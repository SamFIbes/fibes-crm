<?php

namespace App\Services\Qa;

use Illuminate\Validation\ValidationException;

class EvaluationMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['submitted'],
        'submitted' => ['acknowledged'],
        'acknowledged' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.qa.illegal')]);
        }
    }
}
