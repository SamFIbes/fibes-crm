<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Ledger
{
    public function post(
        int $customerId,
        string $type,
        string $debit,
        string $credit,
        string $currency,
        string $rate,
        ?string $sourceType,
        ?int $sourceId,
        ?User $actor,
    ): LedgerEntry {
        return DB::transaction(function () use ($customerId, $type, $debit, $credit, $currency, $rate, $sourceType, $sourceId, $actor) {
            $last = LedgerEntry::query()->where('customer_id', $customerId)->orderByDesc('id')->lockForUpdate()->first();
            $previous = $last instanceof LedgerEntry ? (string) $last->balance_after : '0.00';
            $balance = bcsub(bcadd($previous, bcadd($debit, '0', 2), 2), bcadd($credit, '0', 2), 2);

            return LedgerEntry::query()->create([
                'customer_id' => $customerId,
                'entry_type' => $type,
                'debit' => bcadd($debit, '0', 2),
                'credit' => bcadd($credit, '0', 2),
                'currency' => $currency,
                'exchange_rate' => bcadd($rate, '0', 4),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'balance_after' => $balance,
                'actor_id' => $actor?->id,
            ]);
        });
    }

    public function balance(int $customerId): string
    {
        $last = LedgerEntry::query()->where('customer_id', $customerId)->orderByDesc('id')->value('balance_after');

        return is_string($last) ? bcadd($last, '0', 2) : '0.00';
    }

    public function documents(int $customerId): string
    {
        $invoices = '0.00';
        $credits = '0.00';

        foreach (Invoice::query()->where('customer_id', $customerId)->where('status', '!=', 'voided')->where('status', '!=', 'draft')->get() as $invoice) {
            if ($invoice->type === 'credit_note') {
                $credits = bcadd($credits, (string) $invoice->total, 2);
            }

            if ($invoice->type === 'invoice') {
                $invoices = bcadd($invoices, (string) $invoice->total, 2);
            }
        }

        return bcsub($invoices, $credits, 2);
    }
}
