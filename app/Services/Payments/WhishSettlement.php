<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Integrations\IntegrationRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhishSettlement
{
    public function __construct(
        private PaymentDesk $payments,
        private IntegrationRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, applied: bool, status: int}
     */
    public function apply(array $payload, Request $request): array
    {
        $event = (string) ($payload['event'] ?? '');
        $provider = trim((string) ($payload['provider_id'] ?? ''));
        $checkout = trim((string) ($payload['checkout_id'] ?? ''));
        $amount = $this->money($payload['amount'] ?? null);

        if ($event !== 'payment.succeeded' || $provider === '' || $checkout === '' || $amount === null) {
            $this->recorder->attempt('whish', 'webhook', 'failed', 'ignored', null, [
                'event' => $event !== '' ? $event : null,
                'checkout_id' => $checkout !== '' ? $checkout : null,
            ], $request);

            return ['ok' => true, 'applied' => false, 'status' => 202];
        }

        $key = 'whish:'.$provider;
        $done = Payment::query()->where('idempotency_key', $key)->where('status', 'confirmed')->first();

        if ($done instanceof Payment) {
            $this->recorder->attempt('whish', 'webhook', 'succeeded', 'Already applied.', null, [
                'payment_id' => $done->id,
                'checkout_id' => $checkout,
            ], $request);

            return ['ok' => true, 'applied' => false, 'status' => 200];
        }

        $payment = Payment::query()->where('whish_checkout_id', $checkout)->first();

        if (! $payment instanceof Payment && filled($payload['reference'] ?? null)) {
            $payment = Payment::query()
                ->where('method', 'whish')
                ->where('reference', (string) $payload['reference'])
                ->where('status', 'pending')
                ->first();
        }

        if (! $payment instanceof Payment && filled($payload['invoice_number'] ?? null)) {
            $invoice = Invoice::query()->where('number', (string) $payload['invoice_number'])->where('type', 'invoice')->first();
            $actor = $invoice instanceof Invoice ? $this->actor($invoice->created_by) : null;

            if ($invoice instanceof Invoice && $actor instanceof User && in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
                $payment = $this->payments->openWhish($invoice, $checkout, '', $actor);
            }
        }

        if (! $payment instanceof Payment) {
            $this->recorder->attempt('whish', 'webhook', 'failed', 'No matching payment.', null, [
                'checkout_id' => $checkout,
            ], $request);

            return ['ok' => true, 'applied' => false, 'status' => 202];
        }

        if ($payment->status === 'confirmed') {
            return ['ok' => true, 'applied' => false, 'status' => 200];
        }

        $currency = ($payload['currency'] ?? 'USD') === 'LBP' ? 'LBP' : 'USD';
        $expected = $currency === $payment->currency
            ? bcadd((string) $payment->tender_amount, '0', 2)
            : bcadd((string) $payment->amount, '0', 2);

        if ($currency !== 'USD' && $currency !== $payment->currency) {
            $expected = 'mismatch';
        }

        if ($expected === 'mismatch' || bccomp($expected, $amount, 2) !== 0) {
            $this->recorder->attempt('whish', 'webhook', 'failed', 'Amount does not match.', null, [
                'payment_id' => $payment->id,
                'checkout_id' => $checkout,
            ], $request);

            return ['ok' => false, 'applied' => false, 'status' => 422];
        }

        $actor = $this->actor($payment->created_by);

        if (! $actor instanceof User) {
            $this->recorder->attempt('whish', 'webhook', 'failed', 'No actor for the ledger.', null, [
                'payment_id' => $payment->id,
            ], $request);

            return ['ok' => false, 'applied' => false, 'status' => 422];
        }

        try {
            DB::transaction(function () use ($payment, $key, $provider, $checkout, $actor, $request): void {
                $payment->idempotency_key = mb_substr($key, 0, 180);
                if (! filled($payment->reference)) {
                    $payment->reference = mb_substr($provider, 0, 120);
                }
                if (! filled($payment->whish_checkout_id)) {
                    $payment->whish_checkout_id = mb_substr($checkout, 0, 120);
                }
                $payment->save();
                $this->payments->confirm($payment->fresh() ?? $payment, $actor, $request);
            });
        } catch (UniqueConstraintViolationException) {
            return ['ok' => true, 'applied' => false, 'status' => 200];
        }

        $this->recorder->attempt('whish', 'webhook', 'succeeded', 'Invoice marked paid.', $actor, [
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'checkout_id' => $checkout,
        ], $request);

        return ['ok' => true, 'applied' => true, 'status' => 200];
    }

    private function money(mixed $amount): ?string
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        if (! is_numeric((string) $amount)) {
            return null;
        }

        return bcadd((string) $amount, '0', 2);
    }

    private function actor(mixed $id): ?User
    {
        $user = is_numeric($id) ? User::query()->find((int) $id) : null;

        if ($user instanceof User) {
            return $user;
        }

        $admin = User::query()->where('role', 'super_admin')->where('is_active', true)->first();

        return $admin instanceof User ? $admin : null;
    }
}
