<?php

use App\Models\CoverageCheck;
use App\Models\CoverageItem;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResult;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Coverage\CoverageDesk;
use App\Services\Coverage\Geo;
use App\Services\Customers\CustomerRecords;
use App\Services\Jobs\FieldWork;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('covers a point inside a building or beside a cabinet with a free port', function () {
    $admin = User::factory()->superAdmin()->create();
    $desk = app(CoverageDesk::class);
    $file = new UploadedFile(base_path('tests/fixtures/coverage/area.geojson'), 'area.geojson', 'application/json', null, true);
    $this->actingAs($admin)->post('/coverage/import', ['file' => $file, 'apply' => true])->assertRedirect();

    $inside = $desk->evaluate(33.890, 35.500);
    expect($inside['result'])->toBe('covered');

    CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Street cabinet',
        'latitude' => 33.8902,
        'longitude' => 35.5002,
        'capacity_total' => 8,
        'capacity_used' => 1,
        'status' => 'live',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);
    $nearCabinet = $desk->evaluate(33.890, 35.500);
    expect($nearCabinet['result'])->toBe('covered')
        ->and($nearCabinet['distance_m'])->toBeLessThan(150);
});

it('never calls a full cabinet covered', function () {
    $desk = app(CoverageDesk::class);
    CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Full cabinet',
        'latitude' => 33.8901,
        'longitude' => 35.5001,
        'capacity_total' => 8,
        'capacity_used' => 8,
        'status' => 'live',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);
    CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Marked full',
        'latitude' => 33.8902,
        'longitude' => 35.5002,
        'capacity_total' => 8,
        'capacity_used' => 1,
        'status' => 'full',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);

    expect($desk->evaluate(33.890, 35.500)['result'])->toBe('near');
});

it('returns near, not covered, or needs a survey', function () {
    $admin = User::factory()->superAdmin()->create();
    $desk = app(CoverageDesk::class);
    CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Far cabinet',
        'latitude' => 33.8927,
        'longitude' => 35.50,
        'capacity_total' => 8,
        'capacity_used' => 0,
        'status' => 'live',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);

    expect($desk->evaluate(33.890, 35.50)['result'])->toBe('near');
    expect($desk->evaluate(34.20, 35.50)['result'])->toBe('not_covered');

    $desk->storeItem([
        'kind' => 'sector',
        'name' => 'North sector',
        'latitude' => 33.70,
        'longitude' => 35.40,
        'azimuth' => 0,
        'beamwidth' => 90,
        'range_m' => 1500,
        'capacity_total' => 20,
        'capacity_used' => 1,
        'status' => 'live',
        'service_type' => 'ptmp',
    ], null, $admin);

    $wireless = $desk->evaluate(33.705, 35.40);
    expect($wireless['result'])->toBe('needs_survey');

    $customer = app(CustomerRecords::class)->create([
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Same building',
        'name_ar' => 'نفس المبنى',
        'phones' => ['71 900 111'],
        'latitude' => '33.705',
        'longitude' => '35.40',
    ], $admin, Request::create('/'));

    expect($customer)->toBeInstanceOf(Customer::class);
    expect($desk->evaluate(33.705, 35.40, $customer->id)['result'])->toBe('covered');
});

