<?php

namespace App\Services\Tickets;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Customers\CustomerRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SupportDesk
{
    public const QUEUE_STATUSES = ['new', 'open', 'reopened', 'escalated'];

    public const OPEN_STATUSES = ['new', 'open', 'reopened', 'escalated', 'pending_customer'];

    public function __construct(
        private TicketRecords $tickets,
        private CustomerRecords $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, User $actor): Builder
    {
        if (! empty($filters['mine'])) {
            $query->where('assignee_id', $actor->id);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['priority'] ?? '') !== '') {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }
        if (! empty($filters['area_id'])) {
            $query->whereHas('customer', fn ($customers) => $customers->where('area_id', $filters['area_id']));
        }
        if (($filters['service_type'] ?? '') !== '') {
            $query->whereHas('service', fn ($services) => $services->where('service_type', $filters['service_type']));
        }
        if (($filters['sla'] ?? '') === 'warning') {
            $query->where(fn ($inner) => $inner->where('response_warned', true)->orWhere('resolve_warned', true));
        }
        if (($filters['sla'] ?? '') === 'breached') {
            $query->where(fn ($inner) => $inner->where('response_breached', true)->orWhere('resolve_breached', true));
        }
        if (! empty($filters['tier'])) {
            $query->where('escalation_tier_id', $filters['tier']);
        }
        if (! empty($filters['customer'])) {
            $query->where('customer_id', $filters['customer']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (($filters['channel'] ?? '') !== '') {
            $query->where('channel', $filters['channel']);
        }
        if (($filters['q'] ?? '') !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($inner) => $inner->where('number', 'like', $term)->orWhere('subject', 'like', $term));
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function inbox(User $viewer, array $filters): array
    {
        $query = $this->tickets->query($viewer)->with([
            'customer.area',
            'category',
            'assignee:id,name',
            'service:id,service_type',
            'messages' => fn ($messages) => $messages->with('author:id,name')->orderByDesc('id')->limit(1),
        ]);
        $this->applyFilters($query, $filters, $viewer);

        return $query->latest()->limit(200)->get()
            ->map(fn (Ticket $ticket) => $this->decorate($ticket, $viewer, false))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queue(User $viewer, string $locale): array
    {
        return $this->queueQuery($viewer)
            ->with([
                'customer.area',
                'category',
                'assignee:id,name',
                'service:id,service_type',
                'messages' => fn ($messages) => $messages->with('author:id,name')->orderByDesc('id')->limit(1),
            ])
            ->orderBy('response_due_at')
            ->limit(100)
            ->get()
            ->map(fn (Ticket $ticket) => $this->decorate($ticket, $viewer, true, $locale))
            ->values()
            ->all();
    }

    public function awaitingCount(User $viewer): int
    {
        return $this->queueQuery($viewer)->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selected(User $viewer, ?int $id, array $inbox, string $locale): ?array
    {
        if ($id) {
            $ticket = $this->tickets->query($viewer)->with([
                'customer.area',
                'category',
                'assignee:id,name',
                'service:id,service_type',
            ])->whereKey($id)->first();

            abort_unless($ticket instanceof Ticket, 403);

            return $this->decorate($ticket, $viewer, true, $locale, true);
        }

        $first = $inbox[0] ?? null;

        if (! is_array($first) || ! isset($first['id'])) {
            return null;
        }

        $ticket = $this->tickets->query($viewer)->whereKey($first['id'])->first();

        return $ticket instanceof Ticket
            ? $this->decorate($ticket, $viewer, true, $locale, true)
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $selected
     * @return array<string, mixed>|null
     */
    public function profile(User $viewer, ?array $selected): ?array
    {
        $id = is_array($selected) ? (int) ($selected['customer']['id'] ?? 0) : 0;

        if ($id === 0) {
            return null;
        }

        $customer = $this->customers->query($viewer)->whereKey($id)->first();

        if (! $customer instanceof Customer) {
            return null;
        }

        $row = $this->customers->present($customer, $viewer, true);
        $row['open_tickets'] = $this->tickets->query($viewer)
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();

        return $row;
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    public function suggestions(Ticket $ticket, string $locale): array
    {
        $ticket->loadMissing(['customer', 'category']);
        $name = $locale === 'ar'
            ? ($ticket->customer?->name_ar ?: $ticket->customer?->name_en)
            : ($ticket->customer?->name_en ?: $ticket->customer?->name_ar);
        if (! is_string($name) || $name === '') {
            $name = (string) ($ticket->customer?->account_no ?? $ticket->number);
        }
        $category = $locale === 'ar'
            ? (string) ($ticket->category?->name_ar ?: $ticket->category?->name_en)
            : (string) ($ticket->category?->name_en ?: $ticket->category?->name_ar);
        $replace = [
            'name' => $name,
            'number' => $ticket->number,
            'category' => $category !== '' ? $category : $ticket->subject,
            'subject' => $ticket->subject,
        ];
        $key = (string) ($ticket->category?->key ?? '');

        return [
            [
                'id' => 'ack',
                'label' => __('ui.tickets.suggest_ack_label'),
                'body' => $this->suggestionBody('ack', $key, $replace),
            ],
            [
                'id' => 'next',
                'label' => __('ui.tickets.suggest_next_label'),
                'body' => $this->suggestionBody('next', $key, $replace),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function insights(User $viewer): array
    {
        $tz = (string) config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();
        $from = $today->copy()->subDays(13);
        $to = $today->copy()->endOfDay();

        $scoped = $this->tickets->query($viewer);
        $created = (clone $scoped)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'created_at']);
        $responded = (clone $scoped)
            ->whereNotNull('first_response_at')
            ->whereBetween('first_response_at', [$from, $to])
            ->get(['id', 'created_at', 'first_response_at']);

        $volume = [];
        $responseTimes = [];
        $responseMinutes = [];

        for ($day = $from->copy(); $day->lte($today); $day->addDay()) {
            $key = $day->toDateString();
            $volume[$key] = 0;
            $responseTimes[$key] = [];
        }

        foreach ($created as $ticket) {
            $key = $ticket->created_at?->timezone($tz)->toDateString();
            if (is_string($key) && array_key_exists($key, $volume)) {
                $volume[$key]++;
            }
        }

        foreach ($responded as $ticket) {
            if ($ticket->first_response_at === null || $ticket->created_at === null) {
                continue;
            }
            $key = $ticket->first_response_at->timezone($tz)->toDateString();
            $minutes = ($ticket->first_response_at->getTimestamp() - $ticket->created_at->getTimestamp()) / 60;
            if (array_key_exists($key, $responseTimes)) {
                $responseTimes[$key][] = $minutes;
            }
            $responseMinutes[] = $minutes;
        }

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $today->toDateString(),
                'timezone' => $tz,
            ],
            'totals' => [
                'opened' => $created->count(),
                'awaiting_reply' => $this->awaitingCount($viewer),
                'avg_first_response_minutes' => $responseMinutes === []
                    ? null
                    : round(array_sum($responseMinutes) / count($responseMinutes), 1),
                'sla_breached' => (clone $scoped)
                    ->where(fn ($inner) => $inner->where('response_breached', true)->orWhere('resolve_breached', true))
                    ->count(),
            ],
            'volume' => collect($volume)->map(fn (int $count, string $date) => [
                'date' => $date,
                'count' => $count,
            ])->values()->all(),
            'response_times' => collect($responseTimes)->map(fn (array $minutes, string $date) => [
                'date' => $date,
                'avg_minutes' => $minutes === [] ? null : round(array_sum($minutes) / count($minutes), 1),
                'responded' => count($minutes),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decorate(Ticket $ticket, User $viewer, bool $withSuggestions, string $locale = 'en', bool $detail = false): array
    {
        $row = $this->tickets->present($ticket, $detail);
        $message = $ticket->relationLoaded('messages')
            ? $ticket->messages->sortByDesc('id')->first()
            : $ticket->messages()->with('author:id,name')->orderByDesc('id')->first();
        $row['last_message'] = $message ? [
            'body' => $message->body,
            'at' => $message->created_at?->toIso8601String(),
            'visibility' => $message->visibility,
            'author' => $message->author?->name,
        ] : null;
        $row['awaiting_reply'] = $ticket->first_response_at === null
            && in_array($ticket->status, self::QUEUE_STATUSES, true);
        if ($withSuggestions) {
            $row['suggestions'] = $this->suggestions($ticket, $locale);
        }

        return $row;
    }

    private function queueQuery(User $viewer): Builder
    {
        return $this->tickets->query($viewer)
            ->whereIn('status', self::QUEUE_STATUSES)
            ->whereNull('first_response_at');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function suggestionBody(string $kind, string $key, array $replace): string
    {
        $specific = "ui.tickets.suggest_{$kind}_{$key}";
        $line = $key !== '' && trans()->has($specific) ? __($specific, $replace) : __("ui.tickets.suggest_{$kind}", $replace);

        return is_string($line) ? $line : __("ui.tickets.suggest_{$kind}", $replace);
    }
}
