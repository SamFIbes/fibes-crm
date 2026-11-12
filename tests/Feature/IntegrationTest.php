<?php

use App\Jobs\PushErpNextMaterialRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ErpSyncRun;
use App\Models\Expense;
use App\Models\IntegrationAttempt;
use App\Models\NetworkNode;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\User;
use App\Services\Integrations\ErpNext\ErpNextClient;
use App\Services\Integrations\ErpNext\ErpNextMap;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\IntegrationSettings;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

it('hides the integrations desk from everyone except a super admin and never prints a secret', function () {
    config([
        'fibes.erpnext.api_key' => 'live-key',
        'fibes.erpnext.api_secret' => 'live-secret-value',
    ]);
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $finance = User::factory()->create(['role' => 'finance']);

    $this->get('/settings/integrations')->assertRedirect('/login');
    $this->actingAs($warehouse)->get('/settings/integrations')->assertForbidden();
    $this->actingAs($finance)->get('/settings/integrations')->assertForbidden();
    $this->actingAs($warehouse)->put('/settings/integrations', integrationsPayload())->assertForbidden();

    $this->actingAs($admin)->get('/settings/integrations')
        ->assertOk()
        ->assertDontSee('live-secret-value')
        ->assertDontSee('live-key')
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Integrations')
            ->where('cards.0.key', 'erpnext')
            ->where('cards.0.company', 'Smart Network')
            ->where('cards.0.live', false)
            ->where('cards.0.status', 'configured')
            ->where('cards.0.secrets.1.set', true)
            ->missing('cards.0.api_secret'));
});

it('saves non-secret ERP settings and ignores a posted secret', function () {
    $admin = User::factory()->superAdmin()->create();
    $payload = integrationsPayload();
    $payload['integrations']['erpnext']['api_secret'] = 'should-not-stick';
    $payload['integrations']['erpnext']['company'] = 'Smart Network';
    $payload['integrations']['erpnext']['warehouse'] = 'Stores - SN';
    $payload['integrations']['erpnext']['enabled'] = true;

    $this->actingAs($admin)->put('/settings/integrations', $payload)->assertRedirect();

    $stored = Setting::query()->where('key', 'integrations')->firstOrFail()->value;
    expect($stored['erpnext']['company'])->toBe('Smart Network')
        ->and($stored['erpnext']['warehouse'])->toBe('Stores - SN')
        ->and($stored['erpnext']['enabled'])->toBeTrue()
        ->and(json_encode($stored))->not->toContain('should-not-stick')
        ->and($stored['erpnext'])->not->toHaveKey('api_secret');
});

it('tests Hub ERP with token auth and records the attempt', function () {
    $admin = User::factory()->superAdmin()->create();
    Http::fake();
    $this->actingAs($admin)->post('/settings/integrations/erpnext/test')->assertRedirect();
    Http::assertNothingSent();
    expect(IntegrationAttempt::query()->where('integration', 'erpnext')->where('status', 'failed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'integration.sync')->exists())->toBeTrue();

    enableErp();
    $this->actingAs($admin)->put('/settings/integrations', integrationsPayload(true))->assertRedirect();
    Http::swap(new Factory);
    Http::fake([
        'https://erp.thehubholding.com/*' => Http::response(['message' => 'pong'], 200),
    ]);

    $this->actingAs($admin)->post('/settings/integrations/erpnext/test')->assertRedirect();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token live-key:live-secret-value')
        && str_contains($request->url(), '/api/method/frappe.ping'));
    expect(IntegrationAttempt::query()->where('action', 'ping')->where('status', 'succeeded')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'integration.sync')->latest('id')->first()->metadata['message'] ?? null)->not->toContain('live-secret-value');
});

it('does not call Krypton, Whish, the PBX, or WhatsApp when testing those cards', function () {
    $admin = User::factory()->superAdmin()->create();
    Http::fake();

    foreach (['krypton', 'whish', 'grandstream', 'whatsapp'] as $key) {
        $this->actingAs($admin)->post('/settings/integrations/'.$key.'/test')->assertRedirect()->assertSessionHasErrors('test');
    }

    Http::assertNothingSent();
});

