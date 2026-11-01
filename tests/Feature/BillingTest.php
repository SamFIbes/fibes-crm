<?php

use App\Models\Approval;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\DiscountLimit;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\Job;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingDesk;
use App\Services\Billing\InvoiceMachine;
use App\Services\Billing\InvoiceNumbers;
use App\Services\Billing\Ledger;
use App\Services\Billing\Proration;
use App\Services\Billing\SubscriptionMachine;
use App\Services\Quotes\QuoteDesk;
use App\Services\Settings\CommercialSettings;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden billing step', function () {
    $invoices = new InvoiceMachine;
    $subscriptions = new SubscriptionMachine;

    foreach (InvoiceMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(InvoiceMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($invoices->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $invoices->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }

    foreach (SubscriptionMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(SubscriptionMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($subscriptions->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $subscriptions->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('prorates a mid-cycle month and bills the full price for a full period', function () {
    $portion = app(Proration::class)->portion('35.00', 15, 30);
    expect($portion)->toBe('17.50')
        ->and(app(BillingDesk::class)->chargeFor('35.00', 15, 30))->toBe('17.50')
        ->and(app(Proration::class)->portion('35.00', 30, 30))->toBe('35.00');

    app(CommercialSettings::class)->save(array_merge(app(CommercialSettings::class)->defaults(), [
        'proration' => 'full',
    ]), User::factory()->superAdmin()->create(), Request::create('/'));

    expect(app(BillingDesk::class)->chargeFor('35.00', 15, 30))->toBe('35.00');
});

it('issues the plan price with vat on activation and keeps the ledger in step', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order, $customer] = billedOrder($admin);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($subscription->status)->toBe('active')
        ->and((string) $subscription->price)->toBe('35.00')
        ->and($order->billing_confirmed)->toBeTrue();

    $invoice = Invoice::query()->where('order_id', $order->id)->where('type', 'invoice')->firstOrFail();
    expect($invoice->number)->toBe('INV-'.now()->year.'-000001')
        ->and((string) $invoice->subtotal)->toBe('35.00')
        ->and((string) $invoice->tax)->toBe('3.85')
        ->and((string) $invoice->total)->toBe('38.85')
        ->and((string) $invoice->lbp_total)->toBe('3477000.00')
        ->and((string) $invoice->exchange_rate)->toBe('89500.0000')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('38.85')
        ->and(app(Ledger::class)->documents($customer->id))->toBe('38.85');

    $again = app(BillingDesk::class)->issue($subscription, $admin, Request::create('/'), null, $subscription->current_period_start?->toDateString(), $subscription->current_period_end?->toDateString(), $order->id);
    expect($again->id)->toBe($invoice->id)
        ->and(Invoice::query()->where('type', 'invoice')->count())->toBe(1);
});

it('gives invoice numbers with no gaps', function () {
    $numbers = app(InvoiceNumbers::class);
    $year = (int) now()->year;
    $issued = [];

    foreach (range(1, 5) as $expected) {
        $issued[] = $numbers->next('INV', $year);
        expect($numbers->format('INV', $year, end($issued)))->toBe('INV-'.$year.'-'.str_pad((string) $expected, 6, '0', STR_PAD_LEFT));
    }

    expect($issued)->toBe([1, 2, 3, 4, 5])
        ->and((int) DB::table('invoice_sequences')->where('prefix', 'INV')->where('year', $year)->value('last_number'))->toBe(5);
});

it('refuses to void an invoice that has a payment and keeps the number when a void is allowed', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order, $customer] = billedOrder($admin);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();
    $number = $invoice->number;
    app(Ledger::class)->post($customer->id, 'payment', '0.00', '10.00', 'USD', '89500.0000', 'invoice', $invoice->id, $admin);

    expect(fn () => app(BillingDesk::class)->void($invoice, 'Paid by mistake', $admin, Request::create('/')))
        ->toThrow(ValidationException::class);
    expect($invoice->fresh()->status)->toBe('issued')
        ->and($invoice->fresh()->number)->toBe($number);

    $subscription->next_invoice_at = now()->subDay()->toDateString();
    $subscription->save();
    app(BillingDesk::class)->run($admin, Request::create('/'));
    $second = Invoice::query()->where('type', 'invoice')->where('id', '!=', $invoice->id)->firstOrFail();
    $kept = $second->number;
    app(BillingDesk::class)->void($second, 'Issued in error', $admin, Request::create('/'));

    expect($second->fresh()->status)->toBe('voided')
        ->and($second->fresh()->number)->toBe($kept)
        ->and(LedgerEntry::query()->where('source_id', $second->id)->where('entry_type', 'reversal')->exists())->toBeTrue();
});

it('runs billing once for a due subscription and ignores a second run the same day', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order] = billedOrder($admin);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $subscription->next_invoice_at = now()->subDay()->toDateString();
    $subscription->save();

    expect(app(BillingDesk::class)->run($admin, Request::create('/')))->toBe(1)
        ->and(app(BillingDesk::class)->run($admin, Request::create('/')))->toBe(0)
        ->and(Invoice::query()->where('subscription_id', $subscription->id)->where('type', 'invoice')->count())->toBe(2);
});

