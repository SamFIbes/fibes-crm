<?php

use App\Models\Approval;
use App\Models\CommissionEntry;
use App\Models\CommissionPlan;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\DealEvent;
use App\Models\Opportunity;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Performance\CommissionDesk;
use App\Services\Settings\CommercialSettings;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('pays a percent of the won price, matches the board to sql, and claws it back through the inbox', function () {
    $agent = User::factory()->create(['role' => 'sales_agent', 'name' => 'Nour']);
    $management = User::factory()->create(['role' => 'management']);
    $deal = dealFor($agent, '40.00');
    app(CommissionDesk::class)->onWon($deal);

    $entry = CommissionEntry::query()->where('kind', 'mrc')->firstOrFail();
    expect((string) $entry->amount)->toBe('4.00')
        ->and((string) $entry->currency)->toBe('USD');
    $board = app(CommissionDesk::class)->leaderboard('month');
    $sql = DB::selectOne('select sum(amount) as total, count(*) as deals from deal_events where kind = ? and owner_id = ? and date(created_at) >= ? and date(created_at) <= ?', [
        'won', $agent->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(),
    ]);
    expect($board[0]['total'])->toBe(bcadd((string) $sql->total, '0', 2))
        ->and($board[0]['deals'])->toBe((int) $sql->deals);

    $order = Order::query()->create([
        'customer_id' => $deal->customer_id,
        'opportunity_id' => $deal->id,
        'type' => 'new',
        'status' => 'submitted',
        'currency' => 'USD',
        'exchange_rate' => '89500.0000',
        'total' => '40.00',
        'owner_id' => $agent->id,
        'requested_on' => now()->toDateString(),
    ]);
    $service = CustomerService::query()->create([
        'customer_id' => $deal->customer_id,
        'service_type' => 'ftth',
        'plan_name' => 'Home',
        'status' => 'active',
        'monthly_usd' => '40.00',
        'order_id' => $order->id,
        'activated_at' => now(),
    ]);
    $service->status = 'terminated';
    $service->terminated_at = now();
    $service->save();

    $approval = Approval::query()->where('type', 'commission_clawback')->where('subject_id', $service->id)->firstOrFail();
    expect(CommissionEntry::query()->where('kind', 'clawback')->exists())->toBeFalse();
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Customer left in the first month.'])->assertRedirect();
    $claw = CommissionEntry::query()->where('kind', 'clawback')->firstOrFail();
    expect((string) $claw->amount)->toBe('-4.00')
        ->and((string) $entry->fresh()->amount)->toBe('4.00');
    expect(fn () => $entry->update(['amount' => '0.00']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('commission_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DealEvent::query()->firstOrFail()->delete())->toThrow(RuntimeException::class);
});

it('uses the higher percent after the target and keeps the old plan on older rows', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $admin = User::factory()->superAdmin()->create();
    app(CommissionDesk::class)->setTarget($admin, [
        'period' => 'month',
        'subject_type' => 'user',
        'subject_id' => $agent->id,
        'metric' => 'mrc_added',
        'value' => '30.00',
        'period_start' => now()->toDateString(),
    ], Request::create('/'));
    $deal = dealFor($agent, '40.00');
    app(CommissionDesk::class)->onWon($deal);
    $earned = CommissionEntry::query()->where('kind', 'mrc')->firstOrFail();
    expect((string) $earned->amount)->toBe('6.00')
        ->and($earned->plan_id)->toBe(CommissionPlan::query()->where('is_current', true)->value('id'));

    $figures = app(CommercialSettings::class)->all();
    $figures['commission_mrc_percent'] = '12.00';
    $this->actingAs($admin)->put('/settings/commercial', ['commercial' => $figures])->assertRedirect();
    expect(CommissionPlan::query()->where('is_current', true)->value('version'))->toBe(2)
        ->and($earned->fresh()->plan_id)->not->toBe(CommissionPlan::query()->where('is_current', true)->value('id'));
});

it('lets an agent see only their own commission and keeps the tv token on its own page', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $finance = User::factory()->create(['role' => 'finance']);
    app(CommissionDesk::class)->onWon(dealFor($agent, '35.00'));
    app(CommissionDesk::class)->onWon(dealFor($other, '20.00'));

    $this->actingAs($agent)->get('/performance')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Index')->has('entries', 1));
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/performance')->assertForbidden();
    $this->actingAs($agent)->get('/performance/export')->assertForbidden();
    $this->actingAs($finance)->get('/performance/export')->assertOk();

    $this->actingAs($agent)->post('/performance/adjustments', [
        'user_id' => $agent->id,
        'amount' => '-2.50',
        'reason' => 'Already paid in cash.',
    ])->assertRedirect();
    $approval = Approval::query()->where('type', 'commission_adjustment')->firstOrFail();
    $this->actingAs(User::factory()->create(['role' => 'management']))->post('/approvals/'.$approval->id.'/approve', [
        'comment' => 'Agreed.',
    ])->assertRedirect();
    expect((string) CommissionEntry::query()->where('kind', 'adjustment')->value('amount'))->toBe('-2.50');

    $token = app(CommissionDesk::class)->issueToken($finance, Request::create('/'));
    $this->get('/customers?token='.$token)->assertForbidden();
    $this->get('/billing?token='.$token)->assertForbidden();
    $this->get('/api/v1/hooks/web-form?token='.$token)->assertForbidden();
    $this->get('/api/v1/tv/sales?token='.$token)->assertOk();
    $screen = $this->get('/tv/sales?token='.$token);
    $screen->assertOk();
    expect($screen->headers->get('X-Frame-Options'))->toBeNull();
    $this->get('/tv/sales')->assertForbidden();
    $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
});

it('pays the install amount and a percent of a confirmed payment', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $deal = dealFor($agent, '35.00');
    $order = Order::query()->create([
        'customer_id' => $deal->customer_id,
        'opportunity_id' => $deal->id,
        'type' => 'new',
        'status' => 'activated',
        'currency' => 'USD',
        'exchange_rate' => '89500.0000',
        'total' => '35.00',
        'owner_id' => $agent->id,
        'requested_on' => now()->toDateString(),
    ]);
    app(CommissionDesk::class)->onActivated($order);
    expect((string) CommissionEntry::query()->where('kind', 'install')->value('amount'))->toBe('5.00');

    $plan = CommissionPlan::query()->where('is_current', true)->firstOrFail();
    $rules = $plan->rules;
    $rules['payment_percent'] = '2.00';
    $plan->rules = $rules;
    $plan->save();
    $payment = Payment::query()->create([
        'number' => 'RC-P-1',
        'customer_id' => $deal->customer_id,
        'amount' => '50.00',
        'tender_amount' => '50.00',
        'currency' => 'USD',
        'exchange_rate' => '89500.0000',
        'method' => 'card',
        'status' => 'confirmed',
        'received_by' => $agent->id,
        'received_at' => now(),
        'created_by' => $agent->id,
    ]);
    app(CommissionDesk::class)->onPayment($payment);
    expect((string) CommissionEntry::query()->where('kind', 'payment')->value('amount'))->toBe('1.00');
});

function dealFor(User $agent, string $mrc): Opportunity
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-P-'.$agent->id.'-'.Customer::query()->count(),
        'type' => 'individual',
        'status' => 'prospect',
        'name_en' => 'TV '.$agent->id,
        'name_ar' => 'تلفزيون',
    ]);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->firstOrFail();

    return Opportunity::query()->create([
        'customer_id' => $customer->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $agent->id,
        'mrc' => $mrc,
        'currency' => 'USD',
        'service_type' => 'ftth',
    ]);
}
