<?php

use App\Models\Approval;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Plan;
use App\Models\PriceChangeRequest;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Catalog\PriceResolver;
use App\Services\Catalog\PromotionEngine;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('picks the price that was valid on the quote date and keeps the old row', function () {
    $admin = User::factory()->superAdmin()->create();
    $product = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $resolver = app(PriceResolver::class);
    $old = PriceListItem::query()->where('product_id', $product->id)->whereNull('customer_type')->firstOrFail();

    expect($resolver->resolve($product->id, '2026-03-01', 'individual')['price'])->toBe('35.00')
        ->and($resolver->resolve($product->id, '2026-03-01', 'business')['price'])->toBe('40.00');

    $this->actingAs($admin)->post('/catalog/prices', [
        'product_id' => $product->id,
        'price' => '36.00',
        'currency' => 'USD',
        'valid_from' => '2026-06-01',
    ])->assertRedirect();

    expect($resolver->resolve($product->id, '2026-03-01', 'individual')['price'])->toBe('35.00')
        ->and($resolver->resolve($product->id, '2026-07-01', 'individual')['price'])->toBe('36.00')
        ->and((string) $old->fresh()->price)->toBe('35.00')
        ->and(PriceListItem::query()->where('product_id', $product->id)->count())->toBe(3);
    expect(fn () => $old->update(['price' => '1.00']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('price_list_items')->where('id', $old->id)->delete())->toThrow(QueryException::class);
    expect(DB::table('audit_log')->where('event', 'catalog.price.added')->exists())->toBeTrue();
});

it('uses only the better of two promotions that cannot be combined', function () {
    Promotion::query()->create([
        'code' => 'TEN',
        'name_en' => 'Ten percent',
        'name_ar' => 'عشرة',
        'type' => 'percent',
        'value' => '10.00',
        'valid_from' => '2026-01-01',
        'stackable' => false,
        'requires_approval' => false,
        'is_active' => true,
    ]);
    Promotion::query()->create([
        'code' => 'FIVE',
        'name_en' => 'Five dollars',
        'name_ar' => 'خمسة',
        'type' => 'fixed',
        'value' => '5.00',
        'valid_from' => '2026-01-01',
        'stackable' => false,
        'requires_approval' => false,
        'is_active' => true,
    ]);

    $result = app(PromotionEngine::class)->evaluate(['TEN', 'FIVE'], '35.00', ['on' => '2026-11-01']);

    expect($result['applied'])->toHaveCount(1)
        ->and($result['applied'][0]['code'])->toBe('FIVE')
        ->and($result['final'])->toBe('30.00')
        ->and($result['saving'])->toBe('5.00');
});

it('refuses an expired promotion code with a clear message', function () {
    $admin = User::factory()->superAdmin()->create();
    Promotion::query()->create([
        'code' => 'OLD',
        'name_en' => 'Old offer',
        'name_ar' => 'عرض قديم',
        'type' => 'percent',
        'value' => '20.00',
        'valid_from' => '2025-01-01',
        'valid_to' => '2025-12-31',
        'stackable' => false,
        'requires_approval' => false,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->post('/catalog/promotions/preview', [
        'code' => 'OLD',
        'amount' => '35.00',
        'on' => '2026-11-01',
    ])->assertSessionHasErrors(['code' => __('ui.catalog.promo_expired')]);
});

it('hides the cost price from sales and lets finance only request a price', function () {
    $sales = User::factory()->create(['role' => 'sales_agent']);
    $finance = User::factory()->create(['role' => 'finance']);
    $admin = User::factory()->superAdmin()->create();
    $product = Product::query()->where('sku', 'ONT-SALE')->firstOrFail();

    $salesJson = $this->actingAs($sales)->getJson('/catalog/products')->assertOk()->json('products');
    expect(collect($salesJson)->pluck('cost_usd')->filter()->all())->toBe([])
        ->and(collect($salesJson)->first() ?? [])->not->toHaveKey('cost_usd');

    $financeJson = $this->actingAs($finance)->getJson('/catalog/products')->assertOk()->json('products');
    expect(collect($financeJson)->firstWhere('sku', 'ONT-SALE')['cost_usd'])->toBe('40.00');

    $this->actingAs($finance)->post('/catalog/prices', [
        'product_id' => $product->id,
        'price' => '90.00',
        'valid_from' => '2026-12-01',
    ])->assertForbidden();
    $this->actingAs($finance)->post('/catalog/price-requests', [
        'product_id' => $product->id,
        'price' => '90.00',
        'valid_from' => '2026-12-01',
        'note' => 'To confirm by Sam',
    ])->assertRedirect();
    $change = PriceChangeRequest::query()->firstOrFail();
    expect($change->status)->toBe('pending');
    $this->actingAs($finance)->post('/catalog/price-requests/'.$change->id.'/apply')->assertForbidden();

    $change->requested_by = $admin->id;
    $change->save();
    $this->actingAs($admin)->post('/catalog/price-requests/'.$change->id.'/apply')->assertSessionHasErrors('price');
    $change->requested_by = $finance->id;
    $change->save();
    $this->actingAs($admin)->post('/catalog/price-requests/'.$change->id.'/apply')->assertRedirect();
    expect(PriceListItem::query()->where('product_id', $product->id)->where('price', '90.00')->exists())->toBeFalse();
    $approval = Approval::query()->where('type', 'price_list')->firstOrFail();
    $management = User::factory()->create(['role' => 'management']);
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Price is fair.'])->assertRedirect();
    $approval->refresh();
    $this->actingAs($admin)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Publish it.'])->assertRedirect();
    expect($change->fresh()->status)->toBe('applied')
        ->and(PriceListItem::query()->where('product_id', $product->id)->where('price', '90.00')->exists())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/catalog')->assertForbidden();
    $this->actingAs($sales)->get('/catalog')->assertOk();
});

it('fills a new service from the catalog and leaves it alone when the list price changes', function () {
    $admin = User::factory()->superAdmin()->create();
    $plan = Plan::query()->whereHas('product', fn ($query) => $query->where('sku', 'SMN-8-16'))->firstOrFail();
    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour Haddad',
        'name_ar' => 'نور حداد',
        'phones' => ['71 555 010'],
        'service_type' => 'ftth',
        'plan_id' => $plan->id,
        'monthly_usd' => '1.00',
        'service_status' => 'active',
    ])->assertRedirect();

    $service = CustomerService::query()->firstOrFail();
    expect($service->plan_name)->toBe('SMN-8MB UP TO 16MB')
        ->and((string) $service->monthly_usd)->toBe('35.00')
        ->and($service->plan_id)->toBe($plan->id);

    $this->actingAs($admin)->post('/catalog/prices', [
        'product_id' => $plan->product_id,
        'price' => '42.00',
        'valid_from' => now()->toDateString(),
    ])->assertRedirect();
    expect((string) $service->fresh()->monthly_usd)->toBe('35.00');

    $this->actingAs($admin)->post('/catalog/migrate')->assertSessionHasErrors('migrate');
    expect((string) $service->fresh()->monthly_usd)->toBe('35.00')
        ->and(Customer::query()->count())->toBe(1)
        ->and(DB::table('audit_log')->where('event', 'catalog.migrate_blocked')->exists())->toBeTrue();
});
