<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Access\AccessGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Campaign results for the dashboard: leads created in the period per campaign, and the
 * opportunities, wins and won value those leads turned into so far. Follows the reports.view owner scope.
 */
class CampaignResults
{
    public const TOP = 12;

    public function __construct(private ReportDesk $reports, private AccessGate $access) {}

    /**
     * Shown to anyone allowed to see the campaign tile.
     */
    public function allows(User $user): bool
    {
        return $this->reports->allows($user, 'campaign_roi');
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $from, string $to): array
    {
        [$from, $to] = $this->normalise($from, $to);
        $leads = $this->leads($this->reports->ownerIds($user), $from, $to);
        $outcomes = $this->outcomes($leads);

        $byCampaign = [];
        $uncampaigned = ['leads' => 0, 'won' => 0];

        foreach ($leads as $lead) {
            $outcome = $outcomes[$lead['id']] ?? null;

            if ($lead['campaign_id'] === null) {
                $uncampaigned['leads']++;
                $uncampaigned['won'] += $outcome !== null && $outcome['won'] ? 1 : 0;

                continue;
            }

            $row = $byCampaign[$lead['campaign_id']] ?? ['leads' => 0, 'opportunities' => 0, 'won' => 0, 'won_value' => '0'];
            $row['leads']++;

            if ($outcome !== null) {
                $row['opportunities']++;

                if ($outcome['won']) {
                    $row['won']++;
                    $row['won_value'] = bcadd($row['won_value'], $outcome['mrc'], 2);
                }
            }

            $byCampaign[$lead['campaign_id']] = $row;
        }

        $campaigns = DB::table('campaigns')->whereIn('id', array_keys($byCampaign))->get()->keyBy('id');
        $rows = [];
        $totals = ['leads' => 0, 'opportunities' => 0, 'won' => 0, 'won_value' => '0'];

        foreach ($byCampaign as $id => $row) {
            $campaign = $campaigns[$id] ?? null;

            if ($campaign === null) {
                continue;
            }

            foreach (['leads', 'opportunities', 'won'] as $key) {
                $totals[$key] += $row[$key];
            }
            $totals['won_value'] = bcadd($totals['won_value'], $row['won_value'], 2);
            $budget = $campaign->budget === null ? null : $this->money($campaign->budget);

            $rows[] = [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'channel' => $campaign->channel,
                'starts_on' => $this->day($campaign->starts_on),
                'ends_on' => $this->day($campaign->ends_on),
                'budget' => $budget,
                'currency' => $campaign->currency,
                'leads' => $row['leads'],
                'opportunities' => $row['opportunities'],
                'won' => $row['won'],
                'won_value' => $this->money($row['won_value']),
                'lead_to_opportunity' => $this->rate($row['opportunities'], $row['leads']),
                'lead_to_won' => $this->rate($row['won'], $row['leads']),
                'cost_per_lead' => $this->per($budget, $row['leads']),
                'cost_per_won' => $this->per($budget, $row['won']),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['won'], $b['leads'], $a['name']] <=> [$a['won'], $a['leads'], $b['name']]);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => array_slice($rows, 0, self::TOP),
            'totals' => [
                'leads' => $totals['leads'],
                'opportunities' => $totals['opportunities'],
                'won' => $totals['won'],
                'won_value' => $this->money($totals['won_value']),
                'lead_to_opportunity' => $this->rate($totals['opportunities'], $totals['leads']),
                'lead_to_won' => $this->rate($totals['won'], $totals['leads']),
            ],
            'uncampaigned' => [
                'leads' => $uncampaigned['leads'],
                'lead_to_won' => $this->rate($uncampaigned['won'], $uncampaigned['leads']),
            ],
            'manage_href' => $this->access->allows($user, 'leads.assign') ? '/leads' : null,
        ];
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<array{id: int, campaign_id: int|null, converted_opportunity_id: int|null}>
     */
    private function leads(?array $ids, string $from, string $to): array
    {
        $query = DB::table('leads')
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->select(['id', 'campaign_id', 'converted_opportunity_id']);

        if ($ids !== null) {
            $ids === [] ? $query->whereRaw('0 = 1') : $query->whereIn('owner_id', $ids);
        }

        return $query->get()->map(fn (object $lead) => [
            'id' => (int) $lead->id,
            'campaign_id' => $lead->campaign_id === null ? null : (int) $lead->campaign_id,
            'converted_opportunity_id' => $lead->converted_opportunity_id === null ? null : (int) $lead->converted_opportunity_id,
        ])->all();
    }

    /**
     * The best outcome per lead: won beats open or lost. Linked by opportunities.lead_id or the lead's converted opportunity.
     *
     * @param  list<array{id: int, campaign_id: int|null, converted_opportunity_id: int|null}>  $leads
     * @return array<int, array{won: bool, mrc: string}>
     */
    private function outcomes(array $leads): array
    {
        $outcomes = [];
        $converted = [];

        foreach ($leads as $lead) {
            if ($lead['converted_opportunity_id'] !== null) {
                $converted[$lead['converted_opportunity_id']] = $lead['id'];
            }
        }

        foreach (array_chunk(array_column($leads, 'id'), 500) as $chunk) {
            foreach (DB::table('opportunities')->whereIn('lead_id', $chunk)->get(['id', 'lead_id', 'won_at', 'mrc']) as $opportunity) {
                $this->keep($outcomes, (int) $opportunity->lead_id, $opportunity);
                unset($converted[(int) $opportunity->id]);
            }
        }

        foreach (array_chunk(array_keys($converted), 500, true) as $chunk) {
            foreach (DB::table('opportunities')->whereIn('id', $chunk)->get(['id', 'won_at', 'mrc']) as $opportunity) {
                $this->keep($outcomes, $converted[(int) $opportunity->id], $opportunity);
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array{won: bool, mrc: string}>  $outcomes
     */
    private function keep(array &$outcomes, int $leadId, object $opportunity): void
    {
        $won = $opportunity->won_at !== null;
        $current = $outcomes[$leadId] ?? null;

        if ($current === null || ($won && ! $current['won'])) {
            $outcomes[$leadId] = ['won' => $won, 'mrc' => $won ? $this->money($opportunity->mrc) : '0.00'];
        }
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }

    private function per(?string $budget, int $count): ?string
    {
        return $budget === null || $count === 0 ? null : bcdiv($budget, (string) $count, 2);
    }

    private function day(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toDateString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalise(string $from, string $to): array
    {
        $start = $this->date($from) ?? now()->subDays(30);
        $end = $this->date($to) ?? now();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function date(string $value): ?Carbon
    {
        try {
            return $value === '' ? null : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: '0'), '0', 2);
    }
}
