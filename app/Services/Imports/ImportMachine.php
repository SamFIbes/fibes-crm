<?php

namespace App\Services\Imports;

use Illuminate\Validation\ValidationException;

class ImportMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['applied'],
        'applied' => ['undone'],
        'undone' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.imports.illegal')]);
        }
    }
}
