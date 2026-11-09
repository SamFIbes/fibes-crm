<?php

use App\Models\Customer;
use App\Models\ErpLink;
use App\Models\Lead;
use App\Services\Integrations\ErpNext\ErpNextClient;
use App\Services\Integrations\ErpNext\ErpNextCrmMapper;
use App\Services\Integrations\ErpNext\ErpNextCrmSync;
use App\Services\Integrations\ErpNext\ErpNextHttpException;
use App\Services\Integrations\IntegrationSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function erpCrmFixtures(): string
{
    return base_path('tests/fixtures/erpnext');
}

function enableErpCrmClient(): void
{
    config([
        'fibes.erpnext.enabled' => true,
        'fibes.erpnext.url' => 'https://erp.thehubholding.com',
        'fibes.erpnext.api_key' => 'crm-key',
        'fibes.erpnext.api_secret' => 'crm-secret',
        'fibes.erpnext.company' => 'Smart Network',
    ]);

    // Desk switch also required by IntegrationSettings::runtime
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => 'integrations'],
        ['value' => [
            'erpnext' => [
                'enabled' => true,
                'url' => 'https://erp.thehubholding.com',
                'company' => 'Smart Network',
                'warehouse' => '',
                'purpose' => 'Purchase',
                'warehouses' => [],
            ],
        ]],
    );
}

it('adds erp_links and customers.erp_name without changing existing customer rows', function () {
    expect(Schema::hasTable('erp_links'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'erp_name'))->toBeTrue();

    $before = Customer::query()->count();
    $customer = Customer::query()->create([
        'account_no' => 'ACC-LOCAL-1',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'Local Only',
        'name_ar' => 'Local Only',
    ]);

    expect($customer->fresh()->erp_name)->toBeNull()
        ->and(Customer::query()->count())->toBe($before + 1)
        ->and(ErpLink::query()->count())->toBe(0);
});

