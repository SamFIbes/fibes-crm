<?php

use App\Jobs\RequestReactivation;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\CollectionCase;
use App\Models\CollectionEvent;
use App\Models\CollectionExemption;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Billing\BillingDesk;
use App\Services\Collections\CollectionMachine;
use App\Services\Collections\CollectionsDesk;
use App\Services\Settings\CommercialSettings;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Mail::fake();
});

it('walks every allowed and forbidden collection step', function () {
    $machine = new CollectionMachine;

    foreach (CollectionMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(CollectionMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('fires each dunning step once and uses whatsapp only when the customer agreed', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $agreed = serviceEnding($finance, 'agreed', now()->subDays(10), true);
    $quiet = serviceEnding($finance, 'quiet', now()->subDays(10), false);

    expect(app(CollectionsDesk::class)->run($finance))->toBeGreaterThan(0);
    $first = DB::table('dunning_fires')->where('subscription_id', $agreed->id)->count();
    app(CollectionsDesk::class)->run($finance);
    expect(DB::table('dunning_fires')->where('subscription_id', $agreed->id)->count())->toBe($first)
        ->and($agreed->fresh()->status)->toBe('suspended')
        ->and(WorkTask::query()->where('customer_id', $agreed->customer_id)->where('kind', 'noc_krypton')->count())->toBe(1)
        ->and(WorkTask::query()->where('customer_id', $agreed->customer_id)->where('notes', 'like', '%wa.me/%')->exists())->toBeTrue()
        ->and(WorkTask::query()->where('customer_id', $quiet->customer_id)->where('notes', 'like', '%wa.me/%')->exists())->toBeFalse();
});

it('skips automatic suspension for an exempt customer and records it', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $other = User::factory()->create(['role' => 'finance']);
    $subscription = serviceEnding($finance, 'vip', now()->subDays(10), false);
    $customer = Customer::query()->findOrFail($subscription->customer_id);

    $this->actingAs($finance)->post('/customers/'.$customer->id.'/exemption', [
        'kind' => 'extension',
        'until' => now()->addDays(10)->toDateString(),
    ])->assertRedirect();
    $approval = Approval::query()->where('type', 'suspension_exemption')->firstOrFail();
    $this->actingAs($other)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Give them time.'])->assertRedirect();
    expect(CollectionExemption::query()->where('customer_id', $customer->id)->where('active', true)->exists())->toBeTrue();

    app(CollectionsDesk::class)->run($finance);
    expect($subscription->fresh()->status)->toBe('active')
        ->and(AuditLog::query()->where('event', 'collection.exempt_skipped')->exists())->toBeTrue()
        ->and(WorkTask::query()->where('customer_id', $customer->id)->where('kind', 'noc_krypton')->exists())->toBeFalse();
});

it('moves a missed promise the day after and matches the renewal list', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $soon = serviceEnding($finance, 'soon', now()->addDays(5), false);
    $soon->auto_renew = false;
    $soon->save();
    $later = serviceEnding($finance, 'later', now()->addDays(40), false);
    $case = CollectionCase::query()->create([
        'customer_id' => $soon->customer_id,
        'subscription_id' => $soon->id,
        'amount_due' => '20.00',
        'stage' => 'promise',
        'promise_to_pay_date' => now()->subDay()->toDateString(),
        'promise_amount' => '20.00',
        'opened_on' => now()->subDays(9)->toDateString(),
        'created_by' => $finance->id,
    ]);

    app(CollectionsDesk::class)->run($finance);
    expect($case->fresh()->stage)->toBe('broken')
        ->and(app(CollectionsDesk::class)->renewalQueryCount())->toBe(count(app(CollectionsDesk::class)->renewals()))
        ->and(collect(app(CollectionsDesk::class)->renewals())->pluck('id')->all())->toContain($soon->id)
        ->and(collect(app(CollectionsDesk::class)->renewals())->pluck('id')->all())->not->toContain($later->id)
        ->and(WorkTask::query()->where('customer_id', $soon->customer_id)->where('title', __('ui.collections.renewal_task'))->count())->toBe(1);

    app(CollectionsDesk::class)->run($finance);
    expect(WorkTask::query()->where('customer_id', $soon->customer_id)->where('title', __('ui.collections.renewal_task'))->count())->toBe(1);

    $event = CollectionEvent::query()->firstOrFail();
    expect(fn () => DB::table('collection_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

it('queues a krypton reactivation when a payment clears a suspended balance', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    [$subscription, $customer] = suspendedDebt($finance);

    Queue::fake();
    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'card',
    ])->assertRedirect();
    Queue::assertNothingPushed();

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '28.85',
        'currency' => 'USD',
        'method' => 'card',
    ])->assertRedirect();
    Queue::assertPushed(RequestReactivation::class);
    expect($subscription->fresh()->status)->toBe('suspended');
});

