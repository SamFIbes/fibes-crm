<?php

use App\Jobs\DialGrandstreamCall;
use App\Jobs\PushKryptonRadiusAction;
use App\Models\AbandonedCall;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\IntegrationAttempt;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsAppEvent;
use App\Models\WorkTask;
use App\Services\Billing\BillingDesk;
use App\Services\Billing\Ledger;
use App\Services\Integrations\HttpGrandstreamClient;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Integrations\KryptonRadiusClient;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Payments\WhishDriver;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('does not call Krypton, Whish, the PBX, or WhatsApp while they are off', function () {
    $admin = User::factory()->superAdmin()->create();
    Http::fake();

    foreach (['krypton', 'whish', 'grandstream', 'whatsapp'] as $key) {
        $this->actingAs($admin)->post('/settings/integrations/'.$key.'/test')->assertRedirect()->assertSessionHasErrors('test');
    }

    app(KryptonRadiusClient::class)->suspend('ada');
    expect(app(WhishDriver::class)->charge('WH-100', '10.00', 'USD')['status'])->toBe('pending');
    Http::assertNothingSent();
    expect(IntegrationAttempt::query()->where('message', 'not_configured')->count())->toBe(4);
});

it('pings Krypton, suspends on the queue, and falls back to a task when the call fails', function () {
    $admin = arm('krypton', [
        'fibes.radius.enabled' => true,
        'fibes.radius.url' => 'https://rad.example',
        'fibes.radius.api_key' => 'radius-key',
    ], ['url' => 'https://rad.example']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-RAD-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Ada',
        'name_ar' => 'آدا',
    ]);

    fakeHttp(['https://rad.example/*' => Http::response(['ok' => true], 200)]);
    $this->actingAs($admin)->post('/settings/integrations/krypton/test')->assertRedirect()->assertSessionHas('status');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/health')
        && $request->hasHeader('Authorization', 'Bearer radius-key'));

    fakeHttp(['https://rad.example/*' => Http::response(['ok' => true], 200)]);
    app(KryptonRadiusClient::class)->suspend('ada', $customer->id, $admin->id);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/users/ada/suspend') && $request->method() === 'POST');
    expect(WorkTask::query()->where('kind', 'noc_krypton')->exists())->toBeFalse()
        ->and(IntegrationAttempt::query()->where('action', 'suspend')->where('status', 'succeeded')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('event', 'integration.sync')->get()))->not->toContain('radius-key');

    fakeHttp(['https://rad.example/*' => Http::response(['error' => 'down'], 500)]);
    app(KryptonRadiusClient::class)->suspend('ada', $customer->id, $admin->id);
    expect(WorkTask::query()->where('customer_id', $customer->id)->where('kind', 'noc_krypton')->count())->toBe(1);

    $job = new PushKryptonRadiusAction('suspend', 'ada', $customer->id, $admin->id);
    expect($job->tries())->toBe(5)->and($job->backoff())->toBe(30);
});

it('upserts a Radius user without recording the password and reads a session', function () {
    $admin = arm('krypton', [
        'fibes.radius.enabled' => true,
        'fibes.radius.url' => 'https://rad.example',
        'fibes.radius.api_key' => 'radius-key',
    ], ['url' => 'https://rad.example']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-RAD-2',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Ada',
        'name_ar' => 'آدا',
        'owner_id' => $admin->id,
    ]);
    fakeHttp(['https://rad.example/*' => Http::response(['ok' => true], 200)]);
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => '8 Mb',
        'status' => 'active',
        'monthly_usd' => '35.00',
        'radius_username' => 'ada.user',
        'radius_password' => 'SecretPass1',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'PUT'
            && str_contains($request->url(), '/api/v1/users/ada.user')
            && ($body['password'] ?? null) === 'SecretPass1'
            && ($body['profile'] ?? null) === '8 Mb';
    });
    expect(json_encode(IntegrationAttempt::query()->get()))->not->toContain('SecretPass1')
        ->and(json_encode(IntegrationAttempt::query()->get()))->not->toContain('radius-key');

    fakeHttp(['https://rad.example/*' => Http::response([
        'online' => true,
        'status' => 'active',
        'framed_ip' => '10.1.1.5',
        'session_started_at' => '2026-11-05T10:00:00Z',
    ], 200)]);
    $this->actingAs($admin)->post('/customers/'.$customer->id.'/services/'.$service->id.'/radius')->assertRedirect();
    $service->refresh();
    expect($service->radius_online)->toBeTrue()
        ->and($service->radius_remote_status)->toBe('active')
        ->and($service->radius_framed_ip)->toBe('10.1.1.5');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/session'));
});

