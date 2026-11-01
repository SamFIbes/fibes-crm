<?php

namespace App\Services\Leads;

class LeadMachine
{
    /** @var list<string> */
    public const STATUSES = [
        'new', 'assigned', 'contacted', 'qualified', 'converted',
        'disqualified', 'unreachable', 'merged', 'waitlisted',
    ];

    /** @var array<string, list<string>> */
    public const EDGES = [
        'new' => ['assigned', 'disqualified', 'merged', 'waitlisted'],
        'assigned' => ['contacted', 'disqualified', 'merged', 'waitlisted'],
        'contacted' => ['qualified', 'disqualified', 'unreachable', 'waitlisted'],
        'qualified' => ['converted', 'disqualified', 'waitlisted'],
        'unreachable' => ['contacted'],
        'disqualified' => ['assigned'],
        'waitlisted' => ['assigned'],
        'converted' => [],
        'merged' => [],
    ];

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function next(string $from): array
    {
        return self::EDGES[$from] ?? [];
    }
}