it('maps a draft purchase Material Request and queues it only when ERP is on', function () {
    $admin = User::factory()->superAdmin()->create();
    $cable = StockItem::query()->where('sku', 'fiber')->firstOrFail();
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();
    $customer = Customer::query()->create([
        'account_no' => 'FB-ERP-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
    ]);
    $node = NetworkNode::query()->create(['kind' => 'tower', 'name' => 'Achrafieh tower']);

    $this->actingAs($admin)->post('/inventory/erp-request', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '12',
        'customer_id' => $customer->id,
        'network_node_id' => $node->id,
        'purpose' => 'Purchase',
    ])->assertRedirect()->assertSessionHasErrors('erp');
    expect(StockRequest::query()->count())->toBe(0);

    enableErp();
    $this->actingAs($admin)->put('/settings/integrations', integrationsPayload(true));
    Http::fake([
        'https://erp.thehubholding.com/*' => Http::response(['data' => ['name' => 'MAT-MR-2026-00021']], 200),
    ]);

    $this->actingAs($admin)->post('/inventory/erp-request', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '12',
        'customer_id' => $customer->id,
        'network_node_id' => $node->id,
        'purpose' => 'Purchase',
        'expense_title' => 'Tower hardware',
        'expense_amount' => '40',
    ])->assertRedirect();

    $row = StockRequest::query()->firstOrFail();
    expect($row->status)->toBe('submitted')
        ->and($row->erp_name)->toBe('MAT-MR-2026-00021')
        ->and($row->customer_id)->toBe($customer->id)
        ->and($row->network_node_id)->toBe($node->id)
        ->and($row->expense_id)->not->toBeNull()
        ->and($row->remarks)->toContain('Customer '.$customer->id)
        ->and($row->remarks)->toContain('Base station '.$node->id);

    Http::assertSent(function ($request) use ($customer, $node) {
        $body = $request->data();

        return ($body['company'] ?? null) === 'Smart Network'
            && ($body['material_request_type'] ?? null) === 'Purchase'
            && ($body['docstatus'] ?? null) === 0
            && ($body['items'][0]['item_code'] ?? null) === 'fiber'
            && str_contains((string) ($body['items'][0]['description'] ?? ''), 'Customer '.$customer->id)
            && str_contains((string) ($body['items'][0]['description'] ?? ''), 'Base station '.$node->id)
            && ! array_key_exists('custom_fibes_customer_id', $body);
    });

    expect(AuditLog::query()->where('event', 'integration.sync')->where('metadata->action', 'material_request')->exists())->toBeTrue();
    $job = new PushErpNextMaterialRequest($row->id);
    expect($job->tries())->toBe(5)->and($job->backoff())->toBe(30);
});

it('reads ERPNext lists and a named document without sending custom fields', function () {
    enableErp();
    app(IntegrationSettings::class)->save(integrationsPayload(true)['integrations'], User::factory()->superAdmin()->create(), request());
    Http::fake([
        'https://erp.thehubholding.com/*' => Http::response(['data' => []], 200),
    ]);
    $client = app(ErpNextClient::class);

    $client->listItems(20, 40);
    $client->listWarehouses();
    $client->listMaterialRequests();
    $client->listAssets();
    $client->getMaterialRequest('MAT-MR-2026-00001');
    $client->getStockEntry('STE-00001');

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'api/resource/Item')
        && str_contains(urldecode((string) $request->url()), 'item_group'));
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'api/resource/Material Request')
        && str_contains(urldecode($request->url()), 'material_request_type'));

    expect(fn () => $client->getMaterialRequest('bad/name'))->toThrow(RuntimeException::class);
    expect(app(IntegrationRecorder::class)->redact('token live-key:live-secret-value failed'))->not->toContain('live-secret-value');
    expect(ErpNextMap::OBSERVED_STATUSES)->toContain('Pending', 'Ordered', 'Received', 'Cancelled');
});

