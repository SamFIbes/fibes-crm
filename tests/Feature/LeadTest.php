<?php

use App\Models\Campaign;
use App\Models\CoverageItem;
use App\Models\Customer;
use App\Models\Holiday;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\ReferralCredit;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Leads\LeadClock;
use App\Services\Leads\LeadMachine;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('checks every lead transition', function () {
    $machine = new LeadMachine;

    foreach (LeadMachine::STATUSES as $from) {
        foreach (LeadMachine::STATUSES as $to) {
            if ($from === $to) {
                continue;
            }

            expect($machine->allows($from, $to))->toBe(in_array($to, LeadMachine::EDGES[$from], true));
        }
    }
});

it('stores lebanese numbers in e164 and dedups the same mobile', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->post('/leads', [
        'name' => 'Rami',
        'phone' => '03 123 456',
        'channel' => 'call',
        'latitude' => '33.89',
        'longitude' => '35.50',
        'service_type_interest' => 'ftth',
    ])->assertRedirect();

    $first = Lead::query()->firstOrFail();
    expect($first->phone_e164)->toBe('+9613123456')
        ->and($first->number)->toBe('LD-000001')
        ->and($first->status)->toBe('new')
        ->and($first->owner_id)->toBeNull();

    $this->actingAs($admin)->post('/leads', [
        'name' => 'Rami again',
        'phone' => '+961 3 123456',
        'channel' => 'whatsapp',
    ])->assertSessionHasErrors('phone');
    expect(Lead::query()->count())->toBe(1);

    $this->actingAs($admin)->post('/leads', [
        'name' => 'Rami anyway',
        'phone' => '+961 3 123456',
        'channel' => 'whatsapp',
        'force' => true,
        'force_reason' => 'They asked for a second line.',
    ])->assertRedirect();

    $this->actingAs($admin)->post('/leads', [
        'name' => 'Nour',
        'phone' => '0096170123456',
        'channel' => 'walk_in',
    ])->assertRedirect();

    expect(Lead::query()->where('phone_e164', '+96170123456')->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('event', 'lead.created')->exists())->toBeTrue();
});

it('rejects a web form with a bad signature and stores utm when the signature matches', function () {
    config(['fibes.webhooks.web_form_secret' => 'form-secret']);
    $body = json_encode([
        'name' => 'Rami',
        'phone' => '03 123 456',
        'service' => 'ftth',
        'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'fiber'],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/v1/hooks/web-form', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_SIGNATURE' => 'nope',
    ], $body)->assertUnauthorized();
    expect(Lead::query()->count())->toBe(0);

    $signature = hash_hmac('sha256', $body, 'form-secret');
    $this->call('POST', '/api/v1/hooks/web-form', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_SIGNATURE' => $signature,
        'HTTP_IDEMPOTENCY_KEY' => 'form-1',
    ], $body)->assertCreated();

    $lead = Lead::query()->firstOrFail();
    expect($lead->phone_e164)->toBe('+9613123456')
        ->and($lead->utm['utm_source'])->toBe('google')
        ->and($lead->source)->toBe('web');

    $this->call('POST', '/api/v1/hooks/web-form', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_SIGNATURE' => $signature,
        'HTTP_IDEMPOTENCY_KEY' => 'form-1',
    ], $body)->assertOk();
    expect(Lead::query()->count())->toBe(1);
});

it('rejects a bad meta handshake and imports a csv onto a campaign', function () {
    config([
        'fibes.webhooks.meta_verify_token' => 'meta-token',
        'fibes.webhooks.meta_app_secret' => 'meta-secret',
    ]);
    $admin = User::factory()->superAdmin()->create();

    $this->get('/api/v1/hooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc')
        ->assertUnauthorized();

    $this->get('/api/v1/hooks/meta?hub.mode=subscribe&hub.verify_token=meta-token&hub.challenge=abc')
        ->assertOk()
        ->assertSee('abc');

    $campaign = Campaign::query()->create([
        'name' => 'Fiber May',
        'channel' => 'social_ad',
        'external_id' => 'cmp-9',
        'currency' => 'USD',
    ]);
    $body = json_encode([
        'entry' => [[
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'campaign_id' => 'cmp-9',
                    'field_data' => [
                        ['name' => 'full_name', 'values' => ['Maya']],
                        ['name' => 'phone_number', 'values' => ['+96170111222']],
                    ],
                ],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/v1/hooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=nope',
    ], $body)->assertUnauthorized();
    expect(Lead::query()->count())->toBe(0);

    $signature = hash_hmac('sha256', $body, 'meta-secret');
    $this->call('POST', '/api/v1/hooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.$signature,
    ], $body)->assertOk();

    expect(Lead::query()->where('campaign_id', $campaign->id)->where('phone_e164', '+96170111222')->exists())->toBeTrue();

    $file = UploadedFile::fake()->createWithContent('ads.csv', "name,phone,email\nHadi,03 222 333,hadi@example.com\n");
    $this->actingAs($admin)->post('/leads/import', [
        'file' => $file,
        'campaign_id' => $campaign->id,
    ])->assertRedirect();

    expect(Lead::query()->where('phone_e164', '+9613222333')->where('campaign_id', $campaign->id)->exists())->toBeTrue();
});

