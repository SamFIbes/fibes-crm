<?php

use App\Events\OpportunityWon;
use App\Listeners\OpenOrderFromWin;
use App\Mail\OrderNotice;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Opportunity;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\OrderMachine;
use App\Services\Quotes\ContractDesk;
use App\Services\Quotes\QuoteDesk;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    app(StockLedger::class)->seed();
});

it('walks every allowed and forbidden order step', function () {
    $machine = new OrderMachine;

    foreach (OrderMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(OrderMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('opens one order and one requested job when a deal is won', function () {
    [$agent, $deal] = wonDeal();
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $request = Request::create('/');
    $quote = app(QuoteDesk::class)->create($agent, [
        'product_id' => $plan->id,
        'qty' => 1,
        'discount_pct' => 0,
        'apply_vat' => 0,
        'submit' => true,
        'opportunity_id' => $deal->id,
        'customer_name' => 'Nour Haddad',
    ], $request);
    app(QuoteDesk::class)->send($quote->fresh(), $agent, 'link', $request);
    app(QuoteDesk::class)->accept($quote->fresh(), $agent, $request);
    $contract = app(ContractDesk::class)->open($quote->fresh(), $agent, ['term_months' => 12], $request);
    app(ContractDesk::class)->sendCode($contract, $request);
    app(ContractDesk::class)->sign($contract, [
        'signer_name' => 'Nour Haddad',
        'consent' => true,
        'otp' => Cache::get('fibes-sms:'.$contract->id),
        'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    ], $request, $agent);

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'won'])->assertRedirect();
    expect(Order::query()->where('opportunity_id', $deal->id)->count())->toBe(1);
    $order = Order::query()->where('opportunity_id', $deal->id)->firstOrFail();
    expect($order->type)->toBe('new')
        ->and($order->quote_id)->toBe(Quote::query()->value('id'))
        ->and($order->contract_id)->not->toBeNull();
    expect(Job::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(Job::query()->where('order_id', $order->id)->value('status'))->toBe('requested');
    app(OpenOrderFromWin::class)->handle(new OpportunityWon($deal->fresh()));
    expect(Order::query()->count())->toBe(1)->and(Job::query()->where('order_id', $order->id)->count())->toBe(1);
    expect(DB::table('audit_log')->where('event', 'order.created')->where('metadata->customer_id', $deal->customer_id)->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('event', 'order.job')->where('metadata->customer_id', $deal->customer_id)->exists())->toBeTrue();
});

it('releases reservations onto the stock book when an order is cancelled', function () {
    $admin = User::factory()->superAdmin()->create();
    $customer = customer();
    $order = app(OrderDesk::class)->openChange($admin, [
        'customer_id' => $customer->id,
        'type' => 'relocation',
    ], Request::create('/'));
    $fiber = StockItem::query()->where('sku', 'fiber')->firstOrFail();
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();
    app(StockLedger::class)->receive($fiber, $main, '10', [], $admin, Request::create('/'));
    $this->actingAs($admin)->post('/orders/'.$order->id.'/reserve', [
        'item_id' => $fiber->id,
        'location_id' => $main->id,
        'quantity' => '2',
    ])->assertRedirect();
    expect(app(StockLedger::class)->onHand($fiber->fresh(), $main->id))->toBe('10.000');

    $this->actingAs($admin)->post('/orders/'.$order->id.'/transition', [
        'to' => 'cancelled',
        'reason' => 'Customer changed their mind.',
    ])->assertRedirect();
    expect(DB::table('stock_reservations')->where('status', 'held')->count())->toBe(0)
        ->and(DB::table('stock_reservations')->where('status', 'released')->count())->toBe(1)
        ->and(StockMovement::query()->where('kind', 'release')->count())->toBe(1)
        ->and(app(StockLedger::class)->onHand($fiber->fresh(), $main->id))->toBe('10.000');

    $event = OrderEvent::query()->firstOrFail();
    expect(fn () => $event->update(['to_status' => 'draft']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('order_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

it('refuses activation without a radius username and then turns the service on', function () {
    $admin = User::factory()->superAdmin()->create();
    $order = app(OrderDesk::class)->openChange($admin, [
        'customer_id' => customer()->id,
        'type' => 'reactivation',
    ], Request::create('/'));

    foreach (['credit_check', 'ready_to_schedule', 'scheduled', 'in_progress', 'installed'] as $step) {
        $this->actingAs($admin)->post('/orders/'.$order->id.'/transition', ['to' => $step])->assertRedirect();
    }

    $this->actingAs($admin)->postJson('/orders/'.$order->id.'/transition', ['to' => 'activated'])
        ->assertStatus(422);
    $this->actingAs($admin)->post('/orders/'.$order->id.'/transition', [
        'to' => 'activated',
        'radius_username' => 'nour.haddad',
        'vlan' => '200',
        'mac' => 'AA:BB:CC:DD:EE:FF',
    ])->assertRedirect();
    $service = CustomerService::query()->firstOrFail();
    expect($service->status)->toBe('active')
        ->and($service->radius_username)->toBe('nour.haddad')
        ->and($service->activated_at)->not->toBeNull();
    expect(WorkTask::query()->where('kind', 'noc_krypton')->count())->toBe(1);
    expect(Invoice::query()->where('order_id', $order->id)->where('type', 'invoice')->exists())->toBeTrue()
        ->and($order->fresh()->billing_confirmed)->toBeTrue();

    $this->actingAs($admin)->post('/orders/'.$order->id.'/transition', ['to' => 'completed'])->assertRedirect();
    expect($order->fresh()->status)->toBe('completed');
});

it('holds a purchase above the limit until someone else approves, then receives the quantity', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $ops = User::factory()->create(['role' => 'ops_manager']);
    $fiber = StockItem::query()->where('sku', 'fiber')->firstOrFail();
    $main = StockLocation::query()->where('name', 'Main warehouse')->firstOrFail();

    $this->actingAs($warehouse)->post('/purchasing', [
        'erp_ref' => 'MR-SN-26-00077',
        'lines' => [['item_id' => $fiber->id, 'qty' => '10', 'unit_cost' => '30.00']],
    ])->assertRedirect();
    $po = PurchaseOrder::query()->firstOrFail();
    $this->actingAs($warehouse)->post('/purchasing/'.$po->id.'/submit')->assertRedirect();
    expect($po->fresh()->status)->toBe('pending_approval');
    $this->actingAs($warehouse)->post('/purchasing/'.$po->id.'/send')->assertSessionHasErrors('status');

    $approval = $po->fresh()->approval_id;
    $this->actingAs($ops)->post('/approvals/'.$approval.'/approve', ['comment' => 'Buy the cable.'])->assertRedirect();
    expect($po->fresh()->status)->toBe('approved');
    $this->actingAs($warehouse)->post('/purchasing/'.$po->id.'/send')->assertRedirect();
    $line = $po->lines()->firstOrFail();
    $this->actingAs($warehouse)->post('/purchasing/'.$po->id.'/receive', [
        'line_id' => $line->id,
        'location_id' => $main->id,
        'quantity' => '4',
    ])->assertRedirect();
    $first = StockMovement::query()->where('kind', 'receive')->firstOrFail();
    expect(app(StockLedger::class)->onHand($fiber->fresh(), $main->id))->toBe('4.000')
        ->and((string) $line->fresh()->received_qty)->toBe('4.000')
        ->and($po->fresh()->status)->toBe('partially_received');
    $this->actingAs($warehouse)->post('/purchasing/'.$po->id.'/receive', [
        'line_id' => $line->id,
        'location_id' => $main->id,
        'quantity' => '6',
    ])->assertRedirect();
    expect($po->fresh()->status)->toBe('received')
        ->and((string) $first->fresh()->quantity)->toBe('4.000')
        ->and(app(StockLedger::class)->onHand($fiber->fresh(), $main->id))->toBe('10.000');

    $this->actingAs($warehouse)->get('/purchasing/export')->assertOk()->assertSee('receive');
    expect(DB::table('audit_log')->where('event', 'purchasing.exported')->exists())->toBeTrue();
});

it('sends a termination and a price change through the approval inbox', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $management = User::factory()->create(['role' => 'management']);
    $admin = User::factory()->superAdmin()->create();
    $customer = customer();
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => 'Home',
        'status' => 'active',
        'monthly_usd' => '35.00',
    ]);

    $this->actingAs($agent)->post('/orders', [
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'termination',
        'return_charge' => '25.00',
        'notes' => 'Moving abroad.',
    ])->assertRedirect();
    $termination = Order::query()->where('type', 'termination')->firstOrFail();
    expect($termination->status)->toBe('pending_approval');
    $first = $termination->approval_id;
    $this->actingAs($manager)->post('/approvals/'.$first.'/approve', ['comment' => 'OK to end it.'])->assertRedirect();
    expect($termination->fresh()->status)->toBe('pending_approval');
    $this->actingAs($management)->post('/approvals/'.$first.'/approve', ['comment' => 'Approved.'])->assertRedirect();
    expect($termination->fresh()->status)->toBe('submitted')
        ->and(Job::query()->where('order_id', $termination->id)->value('type'))->toBe('removal');

    $this->actingAs($agent)->post('/orders', [
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'upgrade',
        'unit_price' => '50.00',
    ])->assertRedirect();
    $upgrade = Order::query()->where('type', 'upgrade')->firstOrFail();
    expect($upgrade->status)->toBe('pending_approval')
        ->and(DB::table('approvals')->where('id', $upgrade->approval_id)->value('type'))->toBe('plan_migration');
    $this->actingAs($management)->post('/approvals/'.$upgrade->approval_id.'/approve', ['comment' => 'New price is fine.'])->assertRedirect();
    $this->actingAs($admin)->post('/approvals/'.$upgrade->approval_id.'/approve', ['comment' => 'Confirmed.'])->assertRedirect();
    expect($upgrade->fresh()->status)->toBe('submitted');
});

it('builds a whatsapp link only when the customer has agreed', function () {
    Mail::fake();
    $admin = User::factory()->superAdmin()->create();
    $person = customer();
    $person->email = 'nour@example.com';
    $person->whatsapp_e164 = '+96170111222';
    $person->whatsapp_consent = false;
    $person->save();
    $order = app(OrderDesk::class)->openChange($admin, [
        'customer_id' => $person->id,
        'type' => 'relocation',
    ], Request::create('/'));

    foreach (['credit_check', 'ready_to_schedule', 'scheduled'] as $step) {
        $this->actingAs($admin)->post('/orders/'.$order->id.'/transition', ['to' => $step])->assertRedirect();
    }

    Mail::assertSent(OrderNotice::class);
    $this->actingAs($admin)->get('/orders/'.$order->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('order.whatsapp', null)
        ->where('order.consent', false));

    $person->whatsapp_consent = true;
    $person->save();
    $this->actingAs($admin)->get('/orders/'.$order->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('order.whatsapp', fn ($link) => is_string($link) && str_contains($link, 'wa.me/96170111222')));
});

it('keeps another sales agent off the order and the warehouse off creating one', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $order = app(OrderDesk::class)->openChange($agent, [
        'customer_id' => customer()->id,
        'type' => 'addon',
    ], Request::create('/'));

    $this->actingAs($other)->get('/orders/'.$order->id)->assertForbidden();
    $this->actingAs($warehouse)->post('/orders', [
        'customer_id' => $order->customer_id,
        'type' => 'termination',
    ])->assertForbidden();
    $this->actingAs($warehouse)->get('/orders')->assertOk();
});

function customer(): Customer
{
    return Customer::query()->create([
        'account_no' => 'FB-OR-'.Customer::query()->count(),
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour Haddad',
        'name_ar' => 'نور حداد',
    ]);
}

/**
 * @return array{0: User, 1: Opportunity}
 */
function wonDeal(): array
{
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->firstOrFail();
    $person = customer();
    $deal = Opportunity::query()->create([
        'customer_id' => $person->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $agent->id,
        'service_type' => 'ftth',
        'mrc' => '35.00',
        'currency' => 'USD',
        'stage_entered_at' => now(),
    ]);

    return [$agent, $deal];
}
