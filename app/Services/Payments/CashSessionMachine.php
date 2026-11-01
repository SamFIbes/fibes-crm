<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

class CashSessionMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'open' => ['pending_close', 'closed'],
        'pending_close' => ['closed'],
        'closed' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.payments.illegal')]);
        }
    }
}