it('posts a small credit note for finance and sends a larger one through the inbox', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $other = User::factory()->create(['role' => 'finance']);
    [$order, $customer] = billedOrder($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();

    $this->actingAs($finance)->post('/invoices/'.$invoice->id.'/credit', ['amount' => '10.00'])->assertRedirect();
    $credit = Invoice::query()->where('type', 'credit_note')->firstOrFail();
    expect((string) $credit->total)->toBe('10.00')
        ->and($credit->number)->toStartWith('CN-')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('28.85')
        ->and(app(Ledger::class)->documents($customer->id))->toBe('28.85');

    $this->actingAs($finance)->post('/invoices/'.$invoice->id.'/credit', ['amount' => '25.00'])->assertSessionHasErrors('amount');
    $approval = Approval::query()->where('type', 'credit_note')->firstOrFail();
    expect($approval->status)->toBe('pending')
        ->and(Invoice::query()->where('type', 'credit_note')->count())->toBe(1);
    $this->actingAs($finance)->post('/approvals/'.$approval->id.'/approve')->assertSessionHasErrors('approval');
    $this->actingAs($other)->post('/approvals/'.$approval->id.'/approve', ['comment' => 'Credit the rest.'])->assertRedirect();
    expect(Invoice::query()->where('type', 'credit_note')->count())->toBe(2)
        ->and(app(Ledger::class)->balance($customer->id))->toBe('3.85');
});

it('writes off through two people and refunds through the management step', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $second = User::factory()->create(['role' => 'finance']);
    $management = User::factory()->create(['role' => 'management']);
    [$order, $customer] = billedOrder($finance);
    app(BillingDesk::class)->openFromOrder($order, $finance, Request::create('/'));
    $invoice = Invoice::query()->where('type', 'invoice')->firstOrFail();

    $this->actingAs($finance)->post('/invoices/'.$invoice->id.'/write-off')->assertRedirect();
    $writeOff = Approval::query()->where('type', 'write_off')->firstOrFail();
    $this->actingAs($finance)->post('/approvals/'.$writeOff->id.'/approve')->assertSessionHasErrors('approval');
    $this->actingAs($second)->post('/approvals/'.$writeOff->id.'/approve', ['comment' => 'Uncollectable.'])->assertRedirect();
    expect($invoice->fresh()->status)->toBe('issued');
    $this->actingAs($management)->post('/approvals/'.$writeOff->id.'/approve', ['comment' => 'Agreed.'])->assertRedirect();
    expect($invoice->fresh()->status)->toBe('written_off')
        ->and(app(Ledger::class)->balance($customer->id))->toBe('0.00');

    $this->actingAs($finance)->post('/billing/refunds', [
        'customer_id' => $customer->id,
        'amount' => '60.00',
    ])->assertRedirect();
    $refund = Approval::query()->where('type', 'refund')->firstOrFail();
    $this->actingAs($management)->post('/approvals/'.$refund->id.'/approve', ['comment' => 'Send it back.'])->assertRedirect();
    expect(LedgerEntry::query()->where('customer_id', $customer->id)->where('entry_type', 'refund')->exists())->toBeTrue()
        ->and(app(Ledger::class)->balance($customer->id))->toBe('-60.00');
});

it('blocks changes to the ledger and invoice history', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order] = billedOrder($admin);
    app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $entry = LedgerEntry::query()->firstOrFail();
    $event = InvoiceEvent::query()->firstOrFail();

    expect(fn () => $entry->update(['debit' => '1.00']))->toThrow(RuntimeException::class);
    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
    expect(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('invoice_events')->where('id', $event->id)->update(['to_status' => 'draft']))->toThrow(QueryException::class);
});

it('limits a hold and requires a return before a subscription ends', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order] = billedOrder($admin);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    $desk = app(BillingDesk::class);

    expect(fn () => $desk->suspend($subscription, 'hold', 45, $admin, Request::create('/')))->toThrow(ValidationException::class);
    $desk->suspend($subscription, 'hold', 10, $admin, Request::create('/'));
    expect($subscription->fresh()->status)->toBe('suspended');
    expect(fn () => $desk->terminate($subscription->fresh(), $admin, Request::create('/')))->toThrow(ValidationException::class);

    $order->return_charge = '25.00';
    $order->save();
    $desk->terminate($subscription->fresh(), $admin, Request::create('/'));
    expect($subscription->fresh()->status)->toBe('terminated');
    expect(AuditLog::query()->where('event', 'billing.subscription_terminated')->exists())->toBeTrue();
});

