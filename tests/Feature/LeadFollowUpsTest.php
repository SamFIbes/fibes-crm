<?php

use App\Models\User;
use App\Services\Leads\LeadFollowUps;
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

function followLead(?User $owner, array $extra = []): int
{
    static $n = 0;
    $n++;
    $created = $extra['created_at'] ?? '2026-11-12 09:00:00';

    return DB::table('leads')->insertGetId(array_merge([
        'name' => 'Follow '.$n, 'phone_e164' => '+9617200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'status' => 'assigned',
        'owner_id' => $owner?->id, 'queue' => 'sales', 'created_at' => $created, 'updated_at' => $created,
    ], $extra));
}

/**
 * @return array<string, array<string, mixed>>
 */
function followQueues(User $user): array
{
    return collect(app(LeadFollowUps::class)->for($user)['queues'])->keyBy('key')->all();
}

function followIds(array $queue): array
{
    return array_column($queue['items'], 'id');
}

it('sorts open leads into overdue, due soon and no contact without overlap', function () {
    $admin = User::factory()->superAdmin()->create();
    $late = followLead($admin, ['sla_due_at' => '2026-11-12 10:00:00']);
    $flagged = followLead($admin, ['sla_due_at' => '2026-11-12 18:00:00', 'sla_breached_at' => '2026-11-12 11:00:00']);
    $soon = followLead($admin, ['sla_due_at' => '2026-11-12 14:30:00']);
    $later = followLead($admin, ['sla_due_at' => '2026-11-13 12:00:00']);
    $quiet = followLead($admin, ['created_at' => '2026-10-20 09:00:00', 'status' => 'contacted', 'first_response_at' => '2026-10-20 10:00:00']);
    $quietDue = followLead($admin, ['created_at' => '2026-10-20 09:00:00', 'sla_due_at' => '2026-11-12 13:00:00']);
    followLead($admin, ['sla_due_at' => '2026-11-12 10:00:00', 'status' => 'converted']);
    followLead($admin, ['created_at' => '2026-10-01 09:00:00', 'status' => 'disqualified']);

    $queues = followQueues($admin);

    expect(followIds($queues['leads_overdue']))->toBe([$late, $flagged])
        ->and(followIds($queues['leads_due_soon']))->toBe([$quietDue, $soon])
        ->and(followIds($queues['leads_no_contact']))->toBe([$quiet])
        ->and($queues['leads_overdue']['items'][0])->toMatchArray(['tone' => 'bad', 'href' => '/leads?lead='.$late, 'status' => 'assigned'])
        ->and($queues['leads_no_contact']['items'][0]['when'])->toBe(Carbon::parse('2026-10-20 10:00:00')->toIso8601String())
        ->and($later)->not->toBeIn(array_merge(...array_map('followIds', array_values($queues))));
});

it('treats a recent call, note or status change as contact but not system events', function () {
    $admin = User::factory()->superAdmin()->create();
    $old = ['created_at' => '2026-10-01 09:00:00'];
    $called = followLead($admin, $old);
    $noted = followLead($admin, $old);
    $system = followLead($admin, $old);
    $stale = followLead($admin, $old);
    DB::table('interactions')->insert(['direction' => 'outbound', 'channel' => 'phone', 'lead_id' => $called, 'user_id' => $admin->id, 'created_at' => '2026-11-10 09:00:00', 'updated_at' => '2026-11-10 09:00:00']);
    DB::table('lead_activities')->insert(['lead_id' => $noted, 'user_id' => $admin->id, 'kind' => 'note', 'body' => 'Synthetic', 'created_at' => '2026-11-11 09:00:00']);
    DB::table('lead_activities')->insert(['lead_id' => $system, 'user_id' => null, 'kind' => 'status', 'body' => 'auto', 'created_at' => '2026-11-11 09:00:00']);
    DB::table('lead_activities')->insert(['lead_id' => $stale, 'user_id' => $admin->id, 'kind' => 'note', 'body' => 'Synthetic', 'created_at' => '2026-10-15 09:00:00']);

    $queue = followQueues($admin)['leads_no_contact'];

    expect(followIds($queue))->toBe([$system, $stale])
        ->and($queue['items'][1]['when'])->toBe(Carbon::parse('2026-10-15 09:00:00')->toIso8601String())
        ->and($queue['items'][0]['when'])->toBeNull();
});

it('caps each queue at six items but counts them all', function () {
    $admin = User::factory()->superAdmin()->create();

    foreach (range(1, 9) as $hour) {
        followLead($admin, ['sla_due_at' => '2026-11-12 0'.min($hour, 9).':00:00']);
    }

    $queue = followQueues($admin)['leads_overdue'];

    expect($queue['count'])->toBe(9)
        ->and($queue['items'])->toHaveCount(6)
        ->and(array_keys($queue))->toBe(['key', 'href', 'count', 'items']);
});

it('keeps a sales agent to the leads they can open', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $mine = followLead($agent, ['sla_due_at' => '2026-11-12 10:00:00']);
    $pool = followLead(null, ['sla_due_at' => '2026-11-12 10:30:00', 'status' => 'new']);
    followLead($other, ['sla_due_at' => '2026-11-12 10:00:00']);

    expect(followIds(followQueues($agent)['leads_overdue']))->toBe([$mine, $pool]);
});

it('sends the queues as a deferred prop to people who see the lead clock tile', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    followLead($admin, ['sla_due_at' => '2026-11-12 10:00:00']);

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('followUpsAllowed', true)
        ->missing('followUps')
        ->loadDeferredProps('follow-ups', fn (Assert $reload) => $reload
            ->where('followUps.queues.0.key', 'leads_overdue')
            ->where('followUps.queues.0.count', 1)
            ->where('followUps.due_soon_hours', LeadFollowUps::DUE_SOON_HOURS)
            ->where('followUps.stale_days', LeadFollowUps::STALE_DAYS)));

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('followUpsAllowed', false)
        ->loadDeferredProps('follow-ups', fn (Assert $reload) => $reload->where('followUps', null)));
});

it('shows an error state instead of failing the dashboard', function () {
    $admin = User::factory()->superAdmin()->create();
    $broken = Mockery::mock(LeadFollowUps::class);
    $broken->shouldReceive('allows')->andReturn(true);
    $broken->shouldReceive('for')->andThrow(new RuntimeException('synthetic failure'));
    app()->instance(LeadFollowUps::class, $broken);

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('follow-ups', fn (Assert $reload) => $reload->where('followUps.error', true)));
});
