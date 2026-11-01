<?php

use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\CustomerService;
use App\Models\Plan;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('checks every approval transition', function () {
    $flow = new ApprovalFlow;

    foreach (ApprovalFlow::EDGES as $from => $next) {
        foreach (array_keys(ApprovalFlow::EDGES) as $to) {
            if ($from === $to) {
                continue;
            }

            expect($flow->allows($from, $to))->toBe(in_array($to, $next, true));
        }
    }
});

it('routes a 20 percent discount from a sales agent through the manager and then management', function () {
    Notification::fake();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $management = User::factory()->create(['role' => 'management']);

    $this->actingAs($agent)->post('/approvals', [
        'type' => 'quote_discount',
        'summary' => '20 percent on the home plan',
        'percent' => 20,
    ])->assertRedirect();

    $approval = Approval::query()->firstOrFail();
    expect($approval->status)->toBe('pending')
        ->and(collect($approval->steps)->pluck('roles')->all())->toBe([['sales_manager'], ['management']]);

    $this->actingAs($manager)->post('/approvals/'.$approval->id.'/approve')->assertSessionHasErrors('comment');
    $this->actingAs($manager)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Within the street price.'])->assertRedirect();
    expect($approval->fresh()->status)->toBe('pending')
        ->and($approval->fresh()->current_step)->toBe(1);

    $this->actingAs($manager)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Again'])->assertSessionHasErrors('approval');
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Agreed.'])->assertRedirect();
    expect($approval->fresh()->status)->toBe('approved');
    expect(DB::table('audit_log')->where('event', 'approval.approved')->exists())->toBeTrue();
});

it('refuses a manager who tries to approve their own request', function () {
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $approval = app(ApprovalDesk::class)->open($manager, 'quote_discount', 'request', 1, ['percent' => 20], 'Own discount', Request::create('/'));

    $this->actingAs($manager)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'I say so'])->assertSessionHasErrors('approval');
    expect($approval->fresh()->status)->toBe('pending');
});

it('keeps an all-of step open until everyone approves and rejects on one no', function () {
    $first = User::factory()->create(['role' => 'management']);
    $second = User::factory()->create(['role' => 'management']);
    $policy = ApprovalPolicy::query()->where('key', 'contract_change')->firstOrFail();
    $policy->steps = [[
        'roles' => ['management'],
        'users' => [$first->id, $second->id],
        'mode' => 'all_of',
        'timeout_hours' => 4,
    ]];
    $policy->save();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $approval = app(ApprovalDesk::class)->open($agent, 'contract_change', 'contract', 9, [], 'Change the term', Request::create('/'));

    $this->actingAs($first)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Fine.'])->assertRedirect();
    expect($approval->fresh()->status)->toBe('pending');
    $this->actingAs($second)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Fine too.'])->assertRedirect();
    expect($approval->fresh()->status)->toBe('approved');

    $other = app(ApprovalDesk::class)->open($agent, 'contract_change', 'contract', 10, [], 'Another change', Request::create('/'));
    $this->actingAs($first)->post('/approvals/'.$other->id.'/reject', ['comment' => ''])->assertSessionHasErrors('comment');
    $this->actingAs($first)->post('/approvals/'.$other->id.'/reject', ['comment' => 'Not this term.'])->assertRedirect();
    expect($other->fresh()->status)->toBe('rejected')
        ->and($other->fresh()->subject_state)->toBe('draft')
        ->and(ApprovalDecision::query()->where('approval_id', $other->id)->value('comment'))->toBe('Not this term.');

    $decision = ApprovalDecision::query()->where('approval_id', $other->id)->firstOrFail();
    expect(fn () => $decision->update(['comment' => 'changed']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('approval_decisions')->where('id', $decision->id)->delete())->toThrow(QueryException::class);
});

it('escalates a timed-out approval once', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    User::factory()->create(['role' => 'sales_manager']);
    User::factory()->create(['role' => 'management']);
    $approval = app(ApprovalDesk::class)->open($agent, 'quote_discount', 'request', 4, ['percent' => 20], 'Late discount', Request::create('/'));
    $approval->due_at = now()->subMinute();
    $approval->save();
    Notification::fake();

    $this->artisan('approvals:sla')->assertSuccessful();
    expect($approval->fresh()->escalated_at)->not->toBeNull();
    Notification::assertSentTimes(WorkspaceNotification::class, 2);

    Notification::fake();
    $this->artisan('approvals:sla')->assertSuccessful();
    Notification::assertNothingSent();
});

it('sends a stock count above the threshold through the inbox and leaves a small difference alone', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = StockLocation::query()->create(['name' => 'Main', 'kind' => 'warehouse']);
    $item = StockItem::query()->create([
        'sku' => 'cable',
        'name_en' => 'Cable',
        'name_ar' => 'كابل',
        'unit' => 'm',
        'serialized' => false,
        'reorder_level' => '0',
        'unit_cost_usd' => '1.00',
    ]);
    $ledger = app(StockLedger::class);
    $ledger->receive($item, $warehouse, '10', [], $admin, Request::create('/'));
    $ledger->count($warehouse, [['item_id' => $item->id, 'counted_qty' => '9']], null, $admin, Request::create('/'));
    expect(Approval::query()->where('type', 'stock_variance')->count())->toBe(0);

    $ledger->count($warehouse, [['item_id' => $item->id, 'counted_qty' => '4']], null, $admin, Request::create('/'));
    expect(Approval::query()->where('type', 'stock_variance')->where('status', 'pending')->count())->toBe(1);
});

it('reprices services only after the migration is approved', function () {
    $admin = User::factory()->superAdmin()->create();
    $management = User::factory()->create(['role' => 'management']);
    $other = User::factory()->superAdmin()->create();
    $plan = Plan::query()->whereHas('product', fn ($query) => $query->where('sku', 'SMN-8-16'))->firstOrFail();
    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Hadi',
        'name_ar' => 'هادي',
        'phones' => ['03 777 111'],
        'service_type' => 'ftth',
        'plan_id' => $plan->id,
        'service_status' => 'active',
    ])->assertRedirect();
    $this->actingAs($admin)->post('/catalog/prices', [
        'product_id' => $plan->product_id,
        'price' => '42.00',
        'valid_from' => now()->toDateString(),
    ])->assertRedirect();
    $this->actingAs($other)->post('/catalog/migrate')->assertSessionHasErrors('migrate');
    expect((string) CustomerService::query()->value('monthly_usd'))->toBe('35.00');

    $approval = Approval::query()->where('type', 'plan_migration')->firstOrFail();
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Go ahead.'])->assertRedirect();
    $this->actingAs($admin)->post('/approvals/'.$approval->fresh()->id.'/approve', ['comment' => 'Published.'])->assertRedirect();
    expect((string) CustomerService::query()->value('monthly_usd'))->toBe('42.00');
    expect(Product::query()->where('sku', 'SMN-8-16')->exists())->toBeTrue();
});

it('hides the inbox from a technician and shows it to a sales manager', function () {
    $this->actingAs(User::factory()->create(['role' => 'field_technician']))->get('/approvals')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'sales_manager']))->get('/approvals')->assertOk();
});
