<?php

use App\Models\Approval;
use App\Models\CollectionCase;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Order;
use App\Models\RetentionCase;
use App\Models\RetentionEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Billing\Ledger;
use App\Services\Collections\CollectionsDesk;
use App\Services\Orders\OrderDesk;
use App\Services\Retention\RetentionDesk;
use App\Services\Retention\RetentionMachine;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden retention step', function () {
    $machine = new RetentionMachine;

    foreach (RetentionMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(RetentionMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('holds a termination until retention is recorded and sends the thirty day request to the same queue', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $management = User::factory()->create(['role' => 'management']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-RT-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Lina',
        'name_ar' => 'لينا',
        'owner_id' => $agent->id,
    ]);
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => 'Home',
        'status' => 'active',
        'monthly_usd' => '35.00',
    ]);
    app(OrderDesk::class)->openChange($agent, [
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'termination',
        'notes' => 'Moving.',
    ], Request::create('/'));
    $order = Order::query()->where('type', 'termination')->firstOrFail();
    $case = RetentionCase::query()->where('order_id', $order->id)->firstOrFail();
    expect($case->stage)->toBe('open');

    $approval = $order->approval_id;
    $this->actingAs($manager)->post('/approvals/'.$approval.'/approve', ['comment' => 'OK.'])->assertRedirect();
    $this->actingAs($management)->post('/approvals/'.$approval.'/approve', ['comment' => 'End it.'])->assertRedirect();
    $order->refresh();
    $order->status = 'activated';
    $order->billing_confirmed = true;
    $order->save();
    $this->actingAs($manager)->post('/orders/'.$order->id.'/transition', ['to' => 'completed'])->assertSessionHasErrors('retention');

    $this->actingAs($manager)->post('/retention/'.$case->id.'/decline', ['reason' => 'moved'])->assertRedirect();
    $this->actingAs($manager)->post('/orders/'.$order->id.'/transition', ['to' => 'completed'])->assertRedirect();
    expect($order->fresh()->status)->toBe('completed');

    $late = Subscription::query()->create([
        'customer_id' => $customer->id,
        'price' => '35.00',
        'currency' => 'USD',
        'prepaid' => true,
        'current_period_start' => now()->subDays(70),
        'current_period_end' => now()->subDays(40),
        'next_invoice_at' => now()->subDays(40)->toDateString(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $management->id,
    ]);
    app(CollectionsDesk::class)->run($management);
    expect(RetentionCase::query()->where('subscription_id', $late->id)->exists())->toBeTrue()
        ->and($late->fresh()->status)->not->toBe('terminated');
});