it('imports csv, geojson, and kmz, and writes nothing from a bad file', function () {
    $admin = User::factory()->superAdmin()->create();
    $csv = new UploadedFile(base_path('tests/fixtures/coverage/points.csv'), 'points.csv', 'text/csv', null, true);
    $this->actingAs($admin)->post('/coverage/import', ['file' => $csv, 'apply' => false])->assertRedirect()->assertSessionHas('coverage_preview');
    expect(CoverageItem::query()->count())->toBe(0);

    $this->actingAs($admin)->post('/coverage/import', ['file' => $csv, 'apply' => true])->assertRedirect();
    expect(CoverageItem::query()->count())->toBe(2);
    expect(DB::table('audit_log')->where('event', 'coverage.imported')->exists())->toBeTrue();

    CoverageItem::query()->delete();
    $geo = new UploadedFile(base_path('tests/fixtures/coverage/area.geojson'), 'area.geojson', 'application/json', null, true);
    $this->actingAs($admin)->post('/coverage/import', ['file' => $geo, 'apply' => true])->assertRedirect();
    expect(CoverageItem::query()->where('kind', 'building')->count())->toBe(1);

    $kmz = kmzUpload();
    $this->actingAs($admin)->post('/coverage/import', ['file' => $kmz, 'apply' => true])->assertRedirect();
    expect(CoverageItem::query()->where('name', 'Verdun FAT')->exists())->toBeTrue();

    $before = CoverageItem::query()->count();
    $bad = UploadedFile::fake()->createWithContent('bad.geojson', '{');
    $this->actingAs($admin)->post('/coverage/import', ['file' => $bad, 'apply' => true])->assertSessionHasErrors('file');
    expect(CoverageItem::query()->count())->toBe($before);

    $desk = UploadedFile::fake()->createWithContent('net.fiberdesk', (string) file_get_contents(base_path('tests/fixtures/coverage/area.geojson')));
    $this->actingAs($admin)->post('/coverage/import', ['file' => $desk, 'apply' => true])->assertSessionHasErrors('file');
    expect(CoverageItem::query()->count())->toBe($before);

    config(['fibes.coverage.fiberdesk' => true]);
    $this->actingAs($admin)->post('/coverage/import', ['file' => $desk, 'apply' => true])->assertRedirect();
    expect(CoverageItem::query()->count())->toBe($before + 1);
});

it('wakes each waiting lead once when coverage goes live', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/leads', [
        'name' => 'Waiting Rami',
        'phone' => '03 555 010',
        'channel' => 'call',
        'service_type_interest' => 'ftth',
        'latitude' => '33.89',
        'longitude' => '35.50',
    ])->assertRedirect();
    $lead = Lead::query()->firstOrFail();
    $this->actingAs($admin)->post('/coverage/checks', [
        'subject' => 'lead',
        'subject_id' => $lead->id,
        'latitude' => 33.89,
        'longitude' => 35.50,
    ])->assertRedirect();
    expect($lead->fresh()->status)->toBe('waitlisted');
    expect(DB::table('audit_log')->where('event', 'coverage.checked')->exists())->toBeTrue();

    $item = CoverageItem::query()->create([
        'kind' => 'fat',
        'name' => 'Planned cabinet',
        'latitude' => 33.89,
        'longitude' => 35.50,
        'capacity_total' => 8,
        'capacity_used' => 0,
        'status' => 'planned',
        'service_type' => 'ftth',
        'source' => 'manual',
    ]);

    $this->actingAs($admin)->post('/coverage/items/'.$item->id.'/publish')->assertRedirect();
    expect(WorkTask::query()->where('lead_id', $lead->id)->where('kind', 'coverage_ready')->count())->toBe(1)
        ->and($lead->fresh()->status)->toBe('assigned');

    $this->actingAs($admin)->post('/coverage/items/'.$item->id.'/publish')->assertRedirect();
    expect(WorkTask::query()->where('kind', 'coverage_ready')->count())->toBe(1);
    expect(DB::table('audit_log')->where('event', 'coverage.published')->exists())->toBeTrue();
});

it('orders stops by nearest neighbour and breaks ties by id', function () {
    $geo = new Geo;
    $order = $geo->nearestNeighbour([
        ['id' => 2, 'lat' => 33.91, 'lng' => 35.50],
        ['id' => 1, 'lat' => 33.90, 'lng' => 35.50],
    ], 33.89, 35.50);

    expect($order)->toBe([1, 2]);

    $tied = $geo->nearestNeighbour([
        ['id' => 'b', 'lat' => 33.90, 'lng' => 35.50],
        ['id' => 'a', 'lat' => 33.90, 'lng' => 35.50],
    ], 33.89, 35.50);

    expect($tied)->toBe(['a', 'b']);
    expect($geo->contains(33.890, 35.500, [
        [33.889, 35.499],
        [33.889, 35.501],
        [33.891, 35.501],
        [33.891, 35.499],
    ]))->toBeTrue();
});

