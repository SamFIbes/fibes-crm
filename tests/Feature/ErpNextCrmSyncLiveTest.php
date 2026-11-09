<?php

use App\Models\Customer;
use App\Models\ErpLink;
use App\Models\Setting;
use App\Services\Integrations\ErpNext\ErpNextCrmSync;
use App\Services\Integrations\ErpNext\ErpNextHttpException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function enableErpLiveClient(): void
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

function fakeCustomerPull(string $name, string $modified, array $doc, array $contacts = [], array $addresses = []): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($name, $modified, $doc, $contacts, $addresses) {
        $url = $request->url();

        if (str_contains($url, '/api/method/frappe.ping')) {
            return Http::response(['message' => 'pong'], 200);
        }

        if (str_contains($url, '/api/method/frappe.client.get_count')) {
            return Http::response(['message' => 2], 200);
        }

        if (str_contains($url, '/api/resource/Contact')) {
            return Http::response(['data' => $contacts], 200);
        }

        if (str_contains($url, '/api/resource/Address')) {
            return Http::response(['data' => $addresses], 200);
        }

        if (preg_match('#/api/resource/Customer($|\\?)#', $url) === 1) {
            $start = (int) ($request->data()['limit_start'] ?? 0);

            if ($start > 0) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([
                'data' => [[
                    'name' => $name,
                    'modified' => $modified,
                    ...$doc,
                ]],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });
}

it('live dry-run counts customers without writing', function () {
    enableErpLiveClient();
    fakeCustomerPull('CUST-SYNTH-101', '2026-11-01 10:00:00', [
        'customer_name' => 'Synth One',
        'customer_type' => 'Company',
        'mobile_no' => '96171111901',
        'email_id' => 'one@synth.test',
        'company_name' => 'Synth Co',
        'disabled' => 0,
        'status' => 'Active',
    ], [
        ['name' => 'CONT-SYNTH-101', 'full_name' => 'Synth Contact', 'mobile_no' => '96171111911', 'modified' => '2026-11-01 10:00:00'],
    ], [
        ['name' => 'ADDR-SYNTH-101', 'address_line1' => 'Line 1', 'city' => 'Beirut', 'modified' => '2026-11-01 10:00:00'],
    ]);

    $result = (new ErpNextCrmSync)->liveDryRun();

    expect($result['wrote'])->toBeFalse()
        ->and($result['customers']['would_create'])->toBe(1)
        ->and($result['customers']['contacts'])->toBe(0)
        ->and($result['customers']['addresses'])->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(ErpLink::query()->count())->toBe(0);
});

it('live apply upserts customer and erp_link and is idempotent', function () {
    enableErpLiveClient();
    config(['fibes.erpnext.crm_apply' => true]);

    fakeCustomerPull('CUST-SYNTH-102', '2026-11-02 11:00:00', [
        'customer_name' => 'Synth Two',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111902',
        'email_id' => 'two@synth.test',
        'disabled' => 0,
        'status' => 'Active',
    ]);

    $first = (new ErpNextCrmSync)->liveApply();
    expect($first['wrote'])->toBeTrue()
        ->and($first['customers']['would_create'])->toBe(1)
        ->and(Customer::query()->where('erp_name', 'CUST-SYNTH-102')->count())->toBe(1)
        ->and(ErpLink::query()->where('doctype', 'Customer')->where('erp_name', 'CUST-SYNTH-102')->count())->toBe(1);

    $cursor = Setting::query()->where('key', 'erpnext_crm_cursors')->first();
    expect(is_array($cursor?->value))->toBeTrue()
        ->and($cursor->value['Customer'] ?? null)->toBe('2026-11-02 11:00:00');

    fakeCustomerPull('CUST-SYNTH-102', '2026-11-02 11:00:00', [
        'customer_name' => 'Synth Two',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111902',
        'email_id' => 'two@synth.test',
        'disabled' => 0,
        'status' => 'Active',
    ]);

    $second = (new ErpNextCrmSync)->liveApply();
    expect($second['customers']['unchanged'])->toBe(1)
        ->and(Customer::query()->where('erp_name', 'CUST-SYNTH-102')->count())->toBe(1);
});

it('live dry-run does not advance the cursor', function () {
    enableErpLiveClient();
    fakeCustomerPull('CUST-SYNTH-103', '2026-11-03 12:00:00', [
        'customer_name' => 'Synth Three',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111903',
        'email_id' => 'three@synth.test',
        'disabled' => 0,
        'status' => 'Active',
    ]);

    (new ErpNextCrmSync)->liveDryRun();

    expect(Setting::query()->where('key', 'erpnext_crm_cursors')->exists())->toBeFalse();
});

it('retries retryable Hub errors then succeeds', function () {
    enableErpLiveClient();
    $calls = 0;

    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
        $url = $request->url();

        if (str_contains($url, '/api/resource/Contact') || str_contains($url, '/api/resource/Address')) {
            return Http::response(['data' => []], 200);
        }

        if (preg_match('#/api/resource/Customer($|\\?)#', $url) === 1) {
            $calls++;
            $start = (int) ($request->data()['limit_start'] ?? 0);

            if ($start > 0) {
                return Http::response(['data' => []], 200);
            }

            if ($calls === 1) {
                return Http::response(['exc' => 'rate'], 429);
            }

            return Http::response([
                'data' => [[
                    'name' => 'CUST-SYNTH-104',
                    'modified' => '2026-11-04 13:00:00',
                    'customer_name' => 'Synth Four',
                    'customer_type' => 'Company',
                    'mobile_no' => '96171111904',
                    'email_id' => 'four@synth.test',
                    'disabled' => 0,
                    'status' => 'Active',
                ]],
            ], 200);
        }

        return Http::response(['data' => []], 200);
    });

    $result = (new ErpNextCrmSync)->liveDryRun();
    expect($result['customers']['would_create'])->toBe(1)->and($calls)->toBeGreaterThan(1);
});