it('creates the krypton task when that queued reactivation runs', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    [, $customer] = suspendedDebt($finance);

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '38.85',
        'currency' => 'USD',
        'method' => 'card',
    ])->assertRedirect();

    expect(WorkTask::query()->where('customer_id', $customer->id)->where('kind', 'noc_krypton')->where('title', __('ui.collections.krypton_reactivate'))->exists())->toBeTrue()
        ->and(CollectionCase::query()->where('customer_id', $customer->id)->where('stage', 'paid')->exists())->toBeTrue();
});

it('lets finance open the board and keeps the call center from suspending', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $cc = User::factory()->create(['role' => 'cc_agent']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $subscription = serviceEnding($finance, 'board', now()->subDays(2), false);
    $case = CollectionCase::query()->create([
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
        'amount_due' => '15.00',
        'stage' => 'open',
        'opened_on' => now()->subDays(2)->toDateString(),
        'created_by' => $finance->id,
    ]);

    $this->actingAs($warehouse)->get('/collections')->assertForbidden();
    $this->actingAs($finance)->get('/collections')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Collections/Index')
        ->where('cases.0.stage', 'open')
        ->where('can_suspend', true));
    $this->actingAs($cc)->post('/collections/'.$case->id.'/suspend')->assertForbidden();
    $this->actingAs($finance)->post('/collections/'.$case->id.'/suspend')->assertRedirect();
    expect($subscription->fresh()->status)->toBe('suspended')
        ->and(WorkTask::query()->where('customer_id', $subscription->customer_id)->where('title', __('ui.collections.krypton_suspend'))->exists())->toBeTrue();

    $figures = app(CommercialSettings::class)->defaults();
    $figures['reconnect_fee'] = '10.00';
    app(CommercialSettings::class)->save($figures, User::factory()->superAdmin()->create(), Request::create('/'));
    expect(app(CommercialSettings::class)->money('reconnect_fee'))->toBe('10.00')
        ->and(DB::table('dunning_policies')->where('key', 'default')->exists())->toBeTrue();
});

function serviceEnding(User $actor, string $name, Carbon $end, bool $whatsapp): Subscription
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-CL-'.$name,
        'type' => 'individual',
        'status' => 'active',
        'name_en' => $name,
        'name_ar' => $name,
        'email' => $name.'@example.com',
        'whatsapp_e164' => $whatsapp ? '+9613000000' : null,
        'whatsapp_consent' => $whatsapp,
        'owner_id' => $actor->id,
    ]);
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => '8 Mb',
        'status' => 'active',
        'monthly_usd' => '35.00',
        'radius_username' => $name,
    ]);

    return Subscription::query()->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'price' => '35.00',
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'prepaid' => true,
        'current_period_start' => $end->copy()->subMonth(),
        'current_period_end' => $end,
        'next_invoice_at' => $end->toDateString(),
        'status' => 'active',
        'auto_renew' => true,
        'grace_days' => 3,
        'created_by' => $actor->id,
    ]);
}

/**
 * @return array{0: Subscription, 1: Customer}
 */
function suspendedDebt(User $actor): array
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-CL-DEBT',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Debt',
        'name_ar' => 'دين',
        'owner_id' => $actor->id,
    ]);
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => '8 Mb',
        'status' => 'active',
        'monthly_usd' => '35.00',
    ]);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'new',
        'status' => 'installed',
        'currency' => 'USD',
        'total' => '35.00',
        'updated_by' => $actor->id,
    ]);
    OrderLine::query()->create([
        'order_id' => $order->id,
        'description' => '8 Mb',
        'qty' => '1.00',
        'unit_price' => '35.00',
        'line_total' => '35.00',
        'is_recurring' => true,
    ]);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $actor, Request::create('/'));
    $case = CollectionCase::query()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subscription?->id,
        'amount_due' => '38.85',
        'stage' => 'open',
        'opened_on' => now()->toDateString(),
        'created_by' => $actor->id,
    ]);
    app(CollectionsDesk::class)->suspend($case, $actor, Request::create('/'));

    return [$subscription, $customer];
}