it('sends a free month over the supervisor limit to management and posts a credit when it is accepted', function () {
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $management = User::factory()->create(['role' => 'management']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-RT-2',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Hadi',
        'name_ar' => 'هادي',
    ]);
    Subscription::query()->create([
        'customer_id' => $customer->id,
        'price' => '35.00',
        'currency' => 'USD',
        'prepaid' => true,
        'current_period_end' => now()->addMonth(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $supervisor->id,
    ]);
    $case = app(RetentionDesk::class)->open($customer, $supervisor);

    $this->actingAs($supervisor)->post('/retention/'.$case->id.'/offer', [
        'kind' => 'free_month',
        'months' => 2,
    ])->assertRedirect();
    $approval = Approval::query()->where('type', 'retention_offer')->firstOrFail();
    expect($approval->status)->toBe('pending');
    $this->actingAs($supervisor)->post('/approvals/'.$approval->id.'/approve')->assertSessionHasErrors('approval');
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Two months.'])->assertRedirect();
    expect(app(Ledger::class)->balance($customer->id))->toBe('-70.00')
        ->and($case->fresh()->stage)->toBe('saved');

    $small = app(RetentionDesk::class)->open(Customer::query()->create([
        'account_no' => 'FB-RT-3',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour',
        'name_ar' => 'نور',
    ]), $supervisor);
    Subscription::query()->create([
        'customer_id' => $small->customer_id,
        'price' => '35.00',
        'currency' => 'USD',
        'prepaid' => true,
        'current_period_end' => now()->addMonth(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $supervisor->id,
    ]);
    $this->actingAs($supervisor)->post('/retention/'.$small->id.'/offer', [
        'kind' => 'discount',
        'months' => 1,
        'percent' => '4',
    ])->assertRedirect();
    expect(Subscription::query()->where('customer_id', $small->customer_id)->value('price'))->toBe('33.60')
        ->and(Approval::query()->where('type', 'retention_offer')->count())->toBe(1);

    $agent = User::factory()->create(['role' => 'cc_agent']);
    $over = app(RetentionDesk::class)->open(Customer::query()->create([
        'account_no' => 'FB-RT-4',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami',
        'name_ar' => 'رامي',
    ]), $agent);
    Subscription::query()->create([
        'customer_id' => $over->customer_id,
        'price' => '35.00',
        'currency' => 'USD',
        'prepaid' => true,
        'current_period_end' => now()->addMonth(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $agent->id,
    ]);
    $this->actingAs($agent)->post('/retention/'.$over->id.'/offer', [
        'kind' => 'discount',
        'months' => 1,
        'percent' => '10',
    ])->assertRedirect();
    $second = Approval::query()->where('type', 'retention_offer')->where('status', 'pending')->firstOrFail();
    expect(collect($second->steps)->pluck('roles')->flatten()->all())->toContain('management');
});

it('matches the save rate and creates one win-back task and one at-risk task', function () {
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $saved = Customer::query()->create([
        'account_no' => 'FB-RT-5',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Saved',
        'name_ar' => 'محفوظ',
    ]);
    $lost = Customer::query()->create([
        'account_no' => 'FB-RT-6',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Lost',
        'name_ar' => 'مفقود',
    ]);
    $kept = app(RetentionDesk::class)->open($saved, $supervisor);
    Subscription::query()->create([
        'customer_id' => $saved->id,
        'price' => '35.00',
        'currency' => 'USD',
        'prepaid' => true,
        'current_period_end' => now()->addMonth(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $supervisor->id,
    ]);
    app(RetentionDesk::class)->offer($kept, ['kind' => 'free_month', 'months' => 1], $supervisor, Request::create('/'));
    app(RetentionDesk::class)->decline(app(RetentionDesk::class)->open($lost, $supervisor), 'price', $supervisor, Request::create('/'));
    expect(app(RetentionDesk::class)->saveRate())->toBe(['saved' => 1, 'closed' => 2, 'rate' => '50.00']);

    $risk = Customer::query()->create([
        'account_no' => 'FB-RT-7',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Risk',
        'name_ar' => 'خطر',
    ]);
    CustomerService::query()->create([
        'customer_id' => $risk->id,
        'service_type' => 'ftth',
        'plan_name' => 'Home',
        'status' => 'suspended',
        'monthly_usd' => '35.00',
    ]);
    CollectionCase::query()->create([
        'customer_id' => $risk->id,
        'amount_due' => '10.00',
        'stage' => 'open',
        'opened_on' => now()->toDateString(),
        'created_by' => $supervisor->id,
    ]);
    $gone = Customer::query()->create([
        'account_no' => 'FB-RT-8',
        'type' => 'individual',
        'status' => 'terminated',
        'name_en' => 'Gone',
        'name_ar' => 'غادر',
    ]);
    CustomerService::query()->create([
        'customer_id' => $gone->id,
        'service_type' => 'ftth',
        'plan_name' => 'Home',
        'status' => 'terminated',
        'monthly_usd' => '35.00',
        'terminated_at' => now()->subDays(80),
    ]);

    app(RetentionDesk::class)->sweep($supervisor);
    app(RetentionDesk::class)->sweep($supervisor);
    expect(WorkTask::query()->where('customer_id', $risk->id)->where('kind', 'retention')->count())->toBe(1)
        ->and($risk->fresh()->tags)->toContain('at-risk')
        ->and(WorkTask::query()->where('customer_id', $gone->id)->where('title', __('ui.retention.winback_task'))->count())->toBe(1);

    $event = RetentionEvent::query()->firstOrFail();
    expect(fn () => DB::table('retention_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);

    $this->actingAs($supervisor)->get('/retention')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Retention/Index')
        ->where('report.rate', '50.00'));
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/retention')->assertForbidden();
});