it('creates a Whish checkout and marks the invoice paid once from a signed webhook', function () {
    $admin = arm('whish', [
        'fibes.whish.enabled' => true,
        'fibes.whish.url' => 'https://whish.example',
        'fibes.whish.api_key' => 'whish-key',
        'fibes.whish.webhook_secret' => 'whish-hook',
    ], ['url' => 'https://whish.example']);
    [$order, $customer] = payableCustomer($admin);
    app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();
    $before = LedgerEntry::query()->count();

    fakeHttp(['https://whish.example/*' => Http::response([
        'id' => 'chk_1',
        'url' => 'https://pay.whish.money/chk_1',
        'reference' => 'chk_1',
    ], 200)]);
    $this->actingAs($admin)->post('/invoices/'.$invoice->id.'/whish')->assertRedirect()->assertSessionHas('status');
    $invoice->refresh();
    expect($invoice->whish_checkout_id)->toBe('chk_1')
        ->and($invoice->whish_url)->toBe('https://pay.whish.money/chk_1');
    $payment = Payment::query()->where('whish_checkout_id', 'chk_1')->firstOrFail();
    expect($payment->status)->toBe('pending')
        ->and(json_encode(IntegrationAttempt::query()->get()))->not->toContain('whish-key');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/checkouts')
        && $request->hasHeader('Authorization', 'Bearer whish-key')
        && ($request->data()['amount'] ?? null) === (string) $invoice->total);

    $payload = json_encode([
        'event' => 'payment.succeeded',
        'checkout_id' => 'chk_1',
        'provider_id' => 'tx_1',
        'amount' => (string) $payment->amount,
        'currency' => 'USD',
        'invoice_number' => $invoice->number,
    ], JSON_THROW_ON_ERROR);
    config(['fibes.whish.webhook_secret' => '']);
    $this->call('POST', '/api/v1/hooks/whish', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload)->assertStatus(503);
    config(['fibes.whish.webhook_secret' => 'whish-hook']);

    $headers = fibesSign($payload, 'whish-hook');
    $this->call('POST', '/api/v1/hooks/whish', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => 'nope',
    ], $payload)->assertUnauthorized();

    $this->call('POST', '/api/v1/hooks/whish', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => $headers['signature'],
    ], $payload)->assertOk()->assertJsonPath('applied', true);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($payment->fresh()->status)->toBe('confirmed')
        ->and(LedgerEntry::query()->count())->toBe($before + 1)
        ->and(app(Ledger::class)->balance($customer->id))->toBe('0.00');

    $this->call('POST', '/api/v1/hooks/whish', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => $headers['signature'],
    ], $payload)->assertOk()->assertJsonPath('applied', false);
    expect(LedgerEntry::query()->count())->toBe($before + 1)
        ->and(json_encode(AuditLog::query()->where('event', 'integration.sync')->get()))->not->toContain('whish-key');
});

it('keeps a hand-typed Whish reference when live checkout is off', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    [$order, $customer] = payableCustomer($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));
    config(['fibes.whish.enabled' => true]);
    fakeHttp();

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'whish',
        'reference' => 'WH-100',
    ])->assertRedirect();

    expect(Payment::query()->firstOrFail()->reference)->toBe('WH-100')
        ->and(Payment::query()->firstOrFail()->status)->toBe('pending');
    Http::assertNothingSent();
});

it('pings the UCM with a challenge and dials without recording the cookie', function () {
    $admin = arm('grandstream', [
        'fibes.telephony.secret' => 'pbx-secret',
        'fibes.telephony.url' => 'https://pbx.example:8089',
        'fibes.telephony.user' => 'apiuser',
        'fibes.telephony.password' => 'pbx-password',
    ], ['url' => 'https://pbx.example:8089']);
    $admin->extension = '101';
    $admin->save();

    fakeHttp(['https://pbx.example:8089/*' => Http::response([
        'status' => 0,
        'response' => ['challenge' => 'nonce-1'],
    ], 200)]);
    $this->actingAs($admin)->post('/settings/integrations/grandstream/test')->assertRedirect()->assertSessionHas('status');
    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['request']['action'] ?? null) === 'challenge'
            && ($body['request']['user'] ?? null) === 'apiuser'
            && ! array_key_exists('password', $body['request'] ?? []);
    });

    fakeHttp(function ($request) {
        $action = $request->data()['request']['action'] ?? '';

        return match ($action) {
            'challenge' => Http::response(['status' => 0, 'response' => ['challenge' => 'nonce-1']], 200),
            'login' => Http::response(['status' => 0, 'response' => ['cookie' => 'secret-cookie']], 200),
            'dialOutbound' => Http::response(['status' => 0, 'response' => []], 200),
            'logout' => Http::response(['status' => 0], 200),
            default => Http::response(['status' => -1], 500),
        };
    });
    $this->actingAs($admin)->post('/calls/dial', ['phone' => '03 123 456'])->assertRedirect()->assertSessionHas('status');
    Http::assertSent(fn ($request) => ($request->data()['request']['action'] ?? null) === 'dialOutbound'
        && ($request->data()['request']['extension'] ?? null) === '101'
        && ($request->data()['request']['outbound'] ?? null) === '03123456');
    expect(json_encode(IntegrationAttempt::query()->get()))->not->toContain('secret-cookie')
        ->and(json_encode(IntegrationAttempt::query()->get()))->not->toContain('pbx-password');
    expect((new DialGrandstreamCall($admin->id, '101', '03123456'))->tries())->toBe(5);
    expect(app(HttpGrandstreamClient::class)->dialReady())->toBeTrue();
});

