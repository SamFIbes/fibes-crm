<?php

use App\Models\Customer;
use App\Models\Area;
use App\Models\CustomerPhone;
use App\Models\District;
use App\Models\ErpLink;
use App\Models\ErpSyncRun;
use App\Models\Governorate;
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
            $doctype = $request->data()['doctype'] ?? '';
            $counts = ['Customer' => 2, 'Contact' => 5, 'Address' => 4];

            return Http::response(['message' => $counts[$doctype] ?? 0], 200);
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
        ->and($result['customers']['hub_contact_count'])->toBe(5)
        ->and($result['customers']['hub_address_count'])->toBe(4)
        ->and(Customer::query()->count())->toBe(0)
        ->and(ErpLink::query()->count())->toBe(0)
        ->and(ErpSyncRun::query()->where('mode', 'dry_run')->where('status', 'succeeded')->count())->toBe(1);
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
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, '/api/method/frappe.client.get_count')) {
            return Http::response(['message' => 0], 200);
        }

        if (str_contains($url, '/api/resource/Customer')) {
            return Http::response(['exc' => 'denied'], 403);
        }

        return Http::response(['data' => []], 200);
    });

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
            return Http::response(['message' => 12], 200);
        }

        if (str_contains($url, '/api/resource/Customer')) {
            return Http::response([
                'data' => [['name' => 'CUST-SYNTH-SMOKE', 'modified' => '2026-11-05 14:00:00']],
            ], 200);
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

function synthArea(string $nameEn, string $nameAr): Area
{
    $governorate = Governorate::query()->create([
        'name_en' => 'Synth Governorate '.$nameEn,
        'name_ar' => 'Synth Governorate '.$nameAr,
    ]);
    $district = District::query()->create([
        'governorate_id' => $governorate->id,
        'name_en' => 'Synth District '.$nameEn,
        'name_ar' => 'Synth District '.$nameAr,
    ]);

    return Area::query()->create([
        'district_id' => $district->id,
        'name_en' => $nameEn,
        'name_ar' => $nameAr,
    ]);
}

it('maps territory to a unique existing area and stores group and territory on the link', function () {
    enableErpLiveClient();
    config(['fibes.erpnext.crm_apply' => true]);
    $area = synthArea('Beirut Synth', 'بيروت تجريبي');

    fakeCustomerPull('CUST-SYNTH-201', '2026-11-07 10:00:00', [
        'customer_name' => 'Synth Area',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111201',
        'email_id' => 'area@synth.test',
        'disabled' => 0,
        'status' => 'Active',
        'customer_group' => 'Residential',
        'territory' => 'Beirut Synth',
    ]);

    (new ErpNextCrmSync)->liveApply();

    $customer = Customer::query()->where('erp_name', 'CUST-SYNTH-201')->first();
    $link = ErpLink::query()->where('doctype', 'Customer')->where('erp_name', 'CUST-SYNTH-201')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->area_id)->toBe($area->id)
        ->and(Area::query()->count())->toBe(1)
        ->and($link?->meta['customer_group'] ?? null)->toBe('Residential')
        ->and($link?->meta['territory'] ?? null)->toBe('Beirut Synth');
});

it('does not create areas or overwrite area_id when the territory is missing or ambiguous', function () {
    enableErpLiveClient();
    config(['fibes.erpnext.crm_apply' => true]);
    $kept = synthArea('Kept Synth', 'Kept Synth');
    synthArea('Shared Synth', 'Shared A');
    $otherGov = Governorate::query()->create(['name_en' => 'Other Synth Gov', 'name_ar' => 'Other Synth Gov']);
    $otherDistrict = District::query()->create(['governorate_id' => $otherGov->id, 'name_en' => 'Other Synth Dist', 'name_ar' => 'Other Synth Dist']);
    Area::query()->create(['district_id' => $otherDistrict->id, 'name_en' => 'Shared Synth', 'name_ar' => 'Shared B']);

    $existing = Customer::query()->create([
        'account_no' => 'ACC-KEPT',
        'erp_name' => 'CUST-SYNTH-202',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'Kept',
        'name_ar' => 'Kept',
        'area_id' => $kept->id,
        'email' => 'kept@synth.test',
    ]);

    fakeCustomerPull('CUST-SYNTH-202', '2026-11-07 11:00:00', [
        'customer_name' => 'Kept',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111202',
        'email_id' => 'kept@synth.test',
        'disabled' => 0,
        'status' => 'Active',
        'territory' => 'Shared Synth',
    ]);

    (new ErpNextCrmSync)->liveApply();

    expect($existing->fresh()->area_id)->toBe($kept->id)
        ->and(Area::query()->count())->toBe(3);
});

it('never deletes existing phones and does not steal a phone from another customer', function () {
    enableErpLiveClient();
    config(['fibes.erpnext.crm_apply' => true]);

    $owner = Customer::query()->create([
        'account_no' => 'ACC-OWNER',
        'type' => 'residential',
        'status' => 'active',
        'name_en' => 'Owner',
        'name_ar' => 'Owner',
        'email' => 'owner@synth.test',
    ]);
    CustomerPhone::query()->create([
        'customer_id' => $owner->id,
        'phone_e164' => '+96171111900',
        'is_primary' => true,
    ]);

    fakeCustomerPull('CUST-SYNTH-203', '2026-11-07 12:00:00', [
        'customer_name' => 'Synth Phones',
        'customer_type' => 'Individual',
        'mobile_no' => '+96171111203',
        'email_id' => 'phones@synth.test',
        'disabled' => 0,
        'status' => 'Active',
    ], [
        ['name' => 'CONT-SYNTH-203', 'full_name' => 'Extra', 'mobile_no' => '+96171111900', 'email_id' => 'extra@synth.test', 'modified' => '2026-11-07 12:00:00'],
        ['name' => 'CONT-SYNTH-204', 'full_name' => 'Free', 'mobile_no' => '+96171111204', 'modified' => '2026-11-07 12:00:00'],
    ]);

    (new ErpNextCrmSync)->liveApply();

    $imported = Customer::query()->where('erp_name', 'CUST-SYNTH-203')->first();

    expect(Customer::query()->count())->toBe(2)
        ->and(CustomerPhone::query()->where('phone_e164', '+96171111900')->value('customer_id'))->toBe($owner->id)
        ->and(CustomerPhone::query()->where('customer_id', $imported->id)->where('phone_e164', '+96171111203')->exists())->toBeTrue()
        ->and(CustomerPhone::query()->where('customer_id', $imported->id)->where('phone_e164', '+96171111204')->exists())->toBeTrue()
        ->and(CustomerPhone::query()->where('customer_id', $owner->id)->count())->toBe(1);
});

