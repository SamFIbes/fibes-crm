<?php

namespace App\Services\Tickets;

use App\Models\EscalationTier;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Automations\AutomationDesk;
use App\Services\Jobs\FieldWork;
use App\Services\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class EscalationLadder
{
    public function __construct(
        private AuditLogger $audit,
        private SettingsStore $settings,
        private TicketClock $clock,
        private FieldWork $field,
    ) {}

    public function seed(): void
    {
        $rows = [
            [1, 'call_center', 'Call center', 'مركز الاتصال', 'call_center'],
            [2, 'noc', 'Network operations', 'عمليات الشبكة', 'noc_l2'],
            [3, 'field', 'Field technicians', 'الفنيون الميدانيون', 'field_l3'],
        ];

        foreach ($rows as [$level, $key, $en, $ar, $team]) {
            EscalationTier::query()->updateOrCreate(['key' => $key], [
                'level' => $level,
                'name_en' => $en,
                'name_ar' => $ar,
                'team_id' => Team::query()->where('key', $team)->value('id'),
            ]);
        }
    }

    /**
     * @return array{on_warning: string, on_breach: string}
     */
    public function rule(): array
    {
        $stored = $this->settings->get('escalation', config('fibes.escalation', []));

        return [
            'on_warning' => ($stored['on_warning'] ?? 'none') === 'next_tier' ? 'next_tier' : 'none',
            'on_breach' => ($stored['on_breach'] ?? 'next_tier') === 'none' ? 'none' : 'next_tier',
        ];
    }

    public function startingTierId(): ?int
    {
        if (! EscalationTier::query()->exists()) {
            $this->seed();
        }

        return EscalationTier::query()->where('level', 1)->value('id');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function move(Ticket $ticket, array $input, ?User $actor, ?Request $request, bool $automatic = false, string $trigger = 'manual'): string
    {
        $ticket->loadMissing('tier');
        $direction = ($input['direction'] ?? 'escalate') === 'deescalate' ? 'deescalate' : 'escalate';
        $target = $this->target($ticket, $input, $direction);
        $fromTier = $ticket->escalation_tier_id;
        $fromAssignee = $ticket->assignee_id;
        $assigneeId = $this->assigneeId($target, $input, $automatic);

        if ($ticket->status === 'pending_customer') {
            $this->clock->resume($ticket);
        }

        $ticket->fill([
            'status' => $direction === 'deescalate' ? 'open' : 'escalated',
            'escalation_tier_id' => $target->id,
            'team_id' => $target->team_id ?? $ticket->team_id,
            'assignee_id' => $assigneeId,
            'escalation_reason' => $input['reason'],
            'handover_note' => $input['note'],
        ])->save();

        $ticket->escalations()->create([
            'direction' => $direction,
            'from_tier_id' => $fromTier,
            'to_tier_id' => $target->id,
            'from_assignee_id' => $fromAssignee,
            'to_assignee_id' => $assigneeId,
            'reason' => $input['reason'],
            'handover_note' => $input['note'],
            'automatic' => $automatic,
            'trigger' => $trigger,
            'actor_id' => $actor?->id,
        ]);

        $event = $direction === 'deescalate' ? 'ticket.deescalated' : 'ticket.escalated';
        $this->audit->record($event, $actor, [
            'customer_id' => $ticket->customer_id,
            'ticket_id' => $ticket->id,
            'from_tier_id' => $fromTier,
            'to_tier_id' => $target->id,
            'assignee_id' => $assigneeId,
            'reason' => $input['reason'],
            'automatic' => $automatic,
            'trigger' => $trigger,
        ], $request);

        $this->notify($ticket, $target, $direction);
        if ($direction === 'escalate') {
            app(AutomationDesk::class)->emit('ticket.escalated', [
                'subject_type' => 'ticket', 'subject_id' => $ticket->id, 'ticket_id' => $ticket->id, 'customer_id' => $ticket->customer_id, 'priority' => $ticket->priority,
            ]);
        }

        if ($direction === 'escalate' && (int) $target->level === 3 && $actor instanceof User) {
            $this->field->ensureRepair($ticket, $actor, $request);
        }

        return $direction;
    }

    public function auto(Ticket $ticket, string $trigger): bool
    {
        $rule = $this->rule();
        $key = $trigger === 'sla_breach' ? 'on_breach' : 'on_warning';

        if (($rule[$key] ?? 'none') !== 'next_tier') {
            return false;
        }

        if (in_array($ticket->status, ['resolved', 'closed', 'pending_customer'], true) || $ticket->paused_at !== null) {
            return false;
        }

        try {
            $this->move($ticket, [
                'direction' => 'escalate',
                'reason' => __('ui.tickets.auto_reason'),
                'note' => __('ui.tickets.auto_note'),
            ], null, null, true, $trigger);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function target(Ticket $ticket, array $input, string $direction): EscalationTier
    {
        if (! EscalationTier::query()->exists()) {
            $this->seed();
        }

        $current = (int) ($ticket->tier?->level ?? 0);

        if (! empty($input['tier_id'])) {
            $chosen = EscalationTier::query()->find($input['tier_id']);

            if ($chosen instanceof EscalationTier && $chosen->level !== $current) {
                return $chosen;
            }
        }

        if ($direction === 'escalate' && ! empty($input['assignee_id'])) {
            $person = User::query()->find($input['assignee_id']);

            if ($person instanceof User && ($person->hasRole('field_technician') || $person->hasRole('installer'))) {
                $field = EscalationTier::query()->where('level', 3)->first();

                if ($field instanceof EscalationTier && $field->level !== $current) {
                    return $field;
                }
            }
        }

        $level = $direction === 'deescalate' ? $current - 1 : ($current === 0 ? 1 : $current + 1);
        $tier = EscalationTier::query()->where('level', $level)->first();

        if (! $tier instanceof EscalationTier) {
            throw ValidationException::withMessages([
                'direction' => __('ui.tickets.no_tier'),
            ]);
        }

        return $tier;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assigneeId(EscalationTier $target, array $input, bool $automatic): ?int
    {
        if (! $automatic && ! empty($input['assignee_id'])) {
            return (int) $input['assignee_id'];
        }

        $onDuty = $target->members()->wherePivot('on_duty', true)->orderBy('users.id')->value('users.id');

        if ($onDuty) {
            return (int) $onDuty;
        }

        return $automatic ? null : (isset($input['assignee_id']) && $input['assignee_id'] !== '' ? (int) $input['assignee_id'] : null);
    }

    private function notify(Ticket $ticket, EscalationTier $target, string $direction): void
    {
        $members = $target->members()->wherePivot('on_duty', true)->get();

        if ($members->isEmpty()) {
            $members = $target->members()->get();
        }

        if ($ticket->assignee_id) {
            $assignee = User::query()->find($ticket->assignee_id);

            if ($assignee instanceof User && ! $members->contains('id', $assignee->id)) {
                $members->push($assignee);
            }
        }

        if ($members->isEmpty()) {
            return;
        }

        $title = $direction === 'deescalate'
            ? __('ui.notifications.deescalated_title', ['number' => $ticket->number])
            : __('ui.notifications.escalated_title', ['number' => $ticket->number]);
        $body = __('ui.notifications.escalated_body', ['tier' => $target->label()]);

        Notification::send($members, new WorkspaceNotification($title, $body));
    }
}
