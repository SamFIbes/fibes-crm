<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales charts and period KPI cards for the dashboard, built from leads and opportunities.
 * Every query follows the same reports.view owner scope as the dashboard tiles.
 */
class SalesCharts
{
    public const WEEKS = 13;

    public const MONTHS = 12;

    public const TOP_AREAS = 8;

    public function __construct(private ReportDesk $reports) {}

    /**
     * Sales charts are shown to anyone allowed to see sales tiles.
     */
    public function allows(User $user): bool
    {
        return $this->reports->allows($user, 'leads_by_source');
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $from, string $to): array
    {
        $ids = $this->reports->ownerIds($user);
        [$from, $to] = $this->normalise($from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'kpis' => $this->kpis($ids, $from, $to),
            'weekly_leads' => $this->weeklyLeads($ids, $to),
            'weekly_opportunities' => $this->weeklyOpportunities($ids, $to),
            'monthly_won' => $this->monthlyWon($ids, $to),
            'lead_sources' => $this->leadSources($ids, $from, $to),
            'won_by_area' => $this->wonByArea($ids, $from, $to),
        ];
    }

    /**
     * @param  list<int>|null  $ids
     * @return array{new_leads: int, new_opportunities: int, won: int, won_value: string, open: int, open_value: string}
     */
    public function kpis(?array $ids, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $won = $this->own(DB::table('opportunities')->whereBetween('won_at', [$start, $end]), $ids);
        // Open at the end of the period: created by then and not yet won or lost by then.
        $open = $this->own(DB::table('opportunities')
            ->where('created_at', '<=', $end)
            ->where(fn (Builder $query) => $query->whereNull('won_at')->orWhere('won_at', '>', $end))
            ->where(fn (Builder $query) => $query->whereNull('lost_at')->orWhere('lost_at', '>', $end)), $ids);

        return [
            'new_leads' => $this->own(DB::table('leads')->whereBetween('created_at', [$start, $end]), $ids)->count(),
            'new_opportunities' => $this->own(DB::table('opportunities')->whereBetween('created_at', [$start, $end]), $ids)->count(),
            'won' => (clone $won)->count(),
            'won_value' => $this->money((clone $won)->sum('mrc')),
            'open' => (clone $open)->count(),
            'open_value' => $this->money((clone $open)->sum('mrc')),
        ];
    }