it('refuses a second claim and walks the allowed path', function () {
    $admin = User::factory()->superAdmin()->create();
    $first = User::factory()->create(['role' => 'cc_agent', 'name' => 'Maya']);
    $second = User::factory()->create(['role' => 'cc_agent', 'name' => 'Rana']);

    $this->actingAs($admin)->post('/leads', leadPayload())->assertRedirect();
    $lead = Lead::query()->firstOrFail();

    $this->actingAs($first)->post('/leads/'.$lead->id.'/claim')->assertRedirect();
    expect($lead->fresh()->status)->toBe('assigned')
        ->and($lead->fresh()->owner_id)->toBe($first->id);

    $this->actingAs($second)->post('/leads/'.$lead->id.'/claim')->assertSessionHasErrors('lead');
    expect($lead->fresh()->owner_id)->toBe($first->id);

    $this->actingAs($first)->post('/leads/'.$lead->id.'/transition', ['to' => 'qualified'])->assertSessionHasErrors('status');

    $this->actingAs($first)->post('/leads/'.$lead->id.'/transition', ['to' => 'contacted'])->assertRedirect();
    CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Street cabinet',
        'latitude' => 33.89,
        'longitude' => 35.50,
        'capacity_total' => 8,
        'capacity_used' => 0,
        'status' => 'live',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);
    $this->actingAs($first)->post('/coverage/checks', [
        'subject' => 'lead',
        'subject_id' => $lead->id,
        'latitude' => 33.89,
        'longitude' => 35.50,
    ])->assertRedirect();
    $this->actingAs($first)->post('/leads/'.$lead->id.'/transition', ['to' => 'qualified'])->assertRedirect();
    expect($lead->fresh()->status)->toBe('qualified')
        ->and($lead->fresh()->queue)->toBe('sales')
        ->and($lead->fresh()->owner_id)->toBeNull();

    $sales = User::factory()->create(['role' => 'sales_agent']);
    $this->actingAs($sales)->post('/leads/'.$lead->id.'/claim')->assertRedirect();
    $this->actingAs($sales)->post('/leads/'.$lead->id.'/transition', ['to' => 'converted'])->assertRedirect();
    expect($lead->fresh()->status)->toBe('converted')
        ->and($lead->fresh()->converted_customer_id)->not->toBeNull()
        ->and($lead->fresh()->converted_opportunity_id)->not->toBeNull()
        ->and(Customer::query()->count())->toBe(1)
        ->and(Opportunity::query()->count())->toBe(1);
});

it('flags a lead breach once and shows it on the supervisor board', function () {
    Notification::fake();
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/leads', leadPayload())->assertRedirect();
    $lead = Lead::query()->firstOrFail();
    $lead->sla_started_at = now()->subHour();
    $lead->sla_due_at = now()->subMinute();
    $lead->save();

    $this->artisan('leads:sla')->assertSuccessful();
    Notification::assertSentTo($supervisor, WorkspaceNotification::class);
    expect($lead->fresh()->sla_breached_at)->not->toBeNull();

    Notification::fake();
    $this->artisan('leads:sla')->assertSuccessful();
    Notification::assertNothingSent();

    $this->actingAs($supervisor)->get('/calls/board')->assertOk()->assertInertia(fn ($page) => $page
        ->where('lead_breaches', 1)
        ->has('lead_breaches'));
});

it('skips an unconfirmed islamic holiday on the lead clock', function () {
    Holiday::query()->create([
        'observed_on' => '2026-11-03',
        'name_en' => 'Eid sample',
        'name_ar' => 'عيد تجريبي',
        'kind' => 'islamic',
        'confirmed' => false,
    ]);
    $this->travelTo('2026-11-02 12:00:00');

    $due = app(LeadClock::class)->addOpenMinutes(now(), 24 * 60);

    expect($due->timezone((string) config('app.timezone'))->toDateString())->toBe('2026-11-04');
    expect(Holiday::query()->where('kind', 'islamic')->where('confirmed', false)->count())->toBeGreaterThanOrEqual(10);

    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->where('holidays', function ($holidays) {
            return collect($holidays)->contains(fn ($row) => ($row['kind'] ?? null) === 'islamic' && ($row['confirmed'] ?? true) === false);
        }));
});

it('keeps a referral reward pending and reads a map pin', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['71 000 222'],
    ])->assertRedirect();
    $customer = Customer::query()->firstOrFail();

    $this->actingAs($admin)->post('/leads', leadPayload([
        'phone' => '03 555 666',
        'channel' => 'referral',
        'referrer' => $customer->account_no,
    ]))->assertRedirect();

    $credit = ReferralCredit::query()->firstOrFail();
    expect($credit->needs_approval)->toBeTrue()
        ->and($credit->status)->toBe('pending')
        ->and((float) $credit->amount)->toBe(0.0);
    expect(DB::table('audit_log')->where('event', 'referral_credit.pending')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/leads', leadPayload([
        'phone' => '03 555 777',
        'channel' => 'whatsapp',
        'location' => 'https://maps.google.com/?q=33.89,35.50',
    ]))->assertRedirect();

    $pinned = Lead::query()->where('phone_e164', '+9613555777')->firstOrFail();
    expect((float) $pinned->latitude)->toBe(33.89)
        ->and((float) $pinned->longitude)->toBe(35.50);

    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/leads')->assertForbidden();
});

it('asks turnstile when a secret is configured', function () {
    config([
        'fibes.webhooks.web_form_secret' => 'form-secret',
        'fibes.webhooks.turnstile_secret' => 'turnstile-secret',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);
    $body = json_encode(['name' => 'Rami', 'phone' => '03 123 456', 'turnstile_token' => 'bad'], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, 'form-secret');

    $this->call('POST', '/api/v1/hooks/web-form', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_SIGNATURE' => $signature,
    ], $body)->assertStatus(422);
    expect(Lead::query()->count())->toBe(0);
});

function leadPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Rami',
        'phone' => '03 123 456',
        'channel' => 'call',
        'latitude' => '33.89',
        'longitude' => '35.50',
        'service_type_interest' => 'ftth',
    ], $overrides);
}
