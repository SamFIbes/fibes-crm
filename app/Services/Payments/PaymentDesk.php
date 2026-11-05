<?php

namespace App\Services\Payments;

use App\Mail\OrderNotice;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Automations\AutomationDesk;
use App\Services\Billing\InvoiceMachine;
use App\Services\Billing\InvoiceNumbers;
use App\Services\Billing\Ledger;
use App\Services\Billing\MoneyMath;
use App\Services\Collections\CollectionsDesk;
use App\Services\Integrations\WhishClient;
use App\Services\Opportunities\OpportunityMoney;
use App\Services\Performance\CommissionDesk;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PaymentDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private MoneyMath $money,
        private InvoiceNumbers $numbers,
        private Ledger $ledger,
        private InvoiceMachine $invoices,
        private PaymentMachine $payments,
        private CashSessionMachine $sessions,
        private AuditLogger $audit,
        private OpportunityMoney $lbp,
        private WhishClient $whish,
        private AccessGate $access,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(User $actor, array $input, Request $request, string $kind = 'desk'): Payment
    {
        $key = $request->header('Idempotency-Key') ?: ($input['idempotency_key'] ?? null);
        $key = is_string($key) && $key !== '' ? $key : null;

        if ($key !== null) {
            $existing = Payment::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof Payment) {
                return $existing;
            }
        }

        $method = (string) $input['method'];
        $currency = ($input['currency'] ?? 'USD') === 'LBP' ? 'LBP' : 'USD';
        $rate = $this->currentRate();
        $tender = $currency === 'LBP'
            ? $this->roundLbp((string) $input['amount'])
            : bcadd((string) $input['amount'], '0', 2);
        $usd = $currency === 'LBP'
            ? $this->money->round(bcdiv($tender, $rate, 6))
            : $tender;
        $immediate = $this->settlesNow($actor, $method);

        if ($method === 'cash') {
            $this->assertCash($actor, $request, isset($input['password']) ? (string) $input['password'] : null, $kind);
        }

        $year = (int) now()->year;
        $number = $this->numbers->format('RC', $year, $this->numbers->next('RC', $year));
        $session = null;

        if ($method === 'cash' && $immediate) {
            $session = $this->ensureSession($actor, $kind);
        }

        $payment = Payment::query()->create([
            'number' => $number,
            'customer_id' => (int) $input['customer_id'],
            'cash_session_id' => $session?->id,
            'amount' => $usd,
            'tender_amount' => bcadd($tender, '0', 2),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'method' => $method,
            'reference' => $input['reference'] ?? null,
            'idempotency_key' => $key,
            'received_by' => $actor->id,
            'received_at' => now(),
            'status' => $immediate ? 'confirmed' : 'pending',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->event($payment, null, (string) $payment->status, $actor);

        if ($method === 'whish' && config('fibes.whish.enabled') && ! $this->whish->ready()) {
            $this->whish->charge((string) ($payment->reference ?: $payment->number), (string) $payment->tender_amount, $currency);
        }

        if ($immediate) {
            if ($session instanceof CashSession) {
                $this->addExpected($session, $usd);
            }

            $this->commit($payment, $actor, $request, isset($input['invoice_id']) ? (int) $input['invoice_id'] : null);
        }

        $this->audit->record('payment.recorded', $actor, [
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'number' => $payment->number,
            'amount' => (string) $payment->amount,
            'currency' => $currency,
            'method' => $method,
            'status' => $payment->status,
            'rate' => $rate,
        ], $request);

        return $payment;
    }

    public function confirm(Payment $payment, User $actor, Request $request): Payment
    {
        $this->payments->assert((string) $payment->status, 'confirmed');
        $from = (string) $payment->status;

        if ($payment->method === 'cash') {
            $this->assertCash($actor, $request, $request->input('password'), 'desk');
            $session = $this->ensureSession($actor, 'desk');
            $payment->cash_session_id = $session->id;
            $this->addExpected($session, (string) $payment->amount);
        }

        $payment->status = 'confirmed';
        $payment->updated_by = $actor->id;
        $payment->save();
        $this->event($payment, $from, 'confirmed', $actor);
        $this->commit($payment, $actor, $request, $payment->target_invoice_id ? (int) $payment->target_invoice_id : null);
        $this->audit->record('payment.confirmed', $actor, [
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'number' => $payment->number,
            'amount' => (string) $payment->amount,
        ], $request);

        return $payment;
    }

    public function requestReverse(Payment $payment, User $actor, Request $request): void
    {
        $this->payments->assert((string) $payment->status, 'reversed');
        $approval = app(ApprovalDesk::class)->open($actor, 'payment_reversal', 'payment', $payment->id, [
            'amount' => (string) $payment->amount,
            'customer_id' => $payment->customer_id,
        ], (string) $payment->number, $request);
        $payment->approval_id = $approval->id;
        $payment->save();
        $this->audit->record('payment.reverse_requested', $actor, [
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'amount' => (string) $payment->amount,
        ], $request);
    }

    public function applyReversal(int $paymentId, User $actor, ?Request $request): void
    {
        $request ??= Request::create('/');
        $payment = Payment::query()->find($paymentId);

        if (! $payment instanceof Payment || $payment->status !== 'confirmed') {
            return;
        }

        $from = (string) $payment->status;
        $payment->status = 'reversed';
        $payment->updated_by = $actor->id;
        $payment->save();
        $this->event($payment, $from, 'reversed', $actor);
        $this->ledger->post($payment->customer_id, 'reversal', (string) $payment->amount, '0.00', 'USD', (string) $payment->exchange_rate, 'payment', $payment->id, $actor);

        foreach ($payment->allocations()->pluck('invoice_id') as $invoiceId) {
            $invoice = Invoice::query()->find($invoiceId);

            if ($invoice instanceof Invoice) {
                $this->refreshInvoice($invoice, $actor);
            }
        }

        $this->audit->record('payment.reversed', $actor, [
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'amount' => (string) $payment->amount,
        ], $request);
    }

    public function unlock(User $actor, string $password, Request $request): void
    {
        $this->assertCash($actor, $request, $password, 'field');
        $this->audit->record('payment.cash_unlocked', $actor, [], $request);
    }

    public function close(CashSession $session, string $counted, User $actor, Request $request): void
    {
        if ((int) $session->user_id !== (int) $actor->id && ! $this->access->allows($actor, 'payments.record')) {
            throw ValidationException::withMessages(['status' => __('ui.payments.illegal')]);
        }

        $this->assertCash($actor, $request, $request->input('password'), (string) $session->kind);
        $counted = bcadd($counted, '0', 2);
        $variance = bcsub($counted, (string) $session->expected, 2);
        $session->counted = $counted;
        $session->variance = $variance;
        $limit = $this->commercial->money('cash_variance');
        $gap = ltrim($variance, '-');

        if (bccomp($gap, $limit, 2) === 1) {
            $this->sessions->assert((string) $session->status, 'pending_close');
            $from = (string) $session->status;
            $session->status = 'pending_close';
            $session->updated_by = $actor->id;
            $session->save();
            $this->sessionEvent($session, $from, 'pending_close', $actor);
            $approval = app(ApprovalDesk::class)->open($actor, 'cash_variance', 'cash_session', $session->id, [
                'amount' => bcadd($gap, '0', 2),
            ], 'Cash '.$session->id, $request);
            $session->approval_id = $approval->id;
            $session->save();
            $this->audit->record('cash.variance_requested', $actor, [
                'cash_session_id' => $session->id,
                'variance' => $variance,
            ], $request);

            throw ValidationException::withMessages(['counted' => __('ui.payments.variance')]);
        }

        $this->finishClose($session, $actor, $request, null);
    }

    public function applyClose(int $sessionId, User $actor, ?Request $request): void
    {
        $request ??= Request::create('/');
        $session = CashSession::query()->find($sessionId);

        if ($session instanceof CashSession && $session->status !== 'closed') {
            $this->finishClose($session, $actor, $request, $actor->id);
        }
    }

    public function applyCredit(Invoice $invoice, User $actor): void
    {
        $payments = Payment::query()->where('customer_id', $invoice->customer_id)->where('status', 'confirmed')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $this->allocate($payment, $invoice->id, $invoice->id, $actor);
        }
    }

    public function openWhish(Invoice $invoice, string $checkoutId, string $url, User $actor): Payment
    {
        $existing = Payment::query()->where('idempotency_key', 'whish-checkout:'.$checkoutId)->first();

        if ($existing instanceof Payment) {
            return $existing;
        }

        $year = (int) now()->year;
        $payment = Payment::query()->create([
            'number' => $this->numbers->format('RC', $year, $this->numbers->next('RC', $year)),
            'customer_id' => $invoice->customer_id,
            'amount' => bcadd((string) $invoice->total, '0', 2),
            'tender_amount' => bcadd((string) $invoice->total, '0', 2),
            'currency' => 'USD',
            'exchange_rate' => bcadd((string) ($invoice->exchange_rate ?: '1'), '0', 4),
            'method' => 'whish',
            'idempotency_key' => 'whish-checkout:'.$checkoutId,
            'whish_checkout_id' => $checkoutId,
            'whish_url' => $url !== '' ? $url : null,
            'target_invoice_id' => $invoice->id,
            'received_by' => $actor->id,
            'received_at' => now(),
            'status' => 'pending',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->event($payment, null, 'pending', $actor);
        $invoice->whish_checkout_id = $checkoutId;
        $invoice->whish_url = $url !== '' ? $url : null;
        $invoice->save();

        return $payment;
    }

    public function whatsapp(Payment $payment): ?string
    {
        $customer = $payment->customer;

        if (! $customer instanceof Customer || ! $customer->whatsapp_consent || ! $customer->whatsapp_e164) {
            return null;
        }

        $text = rawurlencode(__('ui.payments.whatsapp_text', [
            'number' => $payment->number,
            'amount' => $payment->amount,
        ]));

        return 'https://wa.me/'.ltrim((string) $customer->whatsapp_e164, '+').'?text='.$text;
    }

    /**
     * @return list<LedgerEntry>
     */
    public function statement(Customer $customer, ?string $from, ?string $to): array
    {
        $query = LedgerEntry::query()->where('customer_id', $customer->id)->orderBy('id');

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->get()->all();
    }

    public function referenceBalance(int $customerId): string
    {
        $documents = '0.00';

        foreach (Invoice::query()->where('customer_id', $customerId)->whereNotIn('status', ['voided', 'draft'])->get() as $invoice) {
            if ($invoice->type === 'credit_note') {
                $documents = bcsub($documents, (string) $invoice->total, 2);
            }

            if ($invoice->type === 'invoice') {
                $documents = bcadd($documents, (string) $invoice->total, 2);
            }
        }

        $paid = '0.00';

        foreach (Payment::query()->where('customer_id', $customerId)->where('status', 'confirmed')->get() as $payment) {
            $paid = bcadd($paid, (string) $payment->amount, 2);
        }

        return bcsub($documents, $paid, 2);
    }

    private function commit(Payment $payment, User $actor, Request $request, ?int $preferredInvoice): void
    {
        $this->ledger->post($payment->customer_id, 'payment', '0.00', (string) $payment->amount, 'USD', (string) $payment->exchange_rate, 'payment', $payment->id, $actor);
        $this->allocate($payment, $preferredInvoice, null, $actor);
        $this->notify($payment);
        app(CollectionsDesk::class)->onPayment($payment->customer_id, $actor);
        app(CommissionDesk::class)->onPayment($payment);
        app(AutomationDesk::class)->emit('payment.received', [
            'subject_type' => 'payment', 'subject_id' => $payment->id, 'customer_id' => $payment->customer_id, 'label' => (string) $payment->number,
        ]);
    }

    private function allocate(Payment $payment, ?int $preferred, ?int $only, User $actor): void
    {
        $left = $this->unallocated($payment);

        if (bccomp($left, '0', 2) !== 1) {
            return;
        }

        $query = Invoice::query()->where('customer_id', $payment->customer_id)->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'overdue', 'paid'])->orderBy('issued_at')->orderBy('id');

        if ($only) {
            $query->whereKey($only);
        }

        $rows = $query->get();

        if ($preferred) {
            $rows = $rows->where('id', $preferred)->concat($rows->where('id', '!=', $preferred))->values();
        }

        foreach ($rows as $invoice) {
            if (bccomp($left, '0', 2) !== 1) {
                break;
            }

            $room = bcsub((string) $invoice->total, $this->covered($invoice), 2);

            if (bccomp($room, '0', 2) !== 1) {
                continue;
            }

            $take = bccomp($left, $room, 2) === 1 ? $room : $left;

            if (PaymentAllocation::query()->where('payment_id', $payment->id)->where('invoice_id', $invoice->id)->exists()) {
                continue;
            }

            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $take,
            ]);
            $left = bcsub($left, $take, 2);
            $payment->unsetRelation('allocations');
            $this->refreshInvoice($invoice->fresh() ?? $invoice, $actor);
        }
    }

    private function refreshInvoice(Invoice $invoice, User $actor): void
    {
        if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue', 'paid'], true)) {
            return;
        }

        $paid = $this->covered($invoice);
        $to = 'issued';

        if (bccomp($paid, (string) $invoice->total, 2) >= 0 && bccomp($paid, '0', 2) === 1) {
            $to = 'paid';
        } elseif (bccomp($paid, '0', 2) === 1) {
            $to = 'partially_paid';
        }

        if ($to === $invoice->status || ! $this->invoices->allows((string) $invoice->status, $to)) {
            return;
        }

        $from = (string) $invoice->status;
        $invoice->status = $to;
        $invoice->save();
        InvoiceEvent::query()->create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
        $this->audit->record('billing.invoice_'.$to, $actor, [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'from' => $from,
        ], Request::create('/'));
    }

    private function covered(Invoice $invoice): string
    {
        $sum = '0.00';

        foreach (PaymentAllocation::query()->where('invoice_id', $invoice->id)->whereHas('payment', fn ($query) => $query->where('status', 'confirmed'))->get() as $row) {
            $sum = bcadd($sum, (string) $row->amount, 2);
        }

        return $sum;
    }

    private function unallocated(Payment $payment): string
    {
        $sum = '0.00';
        $payment->load('allocations');

        foreach ($payment->allocations as $row) {
            $sum = bcadd($sum, (string) $row->amount, 2);
        }

        return bcsub((string) $payment->amount, $sum, 2);
    }

    private function ensureSession(User $actor, string $kind): CashSession
    {
        $open = CashSession::query()->where('user_id', $actor->id)->where('kind', $kind)->where('status', 'open')->first();

        if ($open instanceof CashSession) {
            return $open;
        }

        $opening = $kind === 'desk' ? $this->commercial->money('desk_opening') : '0.00';
        $session = CashSession::query()->create([
            'user_id' => $actor->id,
            'kind' => $kind,
            'status' => 'open',
            'opened_at' => now(),
            'opening' => $opening,
            'expected' => $opening,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->sessionEvent($session, null, 'open', $actor);
        $this->audit->record('cash.opened', $actor, ['cash_session_id' => $session->id, 'kind' => $kind, 'opening' => $opening], Request::create('/'));

        return $session;
    }

    private function addExpected(CashSession $session, string $usd): void
    {
        $session->expected = bcadd((string) $session->expected, $usd, 2);
        $session->save();
    }

    private function finishClose(CashSession $session, User $actor, Request $request, ?int $approvedBy): void
    {
        $from = (string) $session->status;
        $this->sessions->assert($from, 'closed');
        $session->status = 'closed';
        $session->closed_at = now();
        $session->approved_by = $approvedBy;
        $session->updated_by = $actor->id;
        $session->save();
        $this->sessionEvent($session, $from, 'closed', $actor);
        $this->audit->record('cash.closed', $actor, [
            'cash_session_id' => $session->id,
            'expected' => (string) $session->expected,
            'counted' => (string) $session->counted,
            'variance' => (string) $session->variance,
        ], $request);
    }

    private function assertCash(User $actor, Request $request, ?string $password, string $kind): void
    {
        if ($kind === 'field' && ! $this->commercial->get('field_cash')) {
            throw ValidationException::withMessages(['method' => __('ui.payments.field_off')]);
        }

        if (is_string($password) && $password !== '' && Hash::check($password, $actor->password)) {
            if ($request->hasSession()) {
                $request->session()->put('cash_unlocked_at', now()->toIso8601String());
            }

            return;
        }

        $stamp = $request->hasSession() ? $request->session()->get('cash_unlocked_at') : null;
        $minutes = max(1, (int) $this->commercial->get('cash_unlock_minutes'));

        if (is_string($stamp) && Carbon::parse($stamp)->greaterThan(now()->subMinutes($minutes))) {
            return;
        }

        throw ValidationException::withMessages(['password' => __('ui.payments.password')]);
    }

    private function settlesNow(User $actor, string $method): bool
    {
        if (in_array($method, ['whish', 'bank_transfer'], true)) {
            return false;
        }

        return $this->access->allows($actor, 'payments.record') || $this->access->allows($actor, 'payments.collect');
    }

    private function notify(Payment $payment): void
    {
        $payment->loadMissing('customer');
        $email = $payment->customer?->email;

        if (is_string($email) && $email !== '') {
            Mail::to($email)->send(new OrderNotice(
                __('ui.payments.receipt_mail', ['number' => $payment->number, 'amount' => $payment->amount]),
                null,
            ));
        }
    }

    private function event(Payment $payment, ?string $from, string $to, User $actor): void
    {
        PaymentEvent::query()->create([
            'payment_id' => $payment->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
    }

    private function sessionEvent(CashSession $session, ?string $from, string $to, User $actor): void
    {
        CashSessionEvent::query()->create([
            'cash_session_id' => $session->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
    }

    private function currentRate(): string
    {
        $rate = ExchangeRate::query()->latest('id')->value('usd_lbp');

        if (is_string($rate) && $rate !== '') {
            return bcadd($rate, '0', 4);
        }

        return $this->commercial->rate();
    }

    private function roundLbp(string $amount): string
    {
        $negative = bccomp($amount, '0', 2) < 0;
        $absolute = ltrim($amount, '-');
        $thousands = bcdiv(bcadd($absolute, '500', 2), '1000', 0);

        return bcadd(($negative ? '-' : '').bcmul($thousands, '1000', 0), '0', 2);
    }
}
