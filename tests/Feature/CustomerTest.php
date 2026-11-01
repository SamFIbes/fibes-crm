<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

function customerPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'whatsapp' => '71 123 456',
        'email' => 'rami@example.com',
        'area_id' => Area::query()->where('name_en', 'Sin el Fil')->value('id'),
        'building' => '12',
        'floor' => '3',
        'landmark' => 'Near the pharmacy',
        'latitude' => '33.8800',
        'longitude' => '35.5400',
        'tags' => 'VIP, Fiber',
        'service_type' => 'ftth',
        'plan_name' => 'Home 100',
        'speed' => '100/20',
        'monthly_usd' => '35.50',
        'service_status' => 'active',
        'radius_username' => 'rami.k',
        'radius_password' => 'secret-pass',
        'ont_serial' => 'ONT123',
    ], $overrides);
}

it('creates a customer with a normalized phone, an account number, and a service price', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->post('/customers', customerPayload())->assertRedirect();

    $customer = Customer::query()->firstOrFail();
    expect($customer->account_no)->toBe('FB-000001')
        ->and($customer->phones()->value('phone_e164'))->toBe('+9613123456')
        ->and($customer->whatsapp_e164)->toStartWith('+961');

    $service = $customer->services()->firstOrFail();
    expect((string) $service->monthly_usd)->toBe('35.50')
        ->and($service->radius_password)->toBe('secret-pass')
        ->and($service->getRawOriginal('radius_password'))->not->toContain('secret-pass');

    $this->actingAs($admin)->get('/customers?q=FB-000001')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Customers/Index')
        ->has('customers', 1));

    expect(DB::table('audit_log')->where('event', 'customer.created')->exists())->toBeTrue();
});

it('refuses a duplicate phone and hides the price and the radius password', function () {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'cc_agent']);
    $this->actingAs($admin)->post('/customers', customerPayload())->assertRedirect();

    $this->actingAs($admin)->post('/customers', customerPayload([
        'name_en' => 'Other',
        'phones' => ['03123456'],
    ]))->assertSessionHasErrors('phones');

    $id = Customer::query()->value('id');
    $this->actingAs($agent)->get('/customers/'.$id)->assertOk()->assertInertia(fn ($page) => $page
        ->missing('customer.services.0.monthly_usd')
        ->where('customer.services.0.radius_username', 'rami.k')
        ->missing('customer.services.0.radius_password'));

    $manager = User::factory()->create(['role' => 'sales_manager']);
    $this->actingAs($manager)->get('/customers/'.$id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('customer.services.0.monthly_usd', '35.50')
        ->missing('customer.services.0.radius_username'));

    $this->actingAs($agent)->post('/customers/'.$id.'/services/'.CustomerService::query()->value('id').'/reveal')->assertForbidden();
});

it('limits a sales agent to their own customers and lets noc reveal a password', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $admin = User::factory()->superAdmin()->create();
    $noc = User::factory()->create(['role' => 'noc_engineer']);

    $this->actingAs($admin)->post('/customers', customerPayload())->assertRedirect();
    $foreign = Customer::query()->firstOrFail();

    $this->actingAs($agent)->get('/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
    $this->actingAs($agent)->get('/customers/'.$foreign->id)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/customers')->assertForbidden();

    $service = $foreign->services()->firstOrFail();
    $this->actingAs($noc)->post('/customers/'.$foreign->id.'/services/'.$service->id.'/reveal')
        ->assertOk()
        ->assertJson(['password' => 'secret-pass']);

    expect(DB::table('audit_log')->where('event', 'customer.radius_password.revealed')->exists())->toBeTrue();
});

it('saves a view and patches a status inline', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/customers', customerPayload())->assertRedirect();
    $customer = Customer::query()->firstOrFail();

    $this->actingAs($admin)->post('/customer-views', [
        'name' => 'Active fiber',
        'filters' => ['status' => 'active', 'service_type' => 'ftth'],
    ])->assertRedirect();

    $this->actingAs($admin)->patch('/customers/'.$customer->id, ['status' => 'suspended'])->assertRedirect();
    expect($customer->fresh()->status)->toBe('suspended');
    expect(DB::table('audit_log')->where('event', 'customer.updated')->exists())->toBeTrue();
});