it('stores an abandoned call once and still refuses an unsigned screen-pop', function () {
    $payload = json_encode(['caller' => '03123456', 'extension' => '101', 'call_id' => 'ab-1'], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/telephony/abandoned', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload)->assertStatus(503);

    config(['fibes.telephony.secret' => 'pbx-secret']);
    $headers = fibesSign($payload, 'pbx-secret');
    $this->call('POST', '/api/telephony/abandoned', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => 'nope',
    ], $payload)->assertUnauthorized();

    $this->call('POST', '/api/telephony/abandoned', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => $headers['signature'],
    ], $payload)->assertOk();
    $this->call('POST', '/api/telephony/abandoned', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $headers['timestamp'],
        'HTTP_X_FIBES_SIGNATURE' => $headers['signature'],
    ], $payload)->assertOk();
    expect(AbandonedCall::query()->count())->toBe(1)
        ->and(AbandonedCall::query()->firstOrFail()->caller)->toBe('03123456');
});

it('sends a WhatsApp template only with consent and stores a delivery status without replying', function () {
    $admin = arm('whatsapp', [
        'fibes.whatsapp.enabled' => true,
        'fibes.whatsapp.token' => 'wa-token',
        'fibes.whatsapp.phone_number_id' => '123456789012345',
        'fibes.whatsapp.verify_token' => 'wa-verify',
        'fibes.whatsapp.app_secret' => 'wa-app-secret',
    ], ['phone_number_id' => '123456789012345']);
    [$order, $customer] = payableCustomer($admin);
    $customer->whatsapp_consent = true;
    $customer->whatsapp_e164 = '+9613123456';
    $customer->save();
    app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();

    fakeHttp(['https://graph.facebook.com/*' => Http::response(['id' => '123456789012345'], 200)]);
    $this->actingAs($admin)->post('/settings/integrations/whatsapp/test')->assertRedirect()->assertSessionHas('status');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/123456789012345')
        && $request->hasHeader('Authorization', 'Bearer wa-token')
        && $request->method() === 'GET');

    fakeHttp(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    $this->actingAs($admin)->post('/invoices/'.$invoice->id.'/whatsapp')->assertRedirect()->assertSessionHas('status');
    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/messages')
            && ($body['template']['name'] ?? null) === 'fibes_payment_reminder'
            && ($body['to'] ?? null) === '9613123456'
            && ($body['type'] ?? null) === 'template';
    });
    expect(json_encode(IntegrationAttempt::query()->get()))->not->toContain('wa-token');

    $quiet = Customer::query()->create([
        'account_no' => 'FB-WA-QUIET',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Quiet',
        'name_ar' => 'هادئ',
        'whatsapp_consent' => false,
        'whatsapp_e164' => '+96170000000',
        'owner_id' => $admin->id,
    ]);
    fakeHttp();
    $this->actingAs($admin)->post('/customers/'.$quiet->id.'/whatsapp/renewal')->assertRedirect()->assertSessionHasErrors('whatsapp');
    Http::assertNothingSent();
    expect(app(WhatsAppBusinessClient::class)->ready())->toBeTrue();

    $this->get('/api/v1/hooks/whatsapp?hub.mode=subscribe&hub.verify_token=wa-verify&hub.challenge=98765')
        ->assertOk()
        ->assertSee('98765');

    $body = json_encode([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'statuses' => [[
                        'id' => 'wamid.1',
                        'status' => 'delivered',
                        'recipient_id' => '9613123456',
                        'timestamp' => '1700000000',
                    ]],
                    'messages' => [[
                        'id' => 'wamid.in',
                        'from' => '9613123456',
                        'type' => 'text',
                    ]],
                ],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, 'wa-app-secret');
    fakeHttp();
    $this->call('POST', '/api/v1/hooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.$signature,
    ], $body)->assertOk();
    $this->call('POST', '/api/v1/hooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.$signature,
    ], $body)->assertOk();
    expect(WhatsAppEvent::query()->count())->toBe(2)
        ->and(WhatsAppEvent::query()->where('status', 'inbound')->exists())->toBeTrue();
    Http::assertNothingSent();
});

function fakeHttp(mixed $callback = null): void
{
    Http::swap(new Factory);
    Http::fake($callback);
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $row
 */
function arm(string $key, array $config, array $row = []): User
{
    config($config);
    $admin = User::factory()->superAdmin()->create();
    $payload = integrationsPayload();
    $payload['integrations'][$key] = array_merge($payload['integrations'][$key], ['enabled' => true], $row);
    app(IntegrationSettings::class)->save($payload['integrations'], $admin, Request::create('/'));

    return $admin;
}

/**
 * @return array{timestamp: string, signature: string}
 */
function fibesSign(string $payload, string $secret): array
{
    $timestamp = (string) now()->timestamp;

    return [
        'timestamp' => $timestamp,
        'signature' => hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
    ];
}
