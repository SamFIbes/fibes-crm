<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leads that need a follow-up, as attention queues for the dashboard.
 * Queues use the same shape as the attention desk: key, href, count and up to six items.
 */
class LeadFollowUps
{
    /** Same open statuses the lead SLA sweep watches. */
    public const OPEN = ['new', 'assigned', 'contacted', 'qualified', 'unreachable', 'waitlisted'];

    /** Lead activity kinds that mean a person actually touched the lead. */
    public const CONTACT_KINDS = ['status', 'no_answer', 'note', 'attached', 'claimed'];

    public const DUE_SOON_HOURS = 4;

    public const STALE_DAYS = 7;

    public const ITEMS = 6;

    public function __construct(
        private AccessGate $access,
        private LeadDesk $leads,
        private ReportDesk $reports,
    ) {}

    /**
     * Shown to people who can see the "leads past their clock" tile and can open leads.
     */
    public function allows(User $user): bool
    {
        return $this->reports->allows($user, 'lead_sla') && $this->access->allows($user, 'leads.view');
    }

    /**
     * @return array{queues: list<array{key: string, href: string, count: int, items: list<array<string, mixed>>}>, due_soon_hours: int, stale_days: int}
     */
    public function for(User $user): array
    {
        $now = now();
        $soon = $now->copy()->addHours(self::DUE_SOON_HOURS);
        $stale = $now->copy()->subDays(self::STALE_DAYS);
        $open = fn () => $this->leads->visible($user)->whereIn('status', self::OPEN);

        $overdue = $open()->where(function (Builder $query) use ($now) {
            $query->whereNotNull('sla_breached_at')
                ->orWhere(fn (Builder $inner) => $inner->whereNotNull('sla_due_at')->where('sla_due_at', '<', $now));
        });
        $dueSoon = $open()->whereNull('sla_breached_at')->whereNotNull('sla_due_at')->whereBetween('sla_due_at', [$now, $soon]);
        $noContact = $this->noContact($open(), $stale, $now);

        return [
            'queues' => [
                $this->queue('leads_overdue', '/leads?view=risk', $overdue->orderByRaw('coalesce(sla_due_at, sla_breached_at) asc')->orderBy('id'), 'bad', 'due'),
                $this->queue('leads_due_soon', '/leads?view=risk', $dueSoon->orderBy('sla_due_at')->orderBy('id'), 'warn', 'due'),
                $this->queue('leads_no_contact', '/leads?view=mine', $noContact->orderBy('created_at')->orderBy('id'), 'warn', 'contact'),
            ],
            'due_soon_hours' => self::DUE_SOON_HOURS,
            'stale_days' => self::STALE_DAYS,
        ];
    }

    /**
     * Open leads with no human touch since the cutoff and not already on an SLA queue.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function noContact(Builder $query, Carbon $stale, Carbon $now): Builder
    {
        return $query
            ->where('created_at', '<', $stale)
            ->whereNull('sla_breached_at')
            ->where(fn (Builder $inner) => $inner->whereNull('sla_due_at')->orWhere('sla_due_at', '>', $now->copy()->addHours(self::DUE_SOON_HOURS)))
            ->where(fn (Builder $inner) => $inner->whereNull('first_response_at')->orWhere('first_response_at', '<', $stale))
            ->whereNotExists(fn (QueryBuilder $activity) => $activity->select(DB::raw(1))->from('lead_activities')
                ->whereColumn('lead_activities.lead_id', 'leads.id')
                ->whereNotNull('lead_activities.user_id')
                ->whereIn('lead_activities.kind', self::CONTACT_KINDS)
                ->where('lead_activities.created_at', '>=', $stale))
            ->whereNotExists(fn (QueryBuilder $call) => $call->select(DB::raw(1))->from('interactions')
                ->whereColumn('interactions.lead_id', 'leads.id')
                ->where('interactions.created_at', '>=', $stale));
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array{key: string, href: string, count: int, items: list<array<string, mixed>>}
     */
    private function queue(string $key, string $href, Builder $query, string $tone, string $clock): array
    {
        $rows = (clone $query)->with(['owner:id,name'])->limit(self::ITEMS)->get();
        $areas = DB::table('areas')->whereIn('id', $rows->pluck('area_id')->filter()->unique()->values())->get(['id', 'name_en', 'name_ar'])->keyBy('id');
        $contacts = $clock === 'contact' ? $this->lastContacts($rows->pluck('id')->all()) : [];

        return [
            'key' => $key,
            'href' => $href,
            'count' => (clone $query)->count(),
            'items' => $rows->map(function (Lead $lead) use ($areas, $tone, $clock, $contacts) {
                $area = $lead->area_id ? $areas->get($lead->area_id) : null;
                $when = $clock === 'due'
                    ? ($lead->sla_due_at ?? $lead->sla_breached_at)
                    : ($contacts[$lead->id] ?? $lead->first_response_at);

                return [
                    'id' => $lead->id,
                    'title' => (string) ($lead->name ?: trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: ($lead->number ?: '#'.$lead->id)),
                    'meta' => $area?->name_en,
                    'meta_ar' => $area?->name_ar,
                    'href' => '/leads?lead='.$lead->id,
                    'tone' => $tone,
                    'when' => $when ? Carbon::parse($when)->toIso8601String() : null,
                    'owner' => $lead->owner?->name,
                    'status' => (string) $lead->status,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Latest human contact per lead from activities and calls.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function lastContacts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $latest = [];
        $activity = DB::table('lead_activities')->whereIn('lead_id', $ids)->whereNotNull('user_id')->whereIn('kind', self::CONTACT_KINDS)
            ->selectRaw('lead_id, max(created_at) as at')->groupBy('lead_id')->pluck('at', 'lead_id');
        $calls = DB::table('interactions')->whereIn('lead_id', $ids)->selectRaw('lead_id, max(created_at) as at')->groupBy('lead_id')->pluck('at', 'lead_id');

        foreach ([$activity, $calls] as $source) {
            foreach ($source as $leadId => $at) {
                if ($at !== null && (! isset($latest[(int) $leadId]) || $at > $latest[(int) $leadId])) {
                    $latest[(int) $leadId] = (string) $at;
                }
            }
        }

        return $latest;
    }
}
