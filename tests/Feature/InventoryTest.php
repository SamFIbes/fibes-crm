<?php

use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Job;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockSerial;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

it('keeps an append-only stock book and alerts when the warehouse runs low', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $ops = User::factory()->create(['role' => 'ops_manager']);
    $tech = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();
    $van = app(StockLedger::class)->ensureVan($tech);
    $cable = StockItem::query()->where('sku', 'fiber')->firstOrFail();
    $ont = StockItem::query()->where('sku', 'ont')->firstOrFail();

    $this->actingAs($tech)->post('/inventory/receive', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '10',
    ])->assertForbidden();

    $this->actingAs($warehouse)->post('/inventory/receive', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '250',
    ])->assertRedirect();
    $this->actingAs($warehouse)->post('/inventory/receive', [
        'item_id' => $ont->id,
        'location_id' => $main->id,
        'serials' => [['serial' => 'ONT-100', 'mac' => 'AA:BB:CC:DD:EE:FF']],
    ])->assertRedirect();

    expect(app(StockLedger::class)->onHand($cable->fresh(), $main->id))->toBe('250.000')
        ->and(StockSerial::query()->where('serial', 'ONT-100')->value('status'))->toBe('in_stock')
        ->and((string) StockMovement::query()->first()->unit_cost_usd)->toBe('0.40');

    $this->actingAs($warehouse)->post('/inventory/transfer', [
        'item_id' => $cable->id,
        'from_location_id' => $main->id,
        'to_location_id' => $van->id,
        'quantity' => '30',
    ])->assertRedirect();
    $this->actingAs($warehouse)->post('/inventory/transfer', [
        'item_id' => $ont->id,
        'from_location_id' => $main->id,
        'to_location_id' => $van->id,
        'serial' => 'ONT-100',
    ])->assertRedirect();

    $this->actingAs($warehouse)->post('/inventory/return', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '5',
    ])->assertRedirect();
    expect(app(StockLedger::class)->onHand($cable->fresh(), $main->id))->toBe('225.000');

    expect(app(StockLedger::class)->onHand($cable->fresh(), $van->id))->toBe('30.000');
    $this->actingAs($tech)->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page
        ->where('items', fn ($items) => collect($items)->firstWhere('sku', 'fiber')['on_hand'] === '30.000'));

    $movement = StockMovement::query()->firstOrFail();
    expect(fn () => $movement->update(['reason' => 'changed']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('stock_movements')->where('id', $movement->id)->delete())->toThrow(QueryException::class);

    $this->actingAs($warehouse)->post('/inventory/write-off', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '20',
        'reason' => 'Damaged reel',
        'disposition' => 'faulty',
    ])->assertForbidden();

    $this->actingAs($ops)->post('/inventory/write-off', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '40',
        'reason' => 'Damaged reel',
        'disposition' => 'faulty',
    ])->assertRedirect();

    expect($cable->fresh()->low_stock_alerted)->toBeTrue()
        ->and($warehouse->fresh()->notifications()->count())->toBe(1);

    $this->actingAs($ops)->post('/inventory/write-off', [
        'item_id' => $cable->id,
        'location_id' => $main->id,
        'quantity' => '5',
        'reason' => 'Another cut',
        'disposition' => 'faulty',
    ])->assertRedirect();
    expect($warehouse->fresh()->notifications()->count())->toBe(1);
});

it('issues van stock onto a job and shows the serial on the service', function () {
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();
    $van = app(StockLedger::class)->ensureVan($tech);
    $ont = StockItem::query()->where('sku', 'ont')->firstOrFail();
    $this->actingAs($admin)->post('/inventory/receive', [
        'item_id' => $ont->id,
        'location_id' => $main->id,
        'serials' => [['serial' => 'ONT-200', 'mac' => '11:22:33:44:55:66']],
    ])->assertRedirect();
    $this->actingAs($admin)->post('/inventory/transfer', [
        'item_id' => $ont->id,
        'from_location_id' => $main->id,
        'to_location_id' => $van->id,
        'serial' => 'ONT-200',
    ])->assertRedirect();

    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['71 000 111'],
    ])->assertRedirect();
    $customer = Customer::query()->firstOrFail();
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'status' => 'active',
        'monthly_usd' => '25.00',
    ]);
    $job = Job::query()->create([
        'number' => 'JB-900001',
        'type' => 'repair',
        'status' => 'on_site',
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'technician_id' => $tech->id,
        'owner_id' => $admin->id,
    ]);

    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/stock', [
        'item_id' => $ont->id,
        'serial' => 'ONT-200',
        'install' => true,
    ], ['Idempotency-Key' => 'issue-ont-200'])->assertRedirect();
    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/stock', [
        'item_id' => $ont->id,
        'serial' => 'ONT-200',
        'install' => true,
    ], ['Idempotency-Key' => 'issue-ont-200'])->assertRedirect();

    expect(StockSerial::query()->where('serial', 'ONT-200')->value('status'))->toBe('installed')
        ->and(StockSerial::query()->where('serial', 'ONT-200')->value('customer_service_id'))->toBe($service->id)
        ->and(StockMovement::query()->where('kind', 'issue')->count())->toBe(1);

    $this->actingAs($admin)->get('/customers/'.$customer->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('customer.services.0.installed.0.serial', 'ONT-200'));

    $this->actingAs($admin)->post('/inventory/counts', [
        'location_id' => $main->id,
        'lines' => [['item_id' => $ont->id, 'counted_qty' => '0']],
    ])->assertRedirect();
    $this->actingAs($admin)->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page
        ->where('count.lines.0.variance', '0.000'));
});
