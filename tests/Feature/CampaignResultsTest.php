<?php

use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Reports\CampaignResults;
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

function campaignRow(string $name, ?string $budget = null): int
{
    return DB::table('campaigns')->insertGetId([
        'name' => $name, 'channel' => 'campaign', 'starts_on' => '2026-10-01', 'budget' => $budget, 'currency' => 'USD',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function campaignLead(User $owner, ?int $campaign, string $at = '2026-11-05 09:00:00', array $extra = []): int
{
    static $n = 0;
    $n++;

    return DB::table('leads')->insertGetId(array_merge([
        'name' => 'Lead '.$n, 'phone_e164' => '+9617200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'status' => 'new',
        'owner_id' => $owner->id, 'campaign_id' => $campaign, 'created_at' => $at, 'updated_at' => $at,
    ], $extra));
}

function campaignDeal(User $owner, array $extra = []): Opportunity
{
    $customer = Customer::query()->firstOrCreate(['account_no' => 'FB-CR-1'], ['type' => 'individual', 'status' => 'active', 'name_en' => 'Synthetic', 'name_ar' => 'تجريبي']);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'proposal')->firstOrFail();

    return Opportunity::query()->create(array_merge([
        'customer_id' => $customer->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'owner_id' => $owner->id,
        'mrc' => '10.00', 'currency' => 'USD', 'service_type' => 'ftth',
    ], $extra));
}

it('counts leads, opportunities, wins, value and costs per campaign', function () {
    $admin = User::factory()->superAdmin()->create();
    $spring = campaignRow('Spring synthetic', '300.00');
    $flyers = campaignRow('Flyers synthetic');
    campaignRow('Unused synthetic', '50.00');

    $a = campaignLead($admin, $spring);
    $b = campaignLead($admin, $spring);
    campaignLead($admin, $spring);
    campaignLead($admin, $spring, '2026-10-20 09:00:00');
    $c = campaignLead($admin, $flyers);
    campaignLead($admin, null);
    $d = campaignLead($admin, null);

    campaignDeal($admin, ['lead_id' => $a, 'won_at' => '2026-11-08 10:00:00', 'mrc' => '40.00']);
    campaignDeal($admin, ['lead_id' => $a, 'lost_at' => '2026-11-07 10:00:00']);
    $converted = campaignDeal($admin, ['won_at' => '2026-11-09 10:00:00', 'mrc' => '35.00']);
    DB::table('leads')->where('id', $b)->update(['converted_opportunity_id' => $converted->id]);
    campaignDeal($admin, ['lead_id' => $c]);
    campaignDeal($admin, ['lead_id' => $d, 'won_at' => '2026-11-09 10:00:00']);

    $result = app(CampaignResults::class)->build($admin, '2026-11-01', '2026-11-12');

    expect($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0])->toMatchArray([
            'name' => 'Spring synthetic', 'leads' => 3, 'opportunities' => 2, 'won' => 2, 'won_value' => '75.00',
            'lead_to_opportunity' => 66.7, 'lead_to_won' => 66.7, 'budget' => '300.00', 'cost_per_lead' => '100.00', 'cost_per_won' => '150.00',
            'starts_on' => '2026-10-01', 'ends_on' => null,
        ])
        ->and($result['rows'][1])->toMatchArray([
            'name' => 'Flyers synthetic', 'leads' => 1, 'opportunities' => 1, 'won' => 0, 'won_value' => '0.00',
            'lead_to_won' => 0.0, 'cost_per_lead' => null, 'cost_per_won' => null,
        ])
        ->and($result['totals'])->toBe([
            'leads' => 4, 'opportunities' => 3, 'won' => 2, 'won_value' => '75.00', 'lead_to_opportunity' => 75.0, 'lead_to_won' => 50.0,
        ])
        ->and($result['uncampaigned'])->toBe(['leads' => 2, 'lead_to_won' => 50.0])
        ->and($result['manage_href'])->toBe('/leads');
});

it('keeps to the owner scope and hides the manage link without leads.assign', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $campaign = campaignRow('Scoped synthetic');
    campaignLead($agent, $campaign);
    campaignLead($other, $campaign);
    campaignLead($other, $campaign);

    $result = app(CampaignResults::class)->build($agent, '2026-11-01', '2026-11-12');

    expect($result['rows'][0]['leads'])->toBe(1)
        ->and($result['manage_href'])->toBeNull();
});

it('returns an empty result when there are no campaign leads', function () {
    $admin = User::factory()->superAdmin()->create();
    campaignLead($admin, null);

    $result = app(CampaignResults::class)->build($admin, '2026-11-12', '2026-11-01');

    expect($result['rows'])->toBe([])
        ->and($result['from'])->toBe('2026-11-01')
        ->and($result['totals']['lead_to_won'])->toBeNull()
        ->and($result['uncampaigned'])->toBe(['leads' => 1, 'lead_to_won' => 0.0]);
});

it('sends campaign results as a deferred prop only to people who see the campaign tile', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    campaignLead($admin, campaignRow('Deferred synthetic'));

    $this->actingAs($admin)->get('/dashboard?from=2026-11-01&to=2026-11-12')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('campaignsAllowed', true)
        ->missing('campaigns')
        ->loadDeferredProps('campaigns', fn (Assert $reload) => $reload
            ->where('campaigns.rows.0.name', 'Deferred synthetic')
            ->where('campaigns.totals.leads', 1)));

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('campaignsAllowed', false)
        ->loadDeferredProps('campaigns', fn (Assert $reload) => $reload->where('campaigns', null)));
});

it('shows an error state instead of failing the dashboard', function () {
    $admin = User::factory()->superAdmin()->create();
    $broken = Mockery::mock(CampaignResults::class);
    $broken->shouldReceive('allows')->andReturn(true);
    $broken->shouldReceive('build')->andThrow(new RuntimeException('synthetic failure'));
    app()->instance(CampaignResults::class, $broken);

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('campaigns', fn (Assert $reload) => $reload->where('campaigns.error', true)));
});
