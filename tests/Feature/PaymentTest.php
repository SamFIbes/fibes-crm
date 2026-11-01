<?php

use App\Mail\OrderNotice;
use App\Models\Approval;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Billing\BillingDesk;
use App\Services\Billing\Ledger;
use App\Services\Payments\CashSessionMachine;
use App\Services\Payments\PaymentDesk;
use App\Services\Payments\PaymentMachine;
use App\Services\Payments\WhishDriver;
use App\Services\Settings\CommercialSettings;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden payment and cash step', function () {
    $payments = new PaymentMachine;
    $sessions = new CashSessionMachine;

    foreach (PaymentMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(PaymentMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($payments->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $payments->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }

    foreach (CashSessionMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(CashSessionMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($sessions->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $sessions->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('keeps the ledger equal to invoices minus payments and leaves a credit for the next invoice', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    [$order, $customer] = payableCustomer($finance);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'password',
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe('partially_paid')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('28.85')
        ->and(app(PaymentDesk::class)->referenceBalance($customer->id))->toBe('28.85');

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '50.00',
        'currency' => 'USD',
        'method' => 'card',
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('-21.15')
        ->and(app(PaymentDesk::class)->referenceBalance($customer->id))->toBe('-21.15');

    $subscription->next_invoice_at = now()->subDay()->toDateString();
    $subscription->save();
    app(BillingDesk::class)->run($finance, Request::create('/'));
    $second = Invoice::query()->where('type', 'invoice')->where('id', '!=', $invoice->id)->firstOrFail();

    expect($second->status)->toBe('partially_paid')
        ->and((string) $second->allocations()->sum('amount'))->toBe('21.15')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('17.70')
        ->and(app(PaymentDesk::class)->referenceBalance($customer->id))->toBe('17.70');
});

it('does not reduce the balance for a pending whish payment until finance confirms it', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $cc = User::factory()->create(['role' => 'cc_agent']);
    [$order, $customer] = payableCustomer($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));

    config(['fibes.whish.enabled' => true]);
    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'whish',
        'reference' => 'WH-100',
    ])->assertRedirect();

    $payment = Payment::query()->firstOrFail();
    expect($payment->status)->toBe('pending')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('38.85')
        ->and(app(WhishDriver::class)->charge('WH-100', '10.00', 'USD')['status'])->toBe('pending');

    $this->actingAs($cc)->post('/payments/'.$payment->id.'/confirm')->assertForbidden();
    $this->actingAs($finance)->post('/payments/'.$payment->id.'/confirm')->assertRedirect();
    expect($payment->fresh()->status)->toBe('confirmed')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('28.85');
});

it('converts a lebanese pound payment at the stored rate and allocates the oldest invoice first', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    [$order, $customer] = payableCustomer($finance);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));
    $first = Invoice::query()->where('type', 'invoice')->firstOrFail();
    $subscription->next_invoice_at = now()->subDay()->toDateString();
    $subscription->save();
    app(BillingDesk::class)->run($finance, Request::create('/'));
    $second = Invoice::query()->where('type', 'invoice')->where('id', '!=', $first->id)->firstOrFail();

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '3477000',
        'currency' => 'LBP',
        'method' => 'omt',
        'reference' => 'OMT-1',
    ])->assertRedirect();

    $payment = Payment::query()->firstOrFail();
    expect((string) $payment->amount)->toBe('38.85')
        ->and((string) $payment->exchange_rate)->toBe('89500.0000')
        ->and($first->fresh()->status)->toBe('paid')
        ->and($second->fresh()->status)->toBe('issued');

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'cheque',
        'reference' => 'CH-1',
        'invoice_id' => $second->id,
    ])->assertRedirect();
    expect($second->fresh()->status)->toBe('partially_paid')
        ->and($first->fresh()->status)->toBe('paid');
});

it('reverses a payment with a new ledger row and keeps the original', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $other = User::factory()->create(['role' => 'finance']);
    [$order, $customer] = payableCustomer($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'card',
    ])->assertRedirect();
    $payment = Payment::query()->firstOrFail();
    $number = $payment->number;

    $this->actingAs($finance)->post('/payments/'.$payment->id.'/reverse')->assertRedirect();
    $approval = Approval::query()->where('type', 'payment_reversal')->firstOrFail();
    $this->actingAs($finance)->post('/approvals/'.$approval->id.'/approve')->assertSessionHasErrors('approval');
    $this->actingAs($other)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Take it back.'])->assertRedirect();

    expect($payment->fresh()->status)->toBe('reversed')
        ->and($payment->fresh()->number)->toBe($number)
        ->and(Payment::query()->count())->toBe(1)
        ->and(app(Ledger::class)->balance($customer->id))->toBe('38.85');

    $event = PaymentEvent::query()->firstOrFail();
    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
    expect(fn () => DB::table('payment_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

it('refuses to close a cash drawer when the difference is above the limit until someone else approves', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $management = User::factory()->create(['role' => 'management']);
    [$order, $customer] = payableCustomer($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('password');

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'password',
    ])->assertRedirect();

    $session = CashSession::query()->firstOrFail();
    expect((string) $session->expected)->toBe('10.00');

    $this->actingAs($finance)->post('/cash-sessions/'.$session->id.'/close', [
        'counted' => '10.00',
        'password' => 'password',
    ])->assertRedirect();
    expect($session->fresh()->status)->toBe('closed');

    $this->actingAs($finance)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '5.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'password',
    ])->assertRedirect();
    $open = CashSession::query()->where('status', 'open')->firstOrFail();
    $this->actingAs($finance)->post('/cash-sessions/'.$open->id.'/close', [
        'counted' => '20.00',
        'password' => 'password',
    ])->assertSessionHasErrors('counted');
    expect($open->fresh()->status)->toBe('pending_close');

    $approval = Approval::query()->where('type', 'cash_variance')->firstOrFail();
    $this->actingAs($finance)->post('/approvals/'.$approval->id.'/approve')->assertSessionHasErrors('approval');
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'The drawer is short.'])->assertRedirect();
    expect($open->fresh()->status)->toBe('closed')
        ->and($open->fresh()->approved_by)->toBe($management->id);

    $event = CashSessionEvent::query()->firstOrFail();
    expect(fn () => DB::table('cash_session_events')->where('id', $event->id)->update(['to_status' => 'open']))->toThrow(QueryException::class);
});