it('does not retry forbidden Hub errors', function () {
    enableErpLiveClient();
    Http::fake([
        'https://erp.thehubholding.com/api/resource/Customer*' => Http::response(['exc' => 'denied'], 403),
    ]);

    expect(fn () => (new ErpNextCrmSync)->liveDryRun())
        ->toThrow(ErpNextHttpException::class);
});

it('refuses apply without the guard flag and supports live-smoke counts only', function () {
    enableErpLiveClient();
    config(['fibes.erpnext.crm_apply' => false]);

    $this->artisan('erpnext:crm-sync', ['--apply' => true])
        ->assertFailed()
        ->expectsOutputToContain('FIBES_ERPNEXT_CRM_APPLY');

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, '/api/method/frappe.ping')) {
            return Http::response(['message' => 'pong'], 200);
        }

        if (str_contains($url, '/api/method/frappe.client.get_count')) {
            $doctype = (string) ($request->data()['doctype'] ?? '');

            return Http::response(['message' => match ($doctype) {
                'Customer' => 12,
                'Sales Invoice' => 7,
                'Payment Entry' => 0,
                default => 0,
            }], 200);
        }

        if (str_contains($url, '/api/resource/Customer')) {
            return Http::response([
                'data' => [['name' => 'CUST-SYNTH-SMOKE', 'modified' => '2026-11-05 14:00:00']],
            ], 200);
        }

        if (str_contains($url, 'Sales')) {
            return Http::response([
                'data' => [['name' => 'SINV-SMOKE', 'modified' => '2026-11-05 14:00:00']],
            ], 200);
        }

        if (str_contains($url, 'Payment')) {
            return Http::response(['data' => []], 200);
        }

        return Http::response(['message' => 'unexpected'], 500);
    });

    $smoke = (new ErpNextCrmSync)->liveSmoke();
    expect($smoke['ok'])->toBeTrue()
        ->and($smoke['customer_count'])->toBe(12)
        ->and($smoke['page_rows'])->toBe(1);

    $code = Artisan::call('erpnext:crm-sync', ['--live-smoke' => true]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('HTTP OK')
        ->and($output)->toContain('customer_count=12')
        ->and($output)->toContain('page_rows=1')
        ->and($output)->toContain('invoice_count=7')
        ->and($output)->toContain('payment_count=0')
        ->and($output)->not->toContain('CUST-SYNTH-SMOKE')
        ->and($output)->not->toContain('@');
});

it('marks email collisions as ambiguous and never auto-merges', function () {
    enableErpLiveClient();

    Customer::query()->create([
        'account_no' => 'ACC-A',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'A',
        'name_ar' => 'A',
        'email' => 'shared@synth.test',
    ]);
    Customer::query()->create([
        'account_no' => 'ACC-B',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'B',
        'name_ar' => 'B',
        'email' => 'shared@synth.test',
    ]);

    fakeCustomerPull('CUST-SYNTH-105', '2026-11-05 15:00:00', [
        'customer_name' => 'Synth Five',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111905',
        'email_id' => 'shared@synth.test',
        'disabled' => 0,
        'status' => 'Active',
    ]);

    $result = (new ErpNextCrmSync)->liveDryRun();
    expect($result['customers']['ambiguous'])->toBe(1)
        ->and($result['customers']['would_create'])->toBe(0);
});
