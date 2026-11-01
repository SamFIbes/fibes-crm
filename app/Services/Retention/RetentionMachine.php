<?php

namespace App\Services\Retention;

use Illuminate\Validation\ValidationException;

class RetentionMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'open' => ['offered', 'declined'],
        'offered' => ['accepted', 'declined'],
        'accepted' => ['saved'],
        'declined' => ['churned'],
        'saved' => [],
        'churned' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['stage' => __('ui.retention.illegal')]);
        }
    }
}
