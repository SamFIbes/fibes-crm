<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Tickets\EscalationLadder;
use App\Services\Tickets\TicketClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class FlagTicketSlas extends Command
{
    private EscalationLadder $ladder;

    protected $signature = 'tickets:sla';

    protected $description = 'Flag ticket SLA warnings and breaches, and notify the people on the ticket.';

    public function handle(TicketClock $clock, EscalationLadder $ladder): int
    {
        $this->ladder = $ladder;

        Ticket::query()
            ->with(['assignee', 'owner'])
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNull('paused_at')
            ->orderBy('id')
            ->each(function (Ticket $ticket) use ($clock): void {
                $snapshot = $clock->snapshot($ticket);
                $this->mark($ticket, 'response', $snapshot['response']['state']);
                $this->mark($ticket, 'resolve', $snapshot['resolve']['state']);
            });

        return self::SUCCESS;
    }

    /**
     * @param  'response'|'resolve'  $side
     */
    private function mark(Ticket $ticket, string $side, string $state): void
    {
        $warned = $side.'_warned';
        $breached = $side.'_breached';

        if ($state === 'warning' && ! $ticket->{$warned} && ! $ticket->{$breached}) {
            Ticket::query()->whereKey($ticket->id)->update([$warned => true]);
            $ticket->{$warned} = true;
            $fresh = $ticket->fresh() ?? $ticket;
            $this->notify($fresh, __('ui.notifications.sla_warning_title', ['number' => $ticket->number]), __('ui.notifications.sla_warning_body', ['side' => $side]));
            $this->ladder->auto($fresh, 'sla_warning');
        }

        if ($state === 'breached' && ! $ticket->{$breached}) {
            Ticket::query()->whereKey($ticket->id)->update([$breached => true]);
            $ticket->{$breached} = true;
            $fresh = $ticket->fresh() ?? $ticket;
            $this->notify($fresh, __('ui.notifications.sla_breach_title', ['number' => $ticket->number]), __('ui.notifications.sla_breach_body', ['side' => $side]));
            $this->ladder->auto($fresh, 'sla_breach');
        }
    }

    private function notify(Ticket $ticket, string $title, string $body): void
    {
        $people = collect([$ticket->assignee, $ticket->owner])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id');

        if ($people->isEmpty()) {
            return;
        }

        Notification::send($people, new WorkspaceNotification($title, $body));
    }
}