it('lets an admin change a to-confirm figure and writes the audit log', function () {
    $admin = User::factory()->superAdmin()->create();
    $figures = app(CommercialSettings::class)->defaults();
    $figures['vat_percent'] = '12.00';
    $figures['agent_line_percent'] = '6.00';
    $figures['plan_home_price'] = '36.00';
    $figures['quote_discount_timeout_hours'] = 5;
    $figures['purchase_auto_amount'] = '250.00';
    $figures['credit_note_auto_amount'] = '30.00';

    $this->actingAs($admin)->put('/settings/commercial', ['commercial' => $figures])->assertRedirect();

    expect(app(CommercialSettings::class)->get('vat_percent'))->toBe('12.00')
        ->and(AuditLog::query()->where('event', 'settings.updated')->exists())->toBeTrue()
        ->and((string) DiscountLimit::query()->where('role', 'sales_agent')->value('line_percent'))->toBe('6.00')
        ->and(ApprovalPolicy::query()->where('key', 'quote_discount')->value('timeout_hours'))->toBe(5)
        ->and(ApprovalPolicy::query()->where('key', 'purchase_order')->firstOrFail()->conditions['auto_amount'])->toBe('250.00')
        ->and(ApprovalPolicy::query()->where('key', 'credit_note')->firstOrFail()->conditions['auto_amount'])->toBe('30.00');

    $product = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    expect(PriceListItem::query()->where('product_id', $product->id)->where('price', '36.00')->exists())->toBeTrue()
        ->and(PriceListItem::query()->where('product_id', $product->id)->where('price', '35.00')->exists())->toBeTrue();

    $quote = app(QuoteDesk::class)->create($admin, [
        'product_id' => $product->id,
        'qty' => 1,
        'discount_pct' => 0,
        'apply_vat' => 1,
        'customer_name' => 'Nour Haddad',
    ], Request::create('/'));
    expect((string) $quote->lines()->firstOrFail()->tax_rate)->toBe('12.00');

    [$order] = billedOrder($admin, '39.00');
    app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    expect((string) Invoice::query()->latest('id')->firstOrFail()->tax)->toBe('4.68');
});

it('shows amounts to finance and only a balance to the call center', function () {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $cc = User::factory()->create(['role' => 'cc_agent']);
    [$order, $customer] = billedOrder($admin);
    $customer->owner_id = $agent->id;
    $customer->save();
    app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));

    expect(app(BillingDesk::class)->visibleInvoices($agent)->count())->toBe(1)
        ->and(app(BillingDesk::class)->visibleInvoices($other)->count())->toBe(0);

    $this->actingAs($admin)->get('/billing')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Billing/Index')
        ->where('see_amounts', true)
        ->where('invoices.0.total', '38.85'));
    $this->actingAs($cc)->get('/billing')->assertOk()->assertInertia(fn ($page) => $page
        ->where('balance_only', true)
        ->where('invoices', []));
    $this->actingAs($admin)->get('/customers/'.$customer->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('billing_balance', '38.85')
        ->where('billing_invoices.0.total', '38.85'));
    $this->actingAs($cc)->get('/customers/'.$customer->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('billing_balance', '38.85')
        ->where('billing_invoices', []));

    $invoice = Invoice::query()->firstOrFail();
    $this->actingAs($admin)->get('/invoices/'.$invoice->id)->assertOk()->assertSee('INV-')->assertSee('ل.ل.');
    $this->actingAs($admin)->get('/invoices/'.$invoice->id.'?locale=ar')->assertOk()->assertSee('فاتورة');
    $this->actingAs($cc)->get('/invoices/'.$invoice->id)->assertForbidden();
});

it('accepts an equipment return job in place of a return charge', function () {
    $admin = User::factory()->superAdmin()->create();
    [$order, $customer] = billedOrder($admin);
    $subscription = app(BillingDesk::class)->openFromOrder($order, $admin, Request::create('/'));
    Job::query()->create([
        'number' => 'FJ-RETURN-1',
        'type' => 'removal',
        'status' => 'done',
        'customer_id' => $customer->id,
        'order_id' => $order->id,
    ]);

    app(BillingDesk::class)->terminate($subscription, $admin, Request::create('/'));
    expect($subscription->fresh()->status)->toBe('terminated');
});

/**
 * @return array{0: Order, 1: Customer}
 */
function billedOrder(User $actor, string $price = '35.00'): array
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-BL-'.(Customer::query()->count() + 1),
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
        'monthly_usd' => $price,
    ]);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'customer_service_id' => $service->id,
        'type' => 'new',
        'status' => 'installed',
        'currency' => 'USD',
        'total' => $price,
        'owner_id' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    OrderLine::query()->create([
        'order_id' => $order->id,
        'description' => '8 Mb',
        'qty' => '1.00',
        'unit_price' => $price,
        'line_total' => $price,
        'is_recurring' => true,
    ]);

    return [$order, $customer];
}
