<?php

namespace App\Services\Billing;

use Illuminate\Validation\ValidationException;

class SubscriptionMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'pending_install' => ['active', 'terminated'],
        'active' => ['grace', 'expired', 'suspended', 'pending_change', 'terminated'],
        'grace' => ['active', 'suspended', 'terminated'],
        'expired' => ['active', 'suspended', 'terminated'],
        'suspended' => ['active', 'terminated'],
        'pending_change' => ['active', 'terminated'],
        'terminated' => [],
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
