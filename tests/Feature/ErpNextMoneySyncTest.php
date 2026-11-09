<?php

use App\Models\Customer;
use App\Models\ErpLink;
use App\Models\ErpPaymentEntry;
use App\Models\ErpSalesInvoice;
use App\Models\Setting;
use App\Services\Integrations\ErpNext\ErpNextCrmMapper;
use App\Services\Integrations\ErpNext\ErpNextMoneySync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function enableErpMoneyClient(): void
{
    config([
        'fibes.erpnext.enabled' => true,
        'fibes.erpnext.crm_apply' => false,
        'fibes.erpnext.url' => 'https://erp.thehubholding.com',
        'fibes.erpnext.api_key' => 'crm-key',
        'fibes.erpnext.api_secret' => 'crm-secret',
        'fibes.erpnext.company' => 'Smart Network',
    ]);

    Setting::query()->updateOrCreate(
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

function fakeMoneyPull(array $invoices, array $payments = []): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($invoices, $payments) {
        $url = $request->url();

        if (str_contains($url, '/api/method/frappe.ping')) {
            return Http::response(['message' => 'pong'], 200);
        }

        if (str_contains($url, '/api/method/frappe.client.get_count')) {
            $doctype = (string) ($request->data()['doctype'] ?? '');

            return Http::response([
                'message' => $doctype === 'Sales Invoice' ? count($invoices) : count($payments),
            ], 200);
        }

        if (str_contains($url, '/api/resource/Sales%20Invoice') || str_contains($url, '/api/resource/Sales Invoice')) {
            $start = (int) ($request->data()['limit_start'] ?? 0);

            return Http::response(['data' => $start > 0 ? [] : $invoices], 200);
        }

        if (str_contains($url, '/api/resource/Payment%20Entry') || str_contains($url, '/api/resource/Payment Entry')) {
            $start = (int) ($request->data()['limit_start'] ?? 0);

            return Http::response(['data' => $start > 0 ? [] : $payments], 200);
        }

        return Http::response(['data' => []], 200);
    });
}

it('adds erp money mirror tables', function () {
    expect(Schema::hasTable('erp_sales_invoices'))->toBeTrue()
        ->and(Schema::hasTable('erp_payment_entries'))->toBeTrue();
});

it('maps synthetic sales invoices and payment entries', function () {
    $si = ErpNextCrmMapper::mapSalesInvoice(json_decode(file_get_contents(base_path('tests/fixtures/erpnext/sales_invoices.json')), true)[0]);
    expect($si['erp_name'])->toBe('ACC-SINV-SYNTH-001')
        ->and($si['customer_erp_name'])->toBe('CUST-SYNTH-001')
        ->and($si['total'])->toBe(49.0);

    $pe = ErpNextCrmMapper::mapPaymentEntry(json_decode(file_get_contents(base_path('tests/fixtures/erpnext/payment_entries.json')), true)[0]);
    expect($pe['erp_name'])->toBe('ACC-PAY-SYNTH-001')
        ->and($pe['customer_erp_name'])->toBe('CUST-SYNTH-001')
        ->and($pe['paid_amount'])->toBe(29.0);
});

it('dry-run counts unlinked money docs without writing', function () {
    enableErpMoneyClient();
    fakeMoneyPull([
        [
            'name' => 'ACC-SINV-SYNTH-201',
            'customer' => 'CUST-SYNTH-201',
            'grand_total' => 10,
            'outstanding_amount' => 10,
            'status' => 'Unpaid',
            'docstatus' => 1,
            'currency' => 'USD',
            'modified' => '2026-11-01 10:00:00',
            'posting_date' => '2026-11-01',
            'is_return' => 0,
        ],
    ], [
        [
            'name' => 'ACC-PAY-SYNTH-201',
            'party' => 'CUST-SYNTH-201',
            'party_type' => 'Customer',
            'paid_amount' => 5,
            'received_amount' => 5,
            'status' => 'Submitted',
            'docstatus' => 1,
            'modified' => '2026-11-01 11:00:00',
            'posting_date' => '2026-11-01',
        ],
    ]);

    $result = (new ErpNextMoneySync)->liveDryRun();

    expect($result['wrote'])->toBeFalse()
        ->and($result['sales_invoices']['unlinked'])->toBe(1)
        ->and($result['sales_invoices']['scanned'])->toBe(1)
        ->and($result['sales_invoices']['grand_total_sum'])->toBe(10.0)
        ->and($result['payment_entries']['unlinked'])->toBe(1)
        ->and($result['payment_entries']['paid_amount_sum'])->toBe(5.0)
        ->and(ErpSalesInvoice::query()->count())->toBe(0)
        ->and(ErpPaymentEntry::query()->count())->toBe(0);
});

it('apply upserts mirror rows when the customer is mapped and is idempotent', function () {
    enableErpMoneyClient();
    config(['fibes.erpnext.crm_apply' => true]);

    Customer::query()->create([
        'account_no' => 'CUST-SYNTH-202',
        'erp_name' => 'CUST-SYNTH-202',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'Synth',
        'name_ar' => 'Synth',
    ]);

    $invoice = [
        'name' => 'ACC-SINV-SYNTH-202',
        'customer' => 'CUST-SYNTH-202',
        'grand_total' => 20,
        'outstanding_amount' => 0,
        'status' => 'Paid',
        'docstatus' => 1,
        'currency' => 'USD',
        'modified' => '2026-11-02 10:00:00',
        'posting_date' => '2026-11-02',
        'is_return' => 0,
    ];

    fakeMoneyPull([$invoice], []);
    $first = (new ErpNextMoneySync)->liveApply();
    expect($first['sales_invoices']['would_create'])->toBe(1)
        ->and(ErpSalesInvoice::query()->where('erp_name', 'ACC-SINV-SYNTH-202')->count())->toBe(1)
        ->and(ErpLink::query()->where('doctype', 'Sales Invoice')->where('erp_name', 'ACC-SINV-SYNTH-202')->count())->toBe(1);

    fakeMoneyPull([$invoice], []);
    $second = (new ErpNextMoneySync)->liveApply();
    expect($second['sales_invoices']['unchanged'])->toBe(1)
        ->and(ErpSalesInvoice::query()->count())->toBe(1);
});

it('refuses apply without the guard and supports money smoke counts', function () {
    enableErpMoneyClient();
    config(['fibes.erpnext.crm_apply' => false]);

    expect(fn () => (new ErpNextMoneySync)->liveApply())
        ->toThrow(RuntimeException::class);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, 'frappe.ping')) {
            return Http::response(['message' => 'pong'], 200);
        }

        if (str_contains($url, 'get_count')) {
            $doctype = (string) ($request->data()['doctype'] ?? '');

            return Http::response(['message' => match ($doctype) {
                'Customer' => 3,
                'Sales Invoice' => 12,
                'Payment Entry' => 0,
                default => 0,
            }], 200);
        }

        if (str_contains($url, 'Customer')) {
            return Http::response(['data' => [['name' => 'CUST-X', 'modified' => '2026-11-01 00:00:00']]], 200);
        }

        if (str_contains($url, 'Sales')) {
            return Http::response(['data' => [['name' => 'SINV-X', 'modified' => '2026-11-01 00:00:00']]], 200);
        }

        if (str_contains($url, 'Payment')) {
            return Http::response(['data' => []], 200);
        }

        return Http::response(['data' => []], 200);
    });

    $code = Artisan::call('erpnext:crm-sync', ['--live-smoke' => true]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('invoice_count=12')
        ->and($output)->toContain('payment_count=0')
        ->and($output)->not->toContain('SINV-X');
});