it('records a field cash collection once and refuses it when the setting is off', function () {
    Mail::fake();
    $tech = User::factory()->create(['role' => 'field_technician']);
    $other = User::factory()->create(['role' => 'field_technician']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-PAY-FIELD',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'email' => 'rami@example.com',
        'whatsapp_e164' => '+9613123456',
        'whatsapp_consent' => true,
        'owner_id' => $tech->id,
    ]);
    $job = Job::query()->create([
        'number' => 'FJ-CASH-1',
        'type' => 'repair',
        'status' => 'on_site',
        'customer_id' => $customer->id,
        'technician_id' => $tech->id,
    ]);

    $this->actingAs($other)->post('/tech/jobs/'.$job->id.'/cash', [
        'amount' => '15.00',
        'currency' => 'USD',
        'password' => 'password',
    ])->assertForbidden();

    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/cash', [
        'amount' => '15.00',
        'currency' => 'USD',
        'password' => 'password',
    ], ['Idempotency-Key' => 'field-cash-1'])->assertRedirect();
    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/cash', [
        'amount' => '15.00',
        'currency' => 'USD',
        'password' => 'password',
    ], ['Idempotency-Key' => 'field-cash-1'])->assertRedirect();

    expect(Payment::query()->count())->toBe(1);
    $payment = Payment::query()->firstOrFail();
    expect($payment->status)->toBe('confirmed')
        ->and($payment->number)->toStartWith('RC-')
        ->and(app(PaymentDesk::class)->whatsapp($payment))->toContain('wa.me/9613123456');
    Mail::assertSent(OrderNotice::class);

    $figures = app(CommercialSettings::class)->defaults();
    $figures['field_cash'] = false;
    app(CommercialSettings::class)->save($figures, User::factory()->superAdmin()->create(), Request::create('/'));
    $this->actingAs($tech)->post('/tech/jobs/'.$job->id.'/cash', [
        'amount' => '4.00',
        'currency' => 'USD',
        'password' => 'password',
    ])->assertSessionHasErrors('method');
});

it('prints a receipt and a statement and lets the call center only request a payment', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $cc = User::factory()->create(['role' => 'cc_agent']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    [$order, $customer] = payableCustomer($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));

    $this->actingAs($warehouse)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '10.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'password',
    ])->assertForbidden();

    $this->actingAs($cc)->post('/payments', [
        'customer_id' => $customer->id,
        'amount' => '12.00',
        'currency' => 'USD',
        'method' => 'cash',
        'password' => 'password',
    ])->assertRedirect();
    $pending = Payment::query()->firstOrFail();
    expect($pending->status)->toBe('pending')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('38.85');

    $this->actingAs($finance)->get('/payments/'.$pending->id)->assertOk()->assertSee('Receipt')->assertSee('ل.ل.');
    $this->actingAs($finance)->get('/payments/'.$pending->id.'?locale=ar')->assertOk()->assertSee('إيصال');
    $this->actingAs($finance)->get('/customers/'.$customer->id.'/statement')->assertOk()->assertSee('Statement')->assertSee('Invoice');
    $this->actingAs($cc)->get('/customers/'.$customer->id.'/statement')->assertForbidden();
    $this->actingAs($finance)->get('/billing')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Billing/Index')
        ->where('payments.0.status', 'pending')
        ->where('can_record', true));
});

/**
 * @return array{0: Order, 1: Customer}
 */
function payableCustomer(User $actor): array
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-PAY-'.(Customer::query()->count() + 1),
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour Haddad',
        'name_ar' => 'نور حداد',
        'owner_id' => $actor->id,
    ]);
    $service = CustomerService::query()->create([
        'customer_id' => $customer->id,
        'service_type' => 'ftth',
        'plan_name' => '8 Mb',
        'status' => 'pending',
        'monthly_usd' => '35.00',
    ]);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'new',
        'status' => 'installed',
        'currency' => 'USD',
        'total' => '35.00',
        'owner_id' => $actor->id,
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

    return [$order, $customer];
}
