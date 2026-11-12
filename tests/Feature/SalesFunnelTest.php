<?php

use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Performance\CommissionDesk;
use App\Services\Reports\SalesFunnel;
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

function funnelStage(string $pipeline, string $key): PipelineStage
{
    $id = Pipeline::query()->where('key', $pipeline)->value('id');

    return PipelineStage::query()->where('pipeline_id', $id)->where('key', $key)->firstOrFail();
}

/**
 * @param  list<string>  $history  stage keys the deal passed through before its current stage
 */
function funnelDeal(User $owner, string $current, array $history = [], array $extra = [], string $pipeline = 'retail', string $created = '2026-11-03 10:00:00'): Opportunity
{
    $customer = Customer::query()->firstOrCreate(['account_no' => 'FB-FN-1'], ['type' => 'individual', 'status' => 'active', 'name_en' => 'Synthetic', 'name_ar' => 'تجريبي']);
    $stage = funnelStage($pipeline, $current);
    $deal = Opportunity::query()->create(array_merge([
        'customer_id' => $customer->id, 'pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id, 'owner_id' => $owner->id,
        'mrc' => '10.00', 'currency' => 'USD', 'service_type' => 'ftth',
    ], $extra));
    $deal->created_at = Carbon::parse($created);
    $deal->save();

    foreach ($history as $key) {
        DB::table('opportunity_stage_history')->insert([
            'opportunity_id' => $deal->id, 'stage_id' => funnelStage($pipeline, $key)->id,
            'entered_at' => $created, 'left_at' => $created, 'seconds' => 60, 'created_at' => $created,
        ]);
    }

    return $deal;
}

function funnelWin(User $owner, string $amount, string $at): void
{
    DB::table('deal_events')->insert(['kind' => 'won', 'owner_id' => $owner->id, 'amount' => $amount, 'currency' => 'USD', 'label' => 'Synthetic', 'created_at' => $at]);
}

it('follows the period cohort stage by stage with conversions', function () {
    $admin = User::factory()->superAdmin()->create();
    funnelDeal($admin, 'feasibility');
    funnelDeal($admin, 'site_survey', ['feasibility']);
    funnelDeal($admin, 'proposal', ['feasibility', 'site_survey'], ['mrc' => '30.00']);
    funnelDeal($admin, 'won', ['feasibility', 'site_survey', 'proposal', 'negotiation'], ['won_at' => '2026-11-08 10:00:00']);
    funnelDeal($admin, 'lost', ['feasibility', 'site_survey'], ['lost_at' => '2026-11-07 10:00:00']);
    funnelDeal($admin, 'lost', [], ['lost_at' => '2026-11-07 10:00:00']);
    funnelDeal($admin, 'won', [], [], 'retail', '2026-09-01 10:00:00');

    $funnel = app(SalesFunnel::class)->funnel(null, '2026-11-01', '2026-11-12');
    $stages = collect($funnel['stages'])->keyBy('key');

    expect($funnel['cohort'])->toBe(6)
        ->and($funnel['lost'])->toBe(2)
        ->and($stages->keys()->all())->toBe(['feasibility', 'site_survey', 'proposal', 'negotiation', 'won'])
        ->and($stages['feasibility']['reached'])->toBe(6)
        ->and($stages['site_survey']['reached'])->toBe(4)
        ->and($stages['proposal']['reached'])->toBe(2)
        ->and($stages['negotiation']['reached'])->toBe(1)
        ->and($stages['won']['reached'])->toBe(1)
        ->and($stages['feasibility']['conversion'])->toBeNull()
        ->and($stages['site_survey']['conversion'])->toBe(66.7)
        ->and($stages['proposal']['conversion'])->toBe(50.0)
        ->and($stages['proposal']['now'])->toBe(1)
        ->and($stages['proposal']['now_value'])->toBe('30.00')
        ->and($funnel['win_rate'])->toBe(16.7);
});

