<?php

namespace App\Services\Quotes;

use Illuminate\Validation\ValidationException;

class QuoteMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'draft' => ['pending_approval', 'approved'],
        'pending_approval' => ['approved', 'rejected', 'draft'],
        'approved' => ['sent', 'expired'],
        'sent' => ['accepted', 'rejected', 'expired'],
        'accepted' => [],
        'rejected' => [],
        'expired' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.quotes.illegal')]);
        }
    }
}