it('stores a survey and lets a feasible visit unlock the proposal', function () {
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician']);
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $this->actingAs($admin)->post('/leads', [
        'name' => 'Survey Lead',
        'phone' => '71 555 019',
        'channel' => 'call',
        'service_type_interest' => 'ptmp',
        'latitude' => '33.89',
        'longitude' => '35.50',
    ])->assertRedirect();
    $lead = Lead::query()->firstOrFail();
    $lead->status = 'qualified';
    $lead->save();
    $this->actingAs($admin)->post('/leads/'.$lead->id.'/transition', ['to' => 'converted'])->assertRedirect();
    $deal = Opportunity::query()->where('lead_id', $lead->id)->firstOrFail();
    $deal->owner_id = $agent->id;
    $deal->save();

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'site_survey'])->assertRedirect();
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'proposal'])->assertSessionHasErrors('coverage_note');

    $job = app(FieldWork::class)->create([
        'type' => 'survey',
        'customer_id' => $deal->customer_id,
        'technician_id' => $tech->id,
        'opportunity_id' => $deal->id,
    ], $admin, Request::create('/'));
    $repair = app(FieldWork::class)->create([
        'type' => 'repair',
        'customer_id' => $deal->customer_id,
        'technician_id' => $tech->id,
    ], $admin, Request::create('/'));

    $this->actingAs($tech)->post('/tech/jobs/'.$repair->id.'/survey', ['feasible' => true])->assertStatus(422);
    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/survey', [
        'feasible' => false,
        'los' => 'blocked',
        'mounting_point' => 'Roof',
    ])->assertRedirect();
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'proposal'])->assertSessionHasErrors('coverage_note');

    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/survey', [
        'feasible' => true,
        'los' => 'clear',
        'signal_dbm' => -55,
        'cable_m' => 40,
        'mounting_point' => 'Roof mast',
        'riser_ok' => 1,
        'power_ok' => 1,
        'materials' => '40m drop',
        'recommended_service_type' => 'ptmp',
    ])->assertRedirect();

    expect(SurveyResult::query()->where('job_id', $job->id)->value('feasible'))->toBeTrue();
    expect(DB::table('audit_log')->where('event', 'survey.saved')->exists())->toBeTrue();
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'proposal'])->assertRedirect();
    expect($deal->fresh()->stage->key)->toBe('proposal');
});

it('lets sales check coverage and keeps the warehouse out', function () {
    $sales = User::factory()->create(['role' => 'sales_agent']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $auditor = User::factory()->create(['role' => 'auditor']);

    $this->actingAs($sales)->get('/coverage')->assertOk();
    $this->actingAs($auditor)->get('/coverage')->assertOk();
    $this->actingAs($warehouse)->get('/coverage')->assertForbidden();
    $this->actingAs($sales)->post('/coverage/items', [
        'name' => 'Nope',
        'kind' => 'fat',
        'service_type' => 'ftth',
        'latitude' => 33.89,
        'longitude' => 35.50,
    ])->assertForbidden();
    $this->actingAs($warehouse)->post('/coverage/checks', [
        'subject' => 'lead',
        'subject_id' => 1,
        'latitude' => 33.89,
        'longitude' => 35.50,
    ])->assertForbidden();

    expect(CoverageCheck::query()->count())->toBe(0);
});

function kmzUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kmz');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('doc.kml', (string) file_get_contents(base_path('tests/fixtures/coverage/site.kml')));
    $zip->close();

    return new UploadedFile($path, 'site.kmz', 'application/vnd.google-earth.kmz', null, true);
}
