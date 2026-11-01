<?php

namespace App\Services\Opportunities;

class OpportunityFlow
{
    /** @var array<string, array<string, list<string>>> */
    public const EDGES = [
        'retail' => [
            'feasibility' => ['site_survey', 'lost'],
            'site_survey' => ['proposal', 'lost'],
            'proposal' => ['negotiation', 'lost'],
            'negotiation' => ['won', 'lost'],
            'won' => [],
            'lost' => [],
        ],
        'business' => [
            'feasibility' => ['site_survey', 'lost'],
            'site_survey' => ['proposal', 'lost'],
            'proposal' => ['negotiation', 'lost'],
            'negotiation' => ['won', 'lost'],
            'won' => [],
            'lost' => [],
        ],
        'reseller' => [
            'qualified' => ['due_diligence', 'lost'],
            'due_diligence' => ['proposal', 'lost'],
            'proposal' => ['agreement', 'lost'],
            'agreement' => ['won', 'lost'],
            'won' => [],
            'lost' => [],
        ],
    ];

    public function allows(string $pipeline, string $from, string $to, bool $skipSurvey = false): bool
    {
        $edges = self::EDGES[$pipeline][$from] ?? [];

        if ($skipSurvey && $pipeline !== 'reseller' && $from === 'feasibility' && $to === 'proposal') {
            return true;
        }

        return in_array($to, $edges, true);
    }

    /**
     * @return list<string>
     */
    public function stages(string $pipeline): array
    {
        return array_keys(self::EDGES[$pipeline] ?? []);
    }
}
