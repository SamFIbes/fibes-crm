<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Performance\CommissionDesk;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opportunity funnel by pipeline stage and the owner leaderboard for the dashboard.
 * Both follow the dashboard tile rules and the reports.view owner scope.
 */
class SalesFunnel
{
    public const LEADERS = 10;

    public function __construct(
        private ReportDesk $reports,
        private CommissionDesk $commissions,
    ) {}

    public function allows(User $user): bool
    {
        return $this->allowsFunnel($user) || $this->allowsLeaderboard($user);
    }

    public function allowsFunnel(User $user): bool
    {
        return $this->reports->allows($user, 'funnel');
    }

    public function allowsLeaderboard(User $user): bool
    {
        return $this->reports->allows($user, 'leaderboard');
    }

    /**
     * @return array{funnel: array<string, mixed>|null, leaderboard: array<string, mixed>|null}
     */
    public function build(User $user, string $from, string $to, ?int $pipelineId = null): array
    {
        [$from, $to] = $this->normalise($from, $to);
        $ids = $this->reports->ownerIds($user);

        return [
            'funnel' => $this->allowsFunnel($user) ? $this->funnel($ids, $from, $to, $pipelineId) : null,
            'leaderboard' => $this->allowsLeaderboard($user) ? $this->leaderboard($ids, $from, $to) : null,
        ];
    }

    /**
     * Opportunities opened in the period, followed through the stages of one pipeline.
     * "Reached" counts every deal that got to a stage or further; "now" counts where they sit today.
     *
     * @param  list<int>|null  $ids
     * @return array<string, mixed>
     */
    public function funnel(?array $ids, string $from, string $to, ?int $pipelineId = null): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $arabic = app()->getLocale() === 'ar';
        $pipelines = DB::table('pipelines')->orderBy('id')->get(['id', 'name_en', 'name_ar']);
        $cohort = $this->own(DB::table('opportunities')->whereBetween('created_at', [$start, $end]), $ids);

        if ($pipelineId === null || ! $pipelines->contains('id', $pipelineId)) {
            $busiest = (clone $cohort)->selectRaw('pipeline_id, count(*) as total')->groupBy('pipeline_id')->orderByDesc('total')->value('pipeline_id');
            $pipelineId = $busiest !== null ? (int) $busiest : ($pipelines->first() ? (int) $pipelines->first()->id : null);
        }

        $stages = DB::table('pipeline_stages')->where('pipeline_id', $pipelineId)->where('entity', 'opportunity')->orderBy('sort')->get();
        $deals = (clone $cohort)->where('pipeline_id', $pipelineId)->get(['id', 'stage_id', 'mrc', 'lost_at']);
        $sortOf = $stages->pluck('sort', 'id')->map(fn ($sort) => (int) $sort);
        $lostIds = $stages->where('is_lost', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $flow = $stages->filter(fn ($stage) => ! $stage->is_lost)->values();
        $firstSort = $flow->isNotEmpty() ? (int) $flow->first()->sort : 0;
        $visited = $this->visited($deals->pluck('id')->map(fn ($id) => (int) $id)->all());
        $furthest = [];
        $lost = 0;

        foreach ($deals as $deal) {
            $stageIds = array_merge($visited[(int) $deal->id] ?? [], [(int) $deal->stage_id]);
            $sorts = [];

            foreach ($stageIds as $stageId) {
                if (! in_array($stageId, $lostIds, true) && $sortOf->has($stageId)) {
                    $sorts[] = $sortOf->get($stageId);
                }
            }

            // A deal that went straight to lost still entered the pipeline at its first stage.
            $furthest[(int) $deal->id] = $sorts === [] ? $firstSort : max($sorts);

            if (in_array((int) $deal->stage_id, $lostIds, true) || $deal->lost_at !== null) {
                $lost++;
            }
        }

        $previous = null;
        $rows = [];

        foreach ($flow as $stage) {
            $sort = (int) $stage->sort;
            $reached = count(array_filter($furthest, fn (int $value) => $value >= $sort));
            $here = $deals->where('stage_id', $stage->id);
            $rows[] = [
                'id' => (int) $stage->id,
                'key' => (string) $stage->key,
                'label' => (string) ($arabic ? $stage->name_ar : $stage->name_en),
                'sort' => $sort,
                'is_won' => (bool) $stage->is_won,
                'reached' => $reached,
                'now' => $here->count(),
                'now_value' => $here->reduce(fn (string $carry, $deal) => bcadd($carry, (string) ($deal->mrc ?? '0'), 2), '0.00'),
                'conversion' => $previous === null ? null : $this->percent($reached, $previous),
            ];
            $previous = $reached;
        }

        $first = $rows[0]['reached'] ?? 0;
        $wonRow = collect($rows)->firstWhere('is_won', true);

        return [
            'pipeline_id' => $pipelineId,
            'pipelines' => $pipelines->map(fn ($pipeline) => ['id' => (int) $pipeline->id, 'label' => (string) ($arabic ? $pipeline->name_ar : $pipeline->name_en)])->values()->all(),
            'cohort' => $deals->count(),
            'lost' => $lost,
            'win_rate' => $wonRow === null ? null : $this->percent((int) $wonRow['reached'], $first),
            'stages' => $rows,
        ];
    }

