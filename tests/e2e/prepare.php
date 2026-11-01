<?php

use App\Models\Approval;
use App\Models\Area;
use App\Models\CoverageItem;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Customers\CustomerRecords;
use App\Services\Inventory\StockLedger;
use App\Services\Jobs\FieldWork;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite') {
    fwrite(STDERR, "Refusing to prepare a non-sqlite database.\n");
    exit(1);
}

User::factory()->superAdmin()->create([
    'name' => 'Sam Al-Nakhel',
    'email' => 'shell@fibes.test',
]);

User::factory()->create([
    'name' => 'Hadi Mansour',
    'email' => 'tech@fibes.test',
    'role' => 'field_technician',
]);

$tech = User::query()->where('email', 'tech@fibes.test')->first();
$admin = User::query()->where('email', 'shell@fibes.test')->first();

if ($tech instanceof User && $admin instanceof User) {
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $admin);
    $customer = app(CustomerRecords::class)->create([
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
        'latitude' => '33.8886300',
        'longitude' => '35.4954800',
    ], $admin, $request);
    $start = now()->timezone((string) config('app.timezone'))->setTime(9, 0);
    app(FieldWork::class)->create([
        'type' => 'repair',
        'customer_id' => $customer->id,
        'technician_id' => $tech->id,
        'window_start' => $start->format('Y-m-d H:i'),
        'window_end' => $start->copy()->addHours(2)->format('Y-m-d H:i'),
        'notes' => 'ONT is dark.',
    ], $admin, $request);
}

$ledger = app(StockLedger::class);
$warehouse = StockLocation::query()->where('kind', 'warehouse')->first();
$connectors = StockItem::query()->where('sku', 'connector')->first();
$ont = StockItem::query()->where('sku', 'ont')->first();

if ($admin instanceof User && $warehouse && $connectors && $ont) {
    $ledger->receive($connectors, $warehouse, '80', [], $admin, $request);
    $ledger->receive($ont, $warehouse, '1', [['serial' => 'ONT-1001', 'mac' => 'AA:BB:CC:00:00:01']], $admin, $request);
    if ($tech instanceof User) {
        $van = $ledger->ensureVan($tech);
        $ledger->transfer($connectors, $warehouse, $van, '10', null, $admin, $request);
    }
}

CoverageItem::query()->create([
    'kind' => 'fat',
    'name' => 'Achrafieh cabinet',
    'latitude' => 33.89,
    'longitude' => 35.50,
    'capacity_total' => 8,
    'capacity_used' => 1,
    'status' => 'live',
    'service_type' => 'ftth',
    'source' => 'manual',
]);

$seller = User::factory()->create([
    'name' => 'Maya Saleh',
    'email' => 'sales@fibes.test',
    'role' => 'sales_agent',
]);

foreach ([
    ['sup@fibes.test', 'cc_supervisor', 'Rami Saleh'],
    ['ops@fibes.test', 'ops_manager', 'Hala Ops'],
    ['noc@fibes.test', 'noc_engineer', 'Nadim Noc'],
    ['finance@fibes.test', 'finance', 'Fadi Finance'],
    ['boss@fibes.test', 'management', 'Mona Boss'],
    ['agent@fibes.test', 'cc_agent', 'Nour Agent'],
    ['wh@fibes.test', 'warehouse', 'Waleed Store'],
] as $person) {
    User::factory()->create(['email' => $person[0], 'role' => $person[1], 'name' => $person[2]]);
}
app(ApprovalDesk::class)->open($seller, 'quote_discount', 'request', null, ['percent' => 20], '20% on the home plan', Request::create('/'));

$pipeline = Pipeline::query()->where('key', 'retail')->first();
$stage = $pipeline instanceof Pipeline
    ? PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->first()
    : null;

if ($admin instanceof User && $seller instanceof User && $pipeline instanceof Pipeline && $stage instanceof PipelineStage) {
    $floor = Customer::query()->create([
        'account_no' => 'FB-FLOOR-1',
        'type' => 'individual',
        'status' => 'prospect',
        'name_en' => 'Floor Nour',
        'name_ar' => 'نور',
        'owner_id' => $admin->id,
    ]);
    $deal = Opportunity::query()->create([
        'number' => 'OP-FLOOR-1',
        'customer_id' => $floor->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $admin->id,
        'mrc' => '120.00',
        'currency' => 'USD',
        'service_type' => 'ftth',
    ]);
    Approval::query()->create([
        'type' => 'install_exception',
        'subject_type' => 'opportunity',
        'subject_id' => $deal->id,
        'requester_id' => $seller->id,
        'status' => 'approved',
        'summary' => 'Sign at install',
        'decided_at' => now(),
    ]);
}

echo "ready\n";