it('links an issue to a customer, a base station, a job, or an expense', function () {
    $admin = User::factory()->superAdmin()->create();
    $cable = StockItem::query()->where('sku', 'fiber')->firstOrFail();
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();
    $customer = Customer::query()->create([
        'account_no' => 'FB-ERP-2',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour',
        'name_ar' => 'نور',
    ]);
    $node = NetworkNode::query()->create(['kind' => 'tower', 'name' => 'Hamra tower']);
    $this->actingAs($admin)->post('/inventory/receive', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '20',
    ])->assertRedirect();

    $this->actingAs($admin)->post('/inventory/issue', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '3',
        'customer_id' => $customer->id,
        'network_node_id' => $node->id,
        'expense_title' => 'Splices',
        'expense_amount' => '8',
    ])->assertRedirect();

    $movement = StockMovement::query()->where('kind', 'issue')->firstOrFail();
    expect($movement->customer_id)->toBe($customer->id)
        ->and($movement->network_node_id)->toBe($node->id)
        ->and(Expense::query()->where('title', 'Splices')->exists())->toBeTrue()
        ->and($movement->expense_id)->toBe(Expense::query()->where('title', 'Splices')->value('id'));
});

it('accepts a signed ERP webhook and refuses a bad signature', function () {
    $payload = json_encode(['doctype' => 'Material Request', 'name' => 'MAT-MR-2026-00021'], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/hooks/erpnext', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload)->assertStatus(503);

    config(['fibes.erpnext.webhook_secret' => 'erp-hook-secret']);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'erp-hook-secret');
    $this->call('POST', '/api/v1/hooks/erpnext', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $timestamp,
        'HTTP_X_FIBES_SIGNATURE' => 'nope',
    ], $payload)->assertUnauthorized();

    $this->call('POST', '/api/v1/hooks/erpnext', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $timestamp,
        'HTTP_X_FIBES_SIGNATURE' => $signature,
    ], $payload)->assertStatus(202)->assertJsonPath('applied', false);

    expect(IntegrationAttempt::query()->where('action', 'webhook')->where('status', 'succeeded')->exists())->toBeTrue();
});

function enableErp(): void
{
    config([
        'fibes.erpnext.enabled' => true,
        'fibes.erpnext.url' => 'https://erp.thehubholding.com',
        'fibes.erpnext.api_key' => 'live-key',
        'fibes.erpnext.api_secret' => 'live-secret-value',
        'fibes.erpnext.company' => 'Smart Network',
    ]);
}

/**
 * @return array<string, mixed>
 */
function integrationsPayload(bool $erpEnabled = false): array
{
    return [
        'integrations' => [
            'erpnext' => [
                'enabled' => $erpEnabled,
                'url' => 'https://erp.thehubholding.com',
                'company' => 'Smart Network',
                'warehouse' => '',
                'purpose' => 'Purchase',
                'warehouses' => [],
            ],
            'krypton' => ['enabled' => false, 'url' => ''],
            'whish' => ['enabled' => false, 'url' => ''],
            'grandstream' => ['enabled' => false, 'url' => ''],
            'whatsapp' => ['enabled' => false, 'phone_number_id' => ''],
        ],
    ];
}

it('lists customer sync run counts on the integrations desk without customer details', function () {
    $admin = User::factory()->superAdmin()->create();
    ErpSyncRun::query()->create([
        'mode' => 'dry_run',
        'status' => 'succeeded',
        'scanned' => 10,
        'would_create' => 7,
        'would_update' => 2,
        'unchanged' => 1,
        'ambiguous' => 0,
        'errors' => 0,
        'contacts' => 0,
        'addresses' => 0,
        'hub_contact_count' => 4,
        'hub_address_count' => 3,
        'message' => null,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($admin)->get('/settings/integrations')
        ->assertOk()
        ->assertDontSee('shared@synth.test')
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Integrations')
            ->has('erp_sync_runs', 1)
            ->where('erp_sync_runs.0.mode', 'dry_run')
            ->where('erp_sync_runs.0.would_create', 7)
            ->where('erp_sync_runs.0.would_update', 2)
            ->where('erp_sync_runs.0.unchanged', 1)
            ->missing('erp_sync_runs.0.email'));
});

