<?php

use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Services\Reports\SalesCharts;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function salesLead(User $owner, string $at, array $extra = []): int
{
    static $n = 0;
    $n++;

    return DB::table('leads')->insertGetId(array_merge([
        'name' => 'Lead '.$n, 'phone_e164' => '+9617100'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'status' => 'new',
        'owner_id' => $owner->id, 'created_at' => $at, 'updated_at' => $at,
    ], $extra));
}

function salesOpportunity(User $owner, string $created, array $extra = []): Opportunity
{
    static $customer = null;
    $customer = Customer::query()->firstOrCreate(['account_no' => 'FB-SC-1'], ['type' => 'individual', 'status' => 'active', 'name_en' => 'Synthetic', 'name_ar' => 'تجريبي']);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->firstOrFail();
    $opportunity = Opportunity::query()->create(array_merge([
        'customer_id' => $customer->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'owner_id' => $owner->id,
        'mrc' => '10.00', 'currency' => 'USD', 'service_type' => 'ftth',
    ], $extra));
    $opportunity->created_at = Carbon::parse($created);
    $opportunity->save();

    return $opportunity;
}

function salesArea(string $name): int
{
    $governorate = DB::table('governorates')->insertGetId(['name_en' => 'Gov '.$name, 'name_ar' => 'م '.$name, 'created_at' => now(), 'updated_at' => now()]);
    $district = DB::table('districts')->insertGetId(['governorate_id' => $governorate, 'name_en' => 'Dist '.$name, 'name_ar' => 'ق '.$name, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('areas')->insertGetId(['district_id' => $district, 'name_en' => $name, 'name_ar' => 'منطقة '.$name, 'created_at' => now(), 'updated_at' => now()]);
}

it('counts the period kpis with the right filters', function () {
    $admin = User::factory()->superAdmin()->create();
    salesLead($admin, '2026-11-01 09:00:00');
    salesLead($admin, '2026-11-10 09:00:00');
    salesLead($admin, '2026-10-20 09:00:00');
    salesOpportunity($admin, '2026-11-02 10:00:00', ['won_at' => '2026-11-05 10:00:00', 'mrc' => '40.00']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['won_at' => '2026-11-06 10:00:00', 'mrc' => '25.50']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['won_at' => '2026-10-15 10:00:00', 'mrc' => '99.00']);
    salesOpportunity($admin, '2026-11-03 10:00:00', ['lost_at' => '2026-11-04 10:00:00']);
    salesOpportunity($admin, '2026-10-05 10:00:00', ['mrc' => '30.00']);
    salesOpportunity($admin, '2026-10-05 10:00:00', ['mrc' => '12.00', 'won_at' => '2026-11-20 10:00:00']);
    salesOpportunity($admin, '2026-11-20 10:00:00', ['mrc' => '70.00']);

    $kpis = app(SalesCharts::class)->kpis(null, '2026-11-01', '2026-11-12');

    expect($kpis)->toBe([
        'new_leads' => 2,
        'new_opportunities' => 2,
        'won' => 2,
        'won_value' => '65.50',
        'open' => 2,
        'open_value' => '42.00',
    ]);
});

it('puts leads and opportunities into the right weeks for the last quarter', function () {
    $admin = User::factory()->superAdmin()->create();
    salesLead($admin, '2026-11-09 08:00:00');
    salesLead($admin, '2026-11-12 08:00:00');
    salesLead($admin, '2026-11-02 08:00:00');
    salesLead($admin, '2026-06-01 08:00:00');
    salesOpportunity($admin, '2026-11-10 10:00:00', ['won_at' => '2026-11-11 10:00:00']);
    salesOpportunity($admin, '2026-11-03 10:00:00', ['lost_at' => '2026-11-10 10:00:00']);

    $desk = app(SalesCharts::class);
    $leads = $desk->weeklyLeads(null, '2026-11-12');
    $opportunities = $desk->weeklyOpportunities(null, '2026-11-12');

    expect($leads)->toHaveCount(13)
        ->and($leads[0]['week'])->toBe('2026-08-17')
        ->and($leads[12])->toBe(['week' => '2026-11-09', 'count' => 2])
        ->and($leads[11])->toBe(['week' => '2026-11-02', 'count' => 1])
        ->and(array_sum(array_column($leads, 'count')))->toBe(3)
        ->and($opportunities[12])->toBe(['week' => '2026-11-09', 'opened' => 1, 'won' => 1, 'lost' => 1])
        ->and($opportunities[11])->toBe(['week' => '2026-11-02', 'opened' => 1, 'won' => 0, 'lost' => 0]);
});

it('adds up won deals and their value per month', function () {
    $admin = User::factory()->superAdmin()->create();
    salesOpportunity($admin, '2026-09-01 10:00:00', ['won_at' => '2026-11-02 10:00:00', 'mrc' => '40.00']);
    salesOpportunity($admin, '2026-09-01 10:00:00', ['won_at' => '2026-11-03 10:00:00', 'mrc' => '2.25']);
    salesOpportunity($admin, '2026-09-01 10:00:00', ['won_at' => '2026-09-30 23:00:00', 'mrc' => '15.00']);
    salesOpportunity($admin, '2025-09-01 10:00:00', ['won_at' => '2025-10-15 10:00:00', 'mrc' => '500.00']);

    $months = app(SalesCharts::class)->monthlyWon(null, '2026-11-12');

    expect($months)->toHaveCount(12)
        ->and($months[0]['month'])->toBe('2025-12')
        ->and($months[11])->toBe(['month' => '2026-11', 'count' => 2, 'value' => '42.25'])
        ->and($months[9])->toBe(['month' => '2026-09', 'count' => 1, 'value' => '15.00'])
        ->and(array_sum(array_column($months, 'count')))->toBe(3);
});

it('groups new leads by their source channel', function () {
    $admin = User::factory()->superAdmin()->create();
    $web = DB::table('lead_sources')->where('channel', 'web')->value('id')
        ?? DB::table('lead_sources')->insertGetId(['channel' => 'web', 'name_en' => 'Website', 'name_ar' => 'الموقع', 'created_at' => now(), 'updated_at' => now()]);
    $webName = DB::table('lead_sources')->where('id', $web)->value('name_en');
    salesLead($admin, '2026-11-05 09:00:00', ['source_id' => $web, 'source' => 'call_center']);
    salesLead($admin, '2026-11-06 09:00:00', ['source_id' => $web]);
    salesLead($admin, '2026-11-07 09:00:00', ['source' => 'walk_in_test']);
    salesLead($admin, '2026-10-01 09:00:00', ['source' => 'walk_in_test']);

    $sources = app(SalesCharts::class)->leadSources(null, '2026-11-01', '2026-11-12');

    expect($sources)->toBe([
        ['channel' => 'web', 'label' => $webName, 'count' => 2],
        ['channel' => 'walk_in_test', 'label' => 'Walk in test', 'count' => 1],
    ]);
});

it('ranks won value by area and keeps deals with no area', function () {
    $admin = User::factory()->superAdmin()->create();
    $hamra = salesArea('Hamra Test');
    $jounieh = salesArea('Jounieh Test');
    salesOpportunity($admin, '2026-10-01 10:00:00', ['area_id' => $hamra, 'won_at' => '2026-11-02 10:00:00', 'mrc' => '20.00']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['area_id' => $jounieh, 'won_at' => '2026-11-02 10:00:00', 'mrc' => '35.00']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['area_id' => $hamra, 'won_at' => '2026-11-03 10:00:00', 'mrc' => '20.00']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['won_at' => '2026-11-04 10:00:00', 'mrc' => '5.00']);
    salesOpportunity($admin, '2026-10-01 10:00:00', ['area_id' => $jounieh, 'won_at' => '2026-10-04 10:00:00', 'mrc' => '900.00']);

    $areas = app(SalesCharts::class)->wonByArea(null, '2026-11-01', '2026-11-12');

    expect($areas)->toBe([
        ['area_id' => $hamra, 'label' => 'Hamra Test', 'count' => 2, 'value' => '40.00'],
        ['area_id' => $jounieh, 'label' => 'Jounieh Test', 'count' => 1, 'value' => '35.00'],
        ['area_id' => null, 'label' => '', 'count' => 1, 'value' => '5.00'],
    ]);
});

it('keeps a sales agent to their own deals and a manager to their team', function () {
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $outsider = User::factory()->create(['role' => 'sales_agent']);
    $team = Team::query()->create(['key' => 'sales-sc', 'name_en' => 'SC', 'name_ar' => 'س']);
    $manager->teams()->attach($team->id);
    $agent->teams()->attach($team->id);
    salesLead($agent, '2026-11-05 09:00:00');
    salesLead($outsider, '2026-11-05 09:00:00');
    salesOpportunity($agent, '2026-11-05 10:00:00', ['won_at' => '2026-11-06 10:00:00', 'mrc' => '10.00']);
    salesOpportunity($outsider, '2026-11-05 10:00:00', ['won_at' => '2026-11-06 10:00:00', 'mrc' => '80.00']);

    $desk = app(SalesCharts::class);
    $mine = $desk->build($agent, '2026-11-01', '2026-11-12');
    $teamView = $desk->build($manager, '2026-11-01', '2026-11-12');
    $agentScope = app(ReportDesk::class)->ownerIds($agent);

    expect($mine['kpis']['new_leads'])->toBe($agentScope === null ? 2 : 1)
        ->and($teamView['kpis']['won_value'])->toBe('10.00')
        ->and($teamView['kpis']['new_leads'])->toBe(1);
});

it('sends the sales charts as a deferred prop only to people who see sales tiles', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    salesLead($admin, '2026-11-05 09:00:00');

    $this->actingAs($admin)->get('/dashboard?from=2026-11-01&to=2026-11-12')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('salesAllowed', true)
        ->missing('sales')
        ->loadDeferredProps('sales', fn (Assert $reload) => $reload
            ->where('sales.kpis.new_leads', 1)
            ->where('sales.from', '2026-11-01')
            ->has('sales.weekly_leads', 13)
            ->has('sales.monthly_won', 12)));

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('salesAllowed', false)
        ->loadDeferredProps('sales', fn (Assert $reload) => $reload->where('sales', null)));
});

