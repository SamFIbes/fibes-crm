<?php

namespace App\Services\Approvals;

class ApprovalFlow
{
    /**
     * @var array<string, list<string>>
     */
    public const EDGES = [
        'draft' => ['pending'],
        'pending' => ['pending', 'approved', 'rejected', 'cancelled', 'escalated'],
        'escalated' => ['pending'],
        'expired' => ['escalated'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }
}
