<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\Reports\ReportExporter;
use App\Support\CsvCell;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('prefixes text that a spreadsheet would run as a formula', function (string $value) {
    expect(CsvCell::safe($value))->toBe("'".$value);
})->with(['=HYPERLINK("http://x.test","click")', '+1+1', '-2+3', '@SUM(A1)', "\tcmd", "\rcmd"]);

it('leaves numbers and ordinary text untouched', function (mixed $value) {
    expect(CsvCell::safe($value))->toBe($value);
})->with([5, 1.5, -3, null, true, '', 'Acme', 'FB-EX-1', '-12.50', '+961']);

it('neutralises formulas in the customer export file', function () {
    config(['fibes.session.idle_minutes' => 10000, 'fibes.session.absolute_minutes' => 10000]);
    Storage::fake('local');
    $agent = User::factory()->create(['role' => 'sales_agent']);
    Customer::query()->create([
        'account_no' => 'FB-CSV-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => '=HYPERLINK("http://evil.test","Open")',
        'name_ar' => 'اختبار',
        'owner_id' => $agent->id,
    ]);

    $this->actingAs($agent)->post('/exports/customers')->assertRedirect();

    $body = Storage::disk('local')->get(DB::table('export_jobs')->value('path'));
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", $body)));

    expect($rows[0])->toBe(['account_no', 'name_en'])
        ->and($rows[1])->toBe(['FB-CSV-1', '\'=HYPERLINK("http://evil.test","Open")']);
});

it('neutralises formulas in report CSV exports', function () {
    $exporter = app(ReportExporter::class);
    $csv = (new ReflectionMethod($exporter, 'csv'))->invoke($exporter, [['label' => '=1+1', 'href' => '/reports']]);

    expect($csv)->toContain("'=1+1,/reports");
});