    /**
     * Leads created per week for the last quarter, ending with the week that holds $to.
     *
     * @param  list<int>|null  $ids
     * @return list<array{week: string, count: int}>
     */
    public function weeklyLeads(?array $ids, string $to): array
    {
        $weeks = $this->weeks($to);
        $start = Carbon::parse($weeks[0])->startOfDay();
        $end = Carbon::parse($to)->endOfWeek()->endOfDay();
        $counts = array_fill_keys($weeks, 0);

        foreach ($this->own(DB::table('leads')->whereBetween('created_at', [$start, $end]), $ids)->pluck('created_at') as $at) {
            $key = Carbon::parse((string) $at)->startOfWeek()->toDateString();

            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return array_map(fn (string $week) => ['week' => $week, 'count' => $counts[$week]], $weeks);
    }

    /**
     * Opportunities opened, won and lost per week for the last quarter.
     *
     * @param  list<int>|null  $ids
     * @return list<array{week: string, opened: int, won: int, lost: int}>
     */
    public function weeklyOpportunities(?array $ids, string $to): array
    {
        $weeks = $this->weeks($to);
        $start = Carbon::parse($weeks[0])->startOfDay();
        $end = Carbon::parse($to)->endOfWeek()->endOfDay();
        $rows = array_fill_keys($weeks, ['opened' => 0, 'won' => 0, 'lost' => 0]);

        foreach (['opened' => 'created_at', 'won' => 'won_at', 'lost' => 'lost_at'] as $kind => $column) {
            foreach ($this->own(DB::table('opportunities')->whereBetween($column, [$start, $end]), $ids)->pluck($column) as $at) {
                $key = Carbon::parse((string) $at)->startOfWeek()->toDateString();

                if (array_key_exists($key, $rows)) {
                    $rows[$key][$kind]++;
                }
            }
        }

        return array_map(fn (string $week) => ['week' => $week] + $rows[$week], $weeks);
    }

    /**
     * Won opportunities and their monthly value (MRC) per month for the last year.
     *
     * @param  list<int>|null  $ids
     * @return list<array{month: string, count: int, value: string}>
     */
    public function monthlyWon(?array $ids, string $to): array
    {
        $last = Carbon::parse($to)->startOfMonth();
        $months = [];

        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $months[] = $last->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        $start = Carbon::parse($months[0].'-01')->startOfDay();
        $end = $last->copy()->endOfMonth();
        $rows = array_fill_keys($months, ['count' => 0, 'value' => '0']);

        foreach ($this->own(DB::table('opportunities')->whereBetween('won_at', [$start, $end]), $ids)->get(['won_at', 'mrc']) as $row) {
            $key = Carbon::parse((string) $row->won_at)->format('Y-m');

            if (array_key_exists($key, $rows)) {
                $rows[$key]['count']++;
                $rows[$key]['value'] = bcadd($rows[$key]['value'], (string) ($row->mrc ?? '0'), 2);
            }
        }

        return array_map(fn (string $month) => [
            'month' => $month,
            'count' => $rows[$month]['count'],
            'value' => bcadd($rows[$month]['value'], '0', 2),
        ], $months);
    }

    /**
     * New leads in the period, grouped by their source channel.
     *
     * @param  list<int>|null  $ids
     * @return list<array{channel: string, label: string, count: int}>
     */
    public function leadSources(?array $ids, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $query = DB::table('leads')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.source_id')
            ->whereBetween('leads.created_at', [$start, $end]);
        $query = $this->own($query, $ids, 'leads.owner_id');
        $rows = $query
            ->selectRaw('coalesce(lead_sources.channel, leads.source) as channel, count(*) as total')
            ->groupByRaw('coalesce(lead_sources.channel, leads.source)')
            ->orderByDesc('total')
            ->get();
        $names = DB::table('lead_sources')->get(['channel', 'name_en', 'name_ar'])->keyBy('channel');
        $arabic = app()->getLocale() === 'ar';

        return $rows->map(function ($row) use ($names, $arabic) {
            $channel = (string) ($row->channel ?: 'unknown');
            $name = $names->get($channel);
            $label = $name ? (string) ($arabic ? $name->name_ar : $name->name_en) : ucfirst(str_replace('_', ' ', $channel));

            return ['channel' => $channel, 'label' => $label, 'count' => (int) $row->total];
        })->values()->all();
    }

    /**
     * Opportunities won in the period, grouped by area, top areas first.
     *
     * @param  list<int>|null  $ids
     * @return list<array{area_id: int|null, label: string, count: int, value: string}>
     */
    public function wonByArea(?array $ids, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $query = DB::table('opportunities')
            ->leftJoin('areas', 'areas.id', '=', 'opportunities.area_id')
            ->whereBetween('opportunities.won_at', [$start, $end]);
        $query = $this->own($query, $ids, 'opportunities.owner_id');
        $arabic = app()->getLocale() === 'ar';
        $rows = $query
            ->selectRaw('opportunities.area_id as area_id, areas.name_en as name_en, areas.name_ar as name_ar, count(*) as total, sum(opportunities.mrc) as value')
            ->groupBy('opportunities.area_id', 'areas.name_en', 'areas.name_ar')
            ->get()
            ->map(fn ($row) => [
                'area_id' => $row->area_id === null ? null : (int) $row->area_id,
                'label' => $row->area_id === null ? '' : (string) ($arabic ? $row->name_ar : $row->name_en),
                'count' => (int) $row->total,
                'value' => $this->money($row->value),
            ])
            ->sortByDesc(fn (array $row) => (float) $row['value'])
            ->values();

        return $rows->take(self::TOP_AREAS)->all();
    }

    /**
     * @return list<string>
     */
    private function weeks(string $to): array
    {
        $last = Carbon::parse($to)->startOfWeek();
        $weeks = [];

        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $weeks[] = $last->copy()->subWeeks($i)->toDateString();
        }

        return $weeks;
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
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