it('rolls the erp_links migration back and forth', function () {
    expect(Schema::hasTable('erp_links'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_11_09_100000_add_erp_links_and_customer_erp_name.php', '--force' => true]);
    expect(Schema::hasTable('erp_links'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'erp_name'))->toBeFalse();

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_09_100000_add_erp_links_and_customer_erp_name.php', '--force' => true]);
    expect(Schema::hasTable('erp_links'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'erp_name'))->toBeTrue();
});

it('maps synthetic Customer Lead Quotation and Sales Invoice payloads', function () {
    $customer = ErpNextCrmMapper::mapCustomer(json_decode(file_get_contents(erpCrmFixtures().'/customers.json'), true)[0]);
    expect($customer['erp_name'])->toBe('CUST-SYNTH-001')
        ->and($customer['account_no'])->toBe('CUST-SYNTH-001')
        ->and($customer['name_en'])->toBe('Acme Fiber Test')
        ->and($customer['type'])->toBe('business')
        ->and($customer['whatsapp_e164'])->toBe('+96171111001')
        ->and($customer['status'])->toBe('active');

    $lead = ErpNextCrmMapper::mapLead(json_decode(file_get_contents(erpCrmFixtures().'/leads.json'), true)[0]);
    expect($lead['external_id'])->toBe('CRM-LEAD-SYNTH-001')
        ->and($lead['source'])->toBe('erpnext_import')
        ->and($lead['status'])->toBe('new')
        ->and($lead['phone_e164'])->toBe('+96171111999')
        ->and($lead['utm']['source'])->toBe('website');

    $quote = ErpNextCrmMapper::mapQuotation(json_decode(file_get_contents(erpCrmFixtures().'/quotations.json'), true)[0]);
    expect($quote['erp_ref'])->toBe('SAL-QTN-SYNTH-001')
        ->and($quote['total'])->toBe(120.0)
        ->and($quote['status'])->toBe('draft');

    $invoice = ErpNextCrmMapper::mapSalesInvoice(json_decode(file_get_contents(erpCrmFixtures().'/sales_invoices.json'), true)[0]);
    expect($invoice['erp_name'])->toBe('ACC-SINV-SYNTH-001')
        ->and($invoice['status'])->toBe('paid')
        ->and($invoice['type'])->toBe('one_time')
        ->and($invoice['customer_erp_name'])->toBe('CUST-SYNTH-001');

    $hash = ErpNextCrmMapper::contentHash($customer);
    expect($hash)->toHaveLength(64)
        ->and(ErpNextCrmMapper::contentHash($customer))->toBe($hash);
});

it('classifies create update and unchanged idempotently without writing domain rows', function () {
    $sync = new ErpNextCrmSync(erpCrmFixtures());
    $first = $sync->dryRun();

    expect($first['wrote'])->toBeFalse()
        ->and($first['customers']['would_create'])->toBe(2)
        ->and($first['leads']['would_create'])->toBe(1)
        ->and($first['quotations']['would_create'])->toBe(1)
        ->and($first['sales_invoices']['would_create'])->toBe(2)
        ->and(Customer::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(ErpLink::query()->count())->toBe(0);

    $mapped = ErpNextCrmMapper::mapCustomer(json_decode(file_get_contents(erpCrmFixtures().'/customers.json'), true)[0]);
    ErpLink::query()->create([
        'doctype' => 'Customer',
        'erp_name' => 'CUST-SYNTH-001',
        'fibes_type' => 'customer',
        'fibes_id' => null,
        'content_hash' => ErpNextCrmMapper::contentHash($mapped),
    ]);

    $second = $sync->dryRun();
    expect($second['wrote'])->toBeFalse()
        ->and($second['customers']['unchanged'])->toBe(1)
        ->and($second['customers']['would_create'])->toBe(1)
        ->and($second['customers']['would_update'])->toBe(0)
        ->and(Customer::query()->count())->toBe(0);

    ErpLink::query()->where('erp_name', 'CUST-SYNTH-001')->update(['content_hash' => 'stale']);
    $third = $sync->dryRun();
    expect($third['customers']['would_update'])->toBe(1)
        ->and($third['customers']['unchanged'])->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('runs the artisan dry-run against fixtures and refuses apply without the guard flag', function () {
    $this->artisan('erpnext:crm-sync', ['--fixture' => erpCrmFixtures()])
        ->assertSuccessful()
        ->expectsOutputToContain('dry run');

    expect(Customer::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(ErpLink::query()->count())->toBe(0);

    $this->artisan('erpnext:crm-sync', ['--fixture' => erpCrmFixtures(), '--apply' => true])
        ->assertFailed()
        ->expectsOutputToContain('FIBES_ERPNEXT_CRM_APPLY');
});

it('raises typed Hub ERP errors for 401 403 429 and 5xx', function () {
    enableErpCrmClient();
    $client = app(ErpNextClient::class);

    foreach ([401, 403, 429, 503] as $status) {
        Http::fake(['https://erp.thehubholding.com/*' => Http::response(['exc' => 'denied'], $status)]);

        try {
            $client->listModified('Customer', ['name']);
            expect(false)->toBeTrue();
        } catch (ErpNextHttpException $exception) {
            expect($exception->status)->toBe($status)
                ->and($exception->isRetryable())->toBe(in_array($status, [429, 503], true));
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
    }
});

it('lists modified documents when Hub answers 200', function () {
    enableErpCrmClient();
    Http::fake([
        'https://erp.thehubholding.com/api/resource/Customer*' => Http::response([
            'data' => [
                ['name' => 'CUST-SYNTH-001', 'modified' => '2026-11-01 10:00:00', 'customer_name' => 'Acme Fiber Test'],
            ],
        ], 200),
    ]);

    $rows = app(ErpNextClient::class)->listModified('Customer', ['customer_name'], '2026-11-01 00:00:00');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('CUST-SYNTH-001');
});
