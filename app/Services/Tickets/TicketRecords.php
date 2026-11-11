<?php

namespace App\Services\Tickets;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\WhatsAppDesk;
use App\Services\Jobs\FieldWork;
use App\Services\Settings\SettingsStore;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class TicketRecords
{
    public function __construct(
        private AccessGate $access,
        private AuditLogger $audit,
        private SettingsStore $settings,
        private TicketClock $clock,
        private EscalationLadder $ladder,
        private FieldWork $field,
    ) {}

    public function query(User $viewer): Builder
    {
        $query = Ticket::query();
        $scope = $this->access->scopeFor($viewer, 'tickets.view');

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($scope === 'all') {
            return $query;
        }

        if ($viewer->hasRole('field_technician') || $viewer->hasRole('installer')) {
            return $query->where(function (Builder $inner) use ($viewer) {
                $inner->where('assignee_id', $viewer->id)->orWhere('owner_id', $viewer->id);
            });
        }

        return $this->access->restrict($query, $viewer, 'tickets.view', 'owner_id', 'team_id');
    }

    public function canTouch(User $viewer): bool
    {
        return $this->access->allows($viewer, 'tickets.create')
            || $this->access->allows($viewer, 'tickets.claim')
            || $this->access->allows($viewer, 'tickets.assign')
            || $this->access->allows($viewer, 'tickets.escalate');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, Request $request): Ticket
    {
        return DB::transaction(function () use ($input, $actor, $request) {
            $ticket = new Ticket([
                'number' => $this->nextNumber(),
                'customer_id' => $input['customer_id'],
                'customer_service_id' => $input['customer_service_id'] ?? null,
                'category_id' => $input['category_id'],
                'priority' => $input['priority'],
                'channel' => $input['channel'],
                'status' => 'new',
                'subject' => $input['subject'],
                'owner_id' => $actor->id,
                'team_id' => $actor->teams()->value('id'),
                'escalation_tier_id' => $this->ladder->startingTierId(),
            ]);
            $this->clock->arm($ticket, $this->settings->get('sla', config('fibes.sla')), $ticket->priority);
            $ticket->save();
            $ticket->messages()->create([
                'author_id' => $actor->id,
                'visibility' => 'public',
                'body' => $input['body'],
            ]);
            $this->field->copyTicketChecks($ticket);
            $this->audit->record('ticket.created', $actor, [
                'customer_id' => $ticket->customer_id,
                'ticket_id' => $ticket->id,
                'number' => $ticket->number,
                'priority' => $ticket->priority,
            ], $request);

            return $ticket;
        });
    }

    public function changeStatus(Ticket $ticket, string $status, User $actor, Request $request): void
    {
        $from = $ticket->status;
        if ($from === $status) {
            return;
        }

        if ($status === 'pending_customer') {
            $this->clock->pause($ticket);
        } elseif ($from === 'pending_customer') {
            $this->clock->resume($ticket);
        }

        $ticket->status = $status;
        $ticket->save();
        $ticket->loadMissing('customer');

        if ($ticket->customer instanceof Customer) {
            app(WhatsAppDesk::class)->queue($ticket->customer, 'ticket', [
                (string) $ticket->number,
                $status,
            ], $actor);
        }
        $this->audit->record('ticket.status', $actor, [
            'customer_id' => $ticket->customer_id,
            'ticket_id' => $ticket->id,
            'from' => $from,
            'to' => $status,
        ], $request);

        if (in_array($status, ['resolved', 'closed'], true)) {
            $this->field->afterTicketResolved($ticket, $actor, $request);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function escalate(Ticket $ticket, array $input, User $actor, Request $request): string
    {
        return $this->ladder->move($ticket, $input, $actor, $request);
    }

    public function reply(Ticket $ticket, string $body, string $visibility, User $actor, Request $request): void
    {
        $ticket->messages()->create([
            'author_id' => $actor->id,
            'visibility' => $visibility,
            'body' => $body,
        ]);

        if ($visibility === 'public' && $ticket->first_response_at === null && $ticket->messages()->count() > 1) {
            $ticket->first_response_at = now();
            if ($ticket->status === 'new') {
                $ticket->status = 'open';
            }
            $ticket->save();
        }

        $this->audit->record('ticket.message', $actor, [
            'customer_id' => $ticket->customer_id,
            'ticket_id' => $ticket->id,
            'visibility' => $visibility,
        ], $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Ticket $ticket, bool $detail = false): array
    {
        $ticket->loadMissing(['customer:id,name_en,name_ar,account_no', 'category', 'assignee:id,name', 'service:id,service_type', 'customer.area', 'tier', 'incident']);
        $sla = $this->clock->snapshot($ticket);
        $row = [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'channel' => $ticket->channel,
            'customer' => [
                'id' => $ticket->customer_id,
                'name_en' => $ticket->customer?->name_en,
                'name_ar' => $ticket->customer?->name_ar,
                'account_no' => $ticket->customer?->account_no,
            ],
            'area' => $ticket->customer?->area ? [
                'name_en' => $ticket->customer->area->name_en,
                'name_ar' => $ticket->customer->area->name_ar,
            ] : null,
            'service_type' => $ticket->service?->service_type,
            'category' => [
                'id' => $ticket->category_id,
                'key' => $ticket->category?->key,
                'name_en' => $ticket->category?->name_en,
                'name_ar' => $ticket->category?->name_ar,
            ],
            'assignee' => $ticket->assignee?->name,
            'tier' => $ticket->tier ? [
                'level' => $ticket->tier->level,
                'name_en' => $ticket->tier->name_en,
                'name_ar' => $ticket->tier->name_ar,
            ] : null,
            'incident' => $ticket->incident ? [
                'id' => $ticket->incident->id,
                'number' => $ticket->incident->number,
                'title' => $ticket->incident->title,
            ] : null,
            'sla' => $sla,
        ];

        if ($detail) {
            $row['escalation_reason'] = $ticket->escalation_reason;
            $row['handover_note'] = $ticket->handover_note;
            $row['timeline'] = $this->timeline($ticket);
            $row['messages'] = $ticket->messages()->with('author:id,name')->orderBy('id')->get()->map(fn ($message) => [
                'id' => $message->id,
                'body' => $message->body,
                'visibility' => $message->visibility,
                'author' => $message->author?->name,
                'at' => $message->created_at?->toIso8601String(),
            ])->all();
            $row['checks'] = $ticket->checks()->orderBy('sort')->get()->map(fn ($check) => [
                'id' => $check->id,
                'label_en' => $check->label_en,
                'label_ar' => $check->label_ar,
                'required' => $check->required,
                'done' => $check->done,
            ])->all();
            $csat = $ticket->csat()->first();
            $row['csat'] = $csat ? [
                'score' => $csat->score,
                'token' => $csat->token,
                'comment' => $csat->comment,
            ] : null;
            $follow = $ticket->tasks()->where('kind', 'follow_up_call')->latest('id')->first();
            $row['follow_up'] = $follow ? [
                'id' => $follow->id,
                'due_at' => $follow->due_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'status' => $follow->status,
            ] : null;
            $row['repair_job'] = $this->field->repairFor($ticket);
            $row['files'] = $ticket->files()->latest()->get()->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'download_url' => URL::temporarySignedRoute('files.download', now()->addMinutes(10), ['attachment' => $file->id]),
            ])->all();
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomer(Customer $customer, User $viewer): array
    {
        return $this->query($viewer)
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(fn (Ticket $ticket) => $this->present($ticket))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeline(Ticket $ticket): array
    {
        $messages = $ticket->messages()->with('author:id,name')->orderBy('id')->get()->map(fn ($message) => [
            'kind' => 'message',
            'id' => 'm'.$message->id,
            'body' => $message->body,
            'visibility' => $message->visibility,
            'author' => $message->author?->name,
            'at' => $message->created_at?->toIso8601String(),
        ]);
        $steps = $ticket->escalations()->with(['fromTier', 'toTier', 'actor:id,name'])->orderBy('id')->get()->map(fn ($step) => [
            'kind' => 'escalation',
            'id' => 'e'.$step->id,
            'direction' => $step->direction,
            'automatic' => $step->automatic,
            'reason' => $step->reason,
            'note' => $step->handover_note,
            'from_tier_en' => $step->fromTier?->name_en,
            'from_tier_ar' => $step->fromTier?->name_ar,
            'to_tier_en' => $step->toTier?->name_en,
            'to_tier_ar' => $step->toTier?->name_ar,
            'author' => $step->actor?->name,
            'at' => $step->created_at?->toIso8601String(),
        ]);

        return $messages->concat($steps)->sortBy([
            ['at', 'asc'],
            ['id', 'asc'],
        ])->values()->all();
    }

    private function nextNumber(): string
    {
        $last = Ticket::query()->lockForUpdate()->orderByDesc('id')->value('number');
        $next = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
            $next = ((int) $match[1]) + 1;
        }

        return 'TK-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
