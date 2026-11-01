<?php

namespace App\Services\Billing;

use App\Models\CustomerService;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Job;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Opportunities\OpportunityMoney;
use App\Services\Payments\PaymentDesk;
use App\Services\Settings\CommercialSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BillingDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private MoneyMath $money,
        private Proration $proration,
        private InvoiceNumbers $numbers,
        private InvoiceMachine $invoices,
        private SubscriptionMachine $subscriptions,
        private Ledger $ledger,
        private OpportunityMoney $lbp,
        private AuditLogger $audit,
    ) {}

    public function openFromOrder(Order $order, ?User $actor = null, ?Request $request = null): ?Subscription
    {
        $request ??= Request::create('/');
        $actor ??= ($order->updated_by ? User::query()->find($order->updated_by) : null);
        $actor ??= User::query()->where('role', 'super_admin')->first();

        if (! $actor instanceof User || ! $order->customer_service_id) {
            $order->billing_confirmed = true;

            return null;
        }

        $existing = Subscription::query()->where('service_id', $order->customer_service_id)->where('status', '!=', 'terminated')->first();

        if ($existing instanceof Subscription) {
            $order->billing_confirmed = true;

            return $existing;
        }

        $service = CustomerService::query()->find($order->customer_service_id);
        $order->loadMissing('lines');
        $recurring = $order->lines->firstWhere('is_recurring', true);
        $price = $order->currency === 'USD' && $recurring instanceof OrderLine
            ? (string) $recurring->unit_price
            : (string) ($service?->monthly_usd ?? '0.00');
        [$start, $end] = $this->period(now());
        $subscription = Subscription::query()->create([
            'customer_id' => $order->customer_id,
            'service_id' => $order->customer_service_id,
            'order_id' => $order->id,
            'contract_id' => $order->contract_id,
            'price' => $price,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'prepaid' => (bool) $this->commercial->get('prepaid'),
            'current_period_start' => $start,
            'current_period_end' => $end,
            'next_invoice_at' => $end->toDateString(),
            'status' => 'active',
            'auto_renew' => true,
            'grace_days' => (int) $this->commercial->get('grace_days'),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        if ($service instanceof CustomerService) {
            $service->subscription_id = $subscription->id;
            $service->save();
        }

        $lines = [[
            'description' => $recurring?->description ?: ($service?->plan_name ?: 'Service'),
            'qty' => '1.00',
            'unit_price' => $price,
            'is_recurring' => true,
            'product_id' => $recurring?->product_id,
        ]];

        foreach ($order->lines as $line) {
            if ($line->is_recurring) {
                continue;
            }

            $lines[] = [
                'description' => $line->description,
                'qty' => (string) $line->qty,
                'unit_price' => $order->currency === 'USD' ? (string) $line->unit_price : '0.00',
                'is_recurring' => false,
                'product_id' => $line->product_id,
            ];
        }

        $this->issue($subscription, $actor, $request, $lines, $start->toDateString(), $end->toDateString(), $order->id);
        $order->billing_confirmed = true;
        $this->audit->record('billing.subscription', $actor, [
            'subscription_id' => $subscription->id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'price' => $price,
        ], $request);

        return $subscription;
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function issue(Subscription $subscription, User $actor, Request $request, ?array $lines = null, ?string $periodStart = null, ?string $periodEnd = null, ?int $orderId = null): Invoice
    {
        $start = $periodStart ?? $subscription->current_period_start?->toDateString() ?? now()->toDateString();
        $existing = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', 'invoice')
            ->whereDate('period_start', $start)
            ->where('status', '!=', 'voided')
            ->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $built = $lines ?? [[
            'description' => 'Service',
            'qty' => '1.00',
            'unit_price' => (string) $subscription->price,
            'is_recurring' => true,
        ]];

        return $this->createDocument($subscription->customer_id, 'invoice', 'INV', $built, $actor, $request, $subscription->id, $orderId, $start, $periodEnd, null);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDocument(int $customerId, string $type, string $prefix, array $lines, User $actor, Request $request, ?int $subscriptionId = null, ?int $orderId = null, ?string $periodStart = null, ?string $periodEnd = null, ?int $sourceInvoiceId = null): Invoice
    {
        $vat = (string) $this->commercial->get('vat_percent');
        $subtotal = '0.00';
        $tax = '0.00';
        $prepared = [];

        foreach ($lines as $index => $line) {
            $net = $this->money->round(bcmul((string) $line['qty'], (string) $line['unit_price'], 6));
            $lineTax = $this->money->tax($net, $vat);
            $subtotal = bcadd($subtotal, $net, 2);
            $tax = bcadd($tax, $lineTax, 2);
            $prepared[] = [
                'description' => $line['description'],
                'qty' => bcadd((string) $line['qty'], '0', 2),
                'unit_price' => bcadd((string) $line['unit_price'], '0', 2),
                'tax_rate' => $vat,
                'line_total' => bcadd($net, $lineTax, 2),
                'is_recurring' => (bool) ($line['is_recurring'] ?? false),
                'product_id' => $line['product_id'] ?? null,
                'sort' => $index,
            ];
        }

        $total = bcadd($subtotal, $tax, 2);
        $rate = $this->currentRate();
        $year = (int) now()->year;
        $number = $this->numbers->format($prefix, $year, $this->numbers->next($prefix, $year));
        $invoice = Invoice::query()->create([
            'number' => $number,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => 'USD',
            'exchange_rate' => $rate,
            'subtotal' => $subtotal,
            'discount' => '0.00',
            'tax' => $tax,
            'total' => $total,
            'lbp_total' => bcadd($this->lbp->lbp($total, $rate), '0', 2),
            'status' => 'issued',
            'issued_at' => now(),
            'due_at' => now(),
            'created_by' => $actor->id,
        ]);

        foreach ($prepared as $line) {
            InvoiceLine::query()->create(['invoice_id' => $invoice->id] + $line);
        }

        $this->event($invoice, null, 'issued', $actor);
        $entry = $type === 'credit_note' ? 'credit' : 'invoice';
        $this->ledger->post(
            $customerId,
            $entry,
            $type === 'credit_note' ? '0.00' : $total,
            $type === 'credit_note' ? $total : '0.00',
            'USD',
            $rate,
            'invoice',
            $invoice->id,
            $actor,
        );
        if ($type === 'invoice') {
            app(PaymentDesk::class)->applyCredit($invoice, $actor);
        }

        $this->audit->record('billing.issued', $actor, [
            'invoice_id' => $invoice->id,
            'customer_id' => $customerId,
            'number' => $number,
            'type' => $type,
            'total' => $total,
            'tax' => $tax,
            'vat_percent' => $vat,
            'rate' => $rate,
            'source_invoice_id' => $sourceInvoiceId,
        ], $request);

        return $invoice;
    }

    public function credit(Invoice $invoice, string $amount, User $actor, Request $request): Invoice
    {
        $amount = bcadd($amount, '0', 2);
        $auto = (string) $this->commercial->money('credit_note_auto_amount');
        $within = $actor->role === 'finance' && bccomp($amount, $auto, 2) <= 0;

        if ($within) {
            return $this->postCredit($invoice, $amount, $actor, $request);
        }

        $approval = app(ApprovalDesk::class)->open($actor, 'credit_note', 'invoice', $invoice->id, [
            'amount' => $amount,
            'customer_id' => $invoice->customer_id,
        ], (string) $invoice->number, $request);

        if ($approval->status === 'approved') {
            $posted = Invoice::query()->where('customer_id', $invoice->customer_id)->where('type', 'credit_note')->latest('id')->first();

            if ($posted instanceof Invoice) {
                return $posted;
            }
        }

        throw ValidationException::withMessages(['amount' => __('ui.billing.approval_needed')]);
    }

    public function chargeFor(string $price, int $days, int $periodDays): string
    {
        if ($this->commercial->get('proration') === 'full') {
            return bcadd($price, '0', 2);
        }

        return $this->proration->portion($price, $days, $periodDays);
    }

    public function postCredit(Invoice $invoice, string $amount, User $actor, Request $request): Invoice
    {
        $vat = (string) $this->commercial->get('vat_percent');
        $net = $this->money->round(bcdiv(bcmul($amount, '100', 6), bcadd('100', $vat, 2), 6));

        return $this->createDocument($invoice->customer_id, 'credit_note', 'CN', [[
            'description' => 'Credit '.$invoice->number,
            'qty' => '1.00',
            'unit_price' => $net,
            'is_recurring' => false,
        ]], $actor, $request, $invoice->subscription_id, $invoice->order_id, null, null, $invoice->id);
    }

    public function requestRefund(int $customerId, string $amount, User $actor, Request $request): void
    {
        $amount = bcadd($amount, '0', 2);
        $approval = app(ApprovalDesk::class)->open($actor, 'refund', 'customer', $customerId, [
            'amount' => $amount,
        ], 'Refund '.$amount, $request);
        Refund::query()->create([
            'customer_id' => $customerId,
            'approval_id' => $approval->id,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pending',
            'created_by' => $actor->id,
        ]);
        $this->audit->record('billing.refund_requested', $actor, ['customer_id' => $customerId, 'amount' => $amount], $request);
    }

    public function requestWriteOff(Invoice $invoice, User $actor, Request $request): void
    {
        $approval = app(ApprovalDesk::class)->open($actor, 'write_off', 'invoice', $invoice->id, [
            'amount' => (string) $invoice->total,
            'customer_id' => $invoice->customer_id,
        ], (string) $invoice->number, $request);
        WriteOff::query()->create([
            'invoice_id' => $invoice->id,
            'approval_id' => $approval->id,
            'amount' => (string) $invoice->total,
            'status' => 'pending',
            'created_by' => $actor->id,
        ]);
    }

    public function applyApproved(string $type, int $subjectId, string $amount, User $actor, ?Request $request): void
    {
        $request ??= Request::create('/');

        if ($type === 'credit_note') {
            $invoice = Invoice::query()->find($subjectId);

            if ($invoice instanceof Invoice) {
                $this->postCredit($invoice, $amount, $actor, $request);
            }
        }

        if ($type === 'write_off') {
            $invoice = Invoice::query()->find($subjectId);

            if ($invoice instanceof Invoice && $invoice->status !== 'written_off') {
                $this->move($invoice, 'written_off', $actor, $request, null);
                $this->ledger->post($invoice->customer_id, 'write_off', '0.00', (string) $invoice->total, 'USD', (string) $invoice->exchange_rate, 'invoice', $invoice->id, $actor);
                $row = WriteOff::query()->where('invoice_id', $invoice->id)->where('status', 'pending')->latest('id')->first();

                if ($row instanceof WriteOff) {
                    $row->status = 'posted';
                    $row->save();
                }

                $this->audit->record('billing.written_off', $actor, ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'total' => (string) $invoice->total], $request);
            }
        }

        if ($type === 'refund') {
            $this->ledger->post($subjectId, 'refund', '0.00', bcadd($amount, '0', 2), 'USD', $this->currentRate(), 'customer', $subjectId, $actor);
            $row = Refund::query()->where('customer_id', $subjectId)->where('status', 'pending')->latest('id')->first();

            if ($row instanceof Refund) {
                $row->status = 'posted';
                $row->save();
            }

            $this->audit->record('billing.refunded', $actor, ['customer_id' => $subjectId, 'amount' => $amount], $request);
        }
    }

    public function void(Invoice $invoice, string $reason, User $actor, Request $request): void
    {
        if (LedgerEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->where('entry_type', 'payment')->exists()) {
            throw ValidationException::withMessages(['status' => __('ui.billing.void_paid')]);
        }

        $number = $invoice->number;
        $this->move($invoice, 'voided', $actor, $request, $reason);
        $this->ledger->post($invoice->customer_id, 'reversal', '0.00', (string) $invoice->total, 'USD', (string) $invoice->exchange_rate, 'invoice', $invoice->id, $actor);
        $this->audit->record('billing.voided', $actor, ['invoice_id' => $invoice->id, 'number' => $number, 'customer_id' => $invoice->customer_id], $request);
    }

    public function run(?User $actor, Request $request): int
    {
        $count = 0;
        $today = now()->toDateString();
        Subscription::query()->where('status', 'active')->whereDate('next_invoice_at', '<=', $today)->orderBy('id')->each(function (Subscription $subscription) use ($actor, $request, &$count): void {
            $who = $actor ?? User::query()->where('role', 'super_admin')->first();

            if (! $who instanceof User) {
                return;
            }

            $before = Invoice::query()->where('subscription_id', $subscription->id)->where('type', 'invoice')->count();
            $start = $subscription->next_invoice_at?->toDateString() ?? $today;
            $end = Carbon::parse($start)->addMonth()->toDateString();
            $this->issue($subscription, $who, $request, null, $start, $end, $subscription->order_id);
            $subscription->current_period_start = Carbon::parse($start)->setTimeFromTimeString((string) $this->commercial->get('expiry_time'));
            $subscription->current_period_end = Carbon::parse($end)->setTimeFromTimeString((string) $this->commercial->get('expiry_time'));
            $subscription->next_invoice_at = $end;
            $subscription->save();

            if (Invoice::query()->where('subscription_id', $subscription->id)->where('type', 'invoice')->count() > $before) {
                $count++;
            }
        });

        return $count;
    }

    public function suspend(Subscription $subscription, string $reason, int $days, User $actor, Request $request): void
    {
        $max = (int) $this->commercial->get('hold_max_days');

        if ($reason === 'hold' && $days > $max) {
            throw ValidationException::withMessages(['days' => __('ui.billing.hold_limit')]);
        }

        $this->moveSubscription($subscription, 'suspended', $actor, $request);
    }

    public function terminate(Subscription $subscription, User $actor, Request $request): void
    {
        $order = $subscription->order_id ? Order::query()->find($subscription->order_id) : null;
        $returned = $order instanceof Order && (
            bccomp((string) $order->return_charge, '0', 2) === 1
            || Job::query()->where('order_id', $order->id)->where('type', 'removal')->exists()
        );

        if (! $returned) {
            throw ValidationException::withMessages(['status' => __('ui.billing.return_needed')]);
        }

        $this->moveSubscription($subscription, 'terminated', $actor, $request);
    }

    public function visibleInvoices(User $user): Builder
    {
        $query = Invoice::query()->whereNull('archived_at');

        if ($user->role === 'sales_agent' || $user->role === 'sales_manager') {
            return $query->whereHas('customer', fn ($customers) => $customers->where('owner_id', $user->id));
        }

        return $query;
    }

    private function move(Invoice $invoice, string $to, User $actor, Request $request, ?string $reason): void
    {
        $from = (string) $invoice->status;
        $this->invoices->assert($from, $to);
        $invoice->status = $to;

        if ($to === 'voided') {
            $invoice->voided_at = now();
            $invoice->void_reason = $reason;
        }

        $invoice->save();
        $this->event($invoice, $from, $to, $actor);
    }

    private function moveSubscription(Subscription $subscription, string $to, User $actor, Request $request): void
    {
        $from = (string) $subscription->status;
        $this->subscriptions->assert($from, $to);
        $subscription->status = $to;
        $subscription->updated_by = $actor->id;
        $subscription->save();
        $this->audit->record('billing.subscription_'.$to, $actor, [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'from' => $from,
        ], $request);
    }

    private function event(Invoice $invoice, ?string $from, string $to, User $actor): void
    {
        InvoiceEvent::query()->create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Carbon $from): array
    {
        $time = (string) $this->commercial->get('expiry_time');
        $start = $from->copy()->timezone((string) config('app.timezone'));
        $end = $start->copy()->addMonth()->setTimeFromTimeString($time);

        return [$start, $end];
    }

    private function currentRate(): string
    {
        $rate = ExchangeRate::query()->latest('id')->value('usd_lbp');

        if (is_string($rate) && $rate !== '') {
            return bcadd($rate, '0', 4);
        }

        return $this->commercial->rate();
    }
}
