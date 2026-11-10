<?php

namespace App\Services\Attention;

use App\Models\Approval;
use App\Models\CollectionCase;
use App\Models\Job;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketRecords;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;

class AttentionDesk
{
    public function __construct(
        private AccessGate $access,
        private TicketRecords $tickets,
    ) {}

    /**
     * Queues the signed-in person is allowed to see. Counts come from the database.
     * A queue is omitted when the role cannot see that work.
     *
     * @return array{queues: list<array{key: string, href: string, count: int, items: list<array<string, mixed>>}>}
     */
    public function for(User $actor): array
    {
        $queues = [];

        if ($this->access->allows($actor, 'tickets.view')) {
            $open = $this->tickets->query($actor)->whereNotIn('status', ['resolved', 'closed']);
            $queues[] = $this->ticketQueue(
                'tickets_unassigned',
                '/tickets?queue=attention',
                (clone $open)->whereNull('assignee_id'),
                'warn',
            );
            $breached = (clone $open)->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->whereNull('first_response_at')->whereNotNull('response_due_at')->where('response_due_at', '<', now());
                })->orWhere(function ($inner): void {
                    $inner->whereNotNull('resolve_due_at')->where('resolve_due_at', '<', now());
                });
            });
            $queues[] = $this->ticketQueue('tickets_breached', '/tickets?queue=attention&sla=breached', $breached, 'bad');
        }

        if ($this->access->allows($actor, 'approvals.view')) {
            $pending = Approval::query()->where('status', 'pending');
            $scope = $this->access->scopeFor($actor, 'approvals.view');

            if ($scope === 'own') {
                $pending->where('requester_id', $actor->id);
            } elseif ($scope !== 'all') {
                $pending->whereRaw('0 = 1');
            }

            $queues[] = [
                'key' => 'approvals',
                'href' => '/approvals',
                'count' => (clone $pending)->count(),
                'items' => (clone $pending)->latest('id')->limit(6)->get()->map(fn (Approval $approval) => [
                    'id' => $approval->id,
                    'title' => $approval->summary ?: $approval->type,
                    'meta' => $approval->type,
                    'meta_ar' => $approval->type,
                    'href' => '/approvals',
                    'tone' => 'warn',
                ])->values()->all(),
            ];
        }

        if ($this->access->allows($actor, 'jobs.schedule')) {
            $jobs = Job::query()->whereNull('technician_id')->whereNotIn('status', ['done', 'failed']);
            $queues[] = [
                'key' => 'jobs_unassigned',
                'href' => '/dispatch',
                'count' => (clone $jobs)->count(),
                'items' => (clone $jobs)->with('customer:id,name_en,name_ar')->latest('id')->limit(6)->get()->map(fn (Job $job) => [
                    'id' => $job->id,
                    'title' => $job->number,
                    'meta' => $job->customer?->name_en,
                    'meta_ar' => $job->customer?->name_ar,
                    'href' => '/dispatch',
                    'tone' => 'warn',
                ])->values()->all(),
            ];
        }

        if ($this->access->allows($actor, 'services.suspend') || $this->access->allows($actor, 'services.request_suspend')) {
            $seeMoney = $this->access->allows($actor, 'billing.view_amounts');
            $promises = CollectionCase::query()
                ->whereNull('archived_at')
                ->where('stage', 'promise')
                ->whereDate('promise_to_pay_date', '<', now()->toDateString());
            $queues[] = [
                'key' => 'promises',
                'href' => '/collections',
                'count' => (clone $promises)->count(),
                'items' => (clone $promises)->with('customer:id,name_en,name_ar')->orderBy('promise_to_pay_date')->limit(6)->get()->map(fn (CollectionCase $case) => [
                    'id' => $case->id,
                    'title' => $case->customer?->name_en,
                    'meta' => $seeMoney ? (string) $case->amount_due : null,
                    'meta_ar' => $case->customer?->name_ar,
                    'href' => '/collections',
                    'tone' => 'bad',
                    'when' => $case->promise_to_pay_date?->toDateString(),
                ])->values()->all(),
            ];
        }

        return ['queues' => $queues];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return array{key: string, href: string, count: int, items: list<array<string, mixed>>}
     */
    private function ticketQueue(string $key, string $href, $query, string $tone): array
    {
        return [
            'key' => $key,
            'href' => $href,
            'count' => (clone $query)->count(),
            'items' => (clone $query)->with('customer:id,name_en,name_ar')->orderBy('id')->limit(6)->get()->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'title' => $ticket->subject,
                'meta' => $ticket->customer?->name_en,
                'meta_ar' => $ticket->customer?->name_ar,
                'href' => '/tickets/'.$ticket->id,
                'tone' => $tone,
                'number' => $ticket->number,
            ])->values()->all(),
        ];
    }
}