it('shows an error state instead of failing the dashboard', function () {
    $admin = User::factory()->superAdmin()->create();
    $broken = Mockery::mock(SalesCharts::class);
    $broken->shouldReceive('allows')->andReturn(true);
    $broken->shouldReceive('build')->andThrow(new RuntimeException('synthetic failure'));
    app()->instance(SalesCharts::class, $broken);

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('sales', fn (Assert $reload) => $reload->where('sales.error', true)));
});

it('labels the old tiles by what they count and keeps their keys', function () {
    $admin = User::factory()->superAdmin()->create();

    expect(__('ui.reports.key_leads_by_source'))->toBe('New leads')
        ->and(__('ui.reports.key_funnel'))->toBe('New opportunities')
        ->and(__('ui.reports.key_leaderboard'))->toBe('Won opportunities')
        ->and(__('ui.reports.key_campaign_roi'))->toBe('Leads with a campaign')
        ->and(array_keys(ReportDesk::REPORTS))->toContain('leads_by_source', 'funnel', 'leaderboard', 'campaign_roi');

    $this->actingAs($admin)->post('/dashboard/layout', ['keys' => ['leads_by_source', 'leaderboard']])->assertRedirect();
    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tiles.0.key', 'leads_by_source')
        ->where('tiles.1.key', 'leaderboard'));
});
