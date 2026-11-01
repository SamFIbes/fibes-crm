<?php

namespace App\Services\Collections;

use Illuminate\Validation\ValidationException;

class CollectionMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'open' => ['reminded', 'promise', 'paid', 'suspended', 'written_off'],
        'reminded' => ['promise', 'broken', 'paid', 'suspended', 'written_off'],
        'promise' => ['paid', 'broken', 'suspended'],
        'broken' => ['promise', 'paid', 'suspended', 'terminated', 'written_off'],
        'suspended' => ['paid', 'terminated', 'written_off'],
        'paid' => [],
        'terminated' => [],
        'written_off' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['stage' => __('ui.collections.illegal')]);
        }
    }
}