it('picks the busiest pipeline unless one is chosen', function () {
    $admin = User::factory()->superAdmin()->create();
    funnelDeal($admin, 'qualified', [], [], 'reseller');
    funnelDeal($admin, 'qualified', [], [], 'reseller');
    funnelDeal($admin, 'feasibility');
    $desk = app(SalesFunnel::class);
    $reseller = Pipeline::query()->where('key', 'reseller')->value('id');
    $retail = Pipeline::query()->where('key', 'retail')->value('id');

    expect($desk->funnel(null, '2026-11-01', '2026-11-12')['pipeline_id'])->toBe($reseller)
        ->and($desk->funnel(null, '2026-11-01', '2026-11-12', $retail)['cohort'])->toBe(1)
        ->and($desk->funnel(null, '2026-11-01', '2026-11-12', 999999)['pipeline_id'])->toBe($reseller)
        ->and($desk->funnel(null, '2026-11-01', '2026-11-12')['pipelines'])->toHaveCount(3);
});

it('ranks owners with the same totals as the performance page', function () {
    $admin = User::factory()->superAdmin()->create();
    $rana = User::factory()->create(['role' => 'sales_agent', 'name' => 'Rana Synthetic']);
    $karim = User::factory()->create(['role' => 'sales_agent', 'name' => 'Karim Synthetic']);
    funnelWin($rana, '40.00', '2026-11-05 10:00:00');
    funnelWin($rana, '20.00', '2026-11-06 10:00:00');
    funnelWin($karim, '90.00', '2026-11-07 10:00:00');
    funnelWin($karim, '500.00', '2026-10-20 10:00:00');
    funnelDeal($rana, 'won', [], ['won_at' => '2026-11-05 10:00:00']);
    funnelDeal($rana, 'lost', [], ['lost_at' => '2026-11-05 10:00:00']);
    funnelDeal($rana, 'lost', [], ['lost_at' => '2026-11-05 10:00:00']);
    funnelDeal($rana, 'proposal', [], ['mrc' => '33.00']);

    $rows = app(SalesFunnel::class)->leaderboard(null, '2026-11-01', '2026-11-30')['rows'];
    $performance = app(CommissionDesk::class)->leaderboard('month');

    expect(array_column($rows, 'user_id'))->toBe([$karim->id, $rana->id])
        ->and(array_map(fn ($row) => [$row['user_id'], $row['total'], $row['deals']], $rows))
        ->toBe(array_map(fn ($row) => [$row['user_id'], $row['total'], $row['deals']], $performance))
        ->and($rows[1])->toMatchArray(['name' => 'Rana Synthetic', 'total' => '60.00', 'deals' => 2, 'lost' => 2, 'win_rate' => 33.3, 'open' => 1, 'open_value' => '33.00'])
        ->and($rows[0]['win_rate'])->toBeNull();
});

it('keeps a sales agent to their own row and funnel', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    funnelWin($agent, '10.00', '2026-11-05 10:00:00');
    funnelWin($other, '80.00', '2026-11-05 10:00:00');
    funnelDeal($agent, 'feasibility');
    funnelDeal($other, 'feasibility');
    funnelDeal($other, 'feasibility');

    $built = app(SalesFunnel::class)->build($agent, '2026-11-01', '2026-11-12');

    expect(array_column($built['leaderboard']['rows'], 'user_id'))->toBe([$agent->id])
        ->and($built['funnel']['cohort'])->toBe(1);
});

it('sends the funnel as a deferred prop that follows the tile rules', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    funnelDeal($admin, 'feasibility');
    funnelDeal($admin, 'qualified', [], [], 'reseller');
    $retail = Pipeline::query()->where('key', 'retail')->value('id');

    $this->actingAs($admin)->get('/dashboard?from=2026-11-01&to=2026-11-12&funnel_pipeline='.$retail)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('funnelAllowed', true)
        ->missing('funnel')
        ->loadDeferredProps('funnel', fn (Assert $reload) => $reload
            ->where('funnel.funnel.pipeline_id', $retail)
            ->where('funnel.funnel.cohort', 1)
            ->has('funnel.leaderboard.rows')));

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('funnelAllowed', false)
        ->loadDeferredProps('funnel', fn (Assert $reload) => $reload->where('funnel', null)));
});

it('shows an error state instead of failing the dashboard', function () {
    $admin = User::factory()->superAdmin()->create();
    $broken = Mockery::mock(SalesFunnel::class);
    $broken->shouldReceive('allows')->andReturn(true);
    $broken->shouldReceive('build')->andThrow(new RuntimeException('synthetic failure'));
    app()->instance(SalesFunnel::class, $broken);

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('funnel', fn (Assert $reload) => $reload->where('funnel.error', true)));
});