    /**
     * Owners ranked by won deal value in the period, using the same deal events as /performance,
     * with their lost deals, win rate and open deals from the opportunities table.
     *
     * @param  list<int>|null  $ids
     * @return array{rows: list<array<string, mixed>>}
     */
    public function leaderboard(?array $ids, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $leaders = collect($this->commissions->leaderboardBetween($from, $to, null, $ids))->take(self::LEADERS);
        $owners = $leaders->pluck('user_id')->all();

        if ($owners === []) {
            return ['rows' => []];
        }

        $won = DB::table('opportunities')->whereIn('owner_id', $owners)->whereBetween('won_at', [$start, $end])
            ->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');
        $lost = DB::table('opportunities')->whereIn('owner_id', $owners)->whereBetween('lost_at', [$start, $end])
            ->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');
        $open = DB::table('opportunities')->whereIn('owner_id', $owners)->whereNull('won_at')->whereNull('lost_at')
            ->selectRaw('owner_id, count(*) as total, sum(mrc) as value')->groupBy('owner_id')->get()->keyBy('owner_id');

        return ['rows' => $leaders->map(function (array $row) use ($won, $lost, $open) {
            $wins = (int) ($won[$row['user_id']] ?? 0);
            $losses = (int) ($lost[$row['user_id']] ?? 0);
            $mine = $open->get($row['user_id']);

            return $row + [
                'lost' => $losses,
                'win_rate' => $wins + $losses > 0 ? $this->percent($wins, $wins + $losses) : null,
                'open' => (int) ($mine->total ?? 0),
                'open_value' => $this->money($mine->value ?? 0),
            ];
        })->values()->all()];
    }

    /**
     * @param  list<int>  $opportunityIds
     * @return array<int, list<int>>
     */
    private function visited(array $opportunityIds): array
    {
        $visited = [];

        foreach (array_chunk($opportunityIds, 500) as $chunk) {
            foreach (DB::table('opportunity_stage_history')->whereIn('opportunity_id', $chunk)->get(['opportunity_id', 'stage_id']) as $row) {
                $visited[(int) $row->opportunity_id][] = (int) $row->stage_id;
            }
        }

        return $visited;
    }

    private function percent(int $part, int $whole): ?float
    {
        if ($whole < 1) {
            return null;
        }

        return round(($part / $whole) * 100, 1);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalise(string $from, string $to): array
    {
        $valid = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
        $start = $valid($from) ? Carbon::parse($from) : now()->subDays(30);
        $finish = $valid($to) ? Carbon::parse($to) : now();

        if ($start->greaterThan($finish)) {
            [$start, $finish] = [$finish, $start];
        }

        return [$start->toDateString(), $finish->toDateString()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $from, string $to): array
    {
        return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function own(Builder $query, ?array $ids, string $column = 'owner_id'): Builder
    {
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($column, $ids);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: '0'), '0', 2);
    }
}
