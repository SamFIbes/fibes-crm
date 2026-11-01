<?php

namespace App\Services\Billing;

use Illuminate\Validation\ValidationException;

class InvoiceMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['issued', 'voided'],
        'issued' => ['partially_paid', 'paid', 'overdue', 'written_off', 'voided'],
        'partially_paid' => ['paid', 'issued', 'written_off'],
        'overdue' => ['partially_paid', 'paid', 'written_off', 'voided'],
        'paid' => ['partially_paid', 'issued'],
        'written_off' => [],
        'voided' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.billing.illegal')]);
        }
    }
}
