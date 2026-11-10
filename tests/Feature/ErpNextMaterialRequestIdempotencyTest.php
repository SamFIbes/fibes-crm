<?php

use App\Jobs\PushErpNextMaterialRequest;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockRequest;
use App\Services\Integrations\ErpNext\ErpNextClient;
use App\Services\Integrations\ErpNext\ErpNextMap;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\IntegrationSettings;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
});

function erpStockRequest(array $attributes = []): StockRequest
{
    $row = StockRequest::query()->create([
        'status' => 'queued',
        'purpose' => 'Purchase',
        'item_id' => StockItem::query()->where('sku', 'fiber')->value('id'),
        'location_id' => StockLocation::query()->where('name', 'Main warehouse')->value('id'),
        'quantity' => '3',
        ...$attributes,
    ]);

    return $row->refresh();
}

function runErpPush(StockRequest $row, ErpNextClient $client, int $attempt = 1): void
{
    $settings = Mockery::mock(IntegrationSettings::class);
    $settings->shouldReceive('runtime')->with('erpnext')->andReturn(['live' => true, 'url' => 'https://erp.example.test']);
    $map = Mockery::mock(ErpNextMap::class);
    $map->shouldReceive('materialRequest')->andReturn(['title' => 'Cable ('.$row->number.')']);
    $map->shouldReceive('remarks')->andReturn('FIBES '.$row->number);
    $recorder = Mockery::mock(IntegrationRecorder::class)->shouldIgnoreMissing();

    $job = new PushErpNextMaterialRequest($row->id);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queued);
    $job->handle($client, $map, $recorder, $settings);
}

it('allows only one queued push per stock request', function () {
    $job = new PushErpNextMaterialRequest(7);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('7');
});

it('does nothing when the request already has an ERP document', function () {
    $row = erpStockRequest(['erp_name' => 'MAT-MR-0001']);
    $client = Mockery::mock(ErpNextClient::class);
    $client->shouldNotReceive('createMaterialRequest');
    $client->shouldNotReceive('findMaterialRequestByNumber');

    runErpPush($row, $client);

    expect($row->refresh()->erp_name)->toBe('MAT-MR-0001');
});

it('does not touch a request that is already submitted or in another state', function () {
    $row = erpStockRequest(['status' => 'draft']);
    $client = Mockery::mock(ErpNextClient::class);
    $client->shouldNotReceive('createMaterialRequest');

    runErpPush($row, $client);

    expect($row->refresh()->status)->toBe('draft');
});

it('creates the document on the first attempt without a lookup', function () {
    $row = erpStockRequest();
    $client = Mockery::mock(ErpNextClient::class);
    $client->shouldNotReceive('findMaterialRequestByNumber');
    $client->shouldReceive('createMaterialRequest')->once()->andReturn(['data' => ['name' => 'MAT-MR-0009']]);

    runErpPush($row, $client);

    expect($row->refresh()->status)->toBe('submitted')
        ->and($row->erp_name)->toBe('MAT-MR-0009');
});

it('reuses the document a lost first attempt already created instead of creating a second one', function () {
    $row = erpStockRequest();
    $client = Mockery::mock(ErpNextClient::class);
    $client->shouldReceive('findMaterialRequestByNumber')->with((string) $row->number)->once()->andReturn('MAT-MR-0042');
    $client->shouldNotReceive('createMaterialRequest');

    runErpPush($row, $client, 2);

    expect($row->refresh()->status)->toBe('submitted')
        ->and($row->erp_name)->toBe('MAT-MR-0042');
});

it('creates the document on a retry when ERPNext has none yet', function () {
    $row = erpStockRequest(['status' => 'failed']);
    $client = Mockery::mock(ErpNextClient::class);
    $client->shouldReceive('findMaterialRequestByNumber')->once()->andReturn(null);
    $client->shouldReceive('createMaterialRequest')->once()->andReturn(['data' => ['name' => 'MAT-MR-0002']]);

    runErpPush($row, $client, 2);

    expect($row->refresh()->erp_name)->toBe('MAT-MR-0002');
});

it('finds an existing document by the request number and ignores near matches', function () {
    config(['fibes.erpnext.api_key' => 'synthetic-key', 'fibes.erpnext.api_secret' => 'synthetic-secret']);
    $settings = Mockery::mock(IntegrationSettings::class);
    $settings->shouldReceive('runtime')->with('erpnext')->andReturn(['live' => true, 'url' => 'https://erp.example.test']);
    Http::fake(['erp.example.test/*' => Http::response(['data' => [
        ['name' => 'MAT-OTHER', 'title' => 'Cable (SR-00010)'],
        ['name' => 'MAT-HIT', 'title' => 'Cable (SR-0001)'],
    ]])]);

    $client = new ErpNextClient($settings);

    expect($client->findMaterialRequestByNumber('SR-0001'))->toBe('MAT-HIT')
        ->and($client->findMaterialRequestByNumber(''))->toBeNull();
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '%(SR-0001)')
        && str_contains(urldecode($request->url()), '"docstatus","!=",2'));
});
