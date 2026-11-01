<?php

namespace App\Services\Automations;

use Illuminate\Validation\ValidationException;

class DeliveryMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'pending' => ['delivered', 'retrying', 'dead'],
        'retrying' => ['delivered', 'retrying', 'dead'],
        'delivered' => [],
        'dead' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.automations.illegal')]);
        }
    }
}
