<?php

namespace App\Services\Orders;

use Illuminate\Validation\ValidationException;

class OrderMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['submitted', 'pending_approval', 'cancelled'],
        'pending_approval' => ['submitted', 'cancelled'],
        'submitted' => ['credit_check', 'on_hold', 'cancelled'],
        'credit_check' => ['ready_to_schedule', 'on_hold', 'cancelled'],
        'ready_to_schedule' => ['scheduled', 'on_hold', 'cancelled'],
        'scheduled' => ['in_progress', 'on_hold', 'cancelled'],
        'in_progress' => ['installed', 'failed_install', 'on_hold'],
        'installed' => ['activated', 'on_hold'],
        'activated' => ['completed'],
        'failed_install' => ['ready_to_schedule', 'cancelled'],
        'on_hold' => ['submitted', 'credit_check', 'ready_to_schedule', 'scheduled', 'in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /** @var list<string> */
    public const PATH = [
        'submitted', 'credit_check', 'ready_to_schedule', 'scheduled', 'in_progress', 'installed', 'activated', 'completed',
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.orders.illegal')]);
        }
    }
}
