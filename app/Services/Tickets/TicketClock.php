<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use Carbon\CarbonInterface;

class TicketClock
{
    /**
     * @param  array<string, mixed>  $sla
     */
    public function arm(Ticket $ticket, array $sla, string $priority): void
    {
        $row = $sla['priorities'][$priority] ?? ['respond_minutes' => 15, 'resolve_minutes' => 1440];
        $ticket->response_minutes = (int) $row['respond_minutes'];
        $ticket->resolve_minutes = (int) $row['resolve_minutes'];
        $ticket->response_due_at = now()->addMinutes($ticket->response_minutes);
        $ticket->resolve_due_at = now()->addMinutes($ticket->resolve_minutes);
    }

    public function pause(Ticket $ticket): void
    {
        if ($ticket->paused_at === null) {
            $ticket->paused_at = now();
        }
    }

    public function resume(Ticket $ticket): void
    {
        if ($ticket->paused_at === null) {
            return;
        }

        $seconds = now()->getTimestamp() - $ticket->paused_at->getTimestamp();
        $ticket->paused_seconds += max(0, $seconds);

        if ($ticket->first_response_at === null) {
            $ticket->response_due_at = $ticket->response_due_at->copy()->addSeconds($seconds);
        }

        $ticket->resolve_due_at = $ticket->resolve_due_at->copy()->addSeconds($seconds);
        $ticket->paused_at = null;
    }

    /**
     * @return array{response: array<string, mixed>, resolve: array<string, mixed>}
     */
    public function snapshot(Ticket $ticket): array
    {
        $now = $ticket->paused_at ?? now();

        return [
            'response' => $this->side(
                $ticket->response_due_at,
                $ticket->response_minutes,
                $now,
                $ticket->first_response_at !== null,
                $ticket->paused_at !== null,
            ),
            'resolve' => $this->side(
                $ticket->resolve_due_at,
                $ticket->resolve_minutes,
                $now,
                in_array($ticket->status, ['resolved', 'closed'], true),
                $ticket->paused_at !== null,
            ),
        ];
    }

    /**
     * @return array{state: string, remaining_seconds: int, ratio: float}
     */
    private function side(CarbonInterface $due, int $minutes, CarbonInterface $now, bool $met, bool $paused): array
    {
        $total = max(1, $minutes * 60);
        $remaining = $due->getTimestamp() - $now->getTimestamp();
        $ratio = ($total - $remaining) / $total;

        $state = 'ok';
        if ($met) {
            $state = 'met';
        } elseif ($remaining <= 0) {
            $state = 'breached';
        } elseif ($paused) {
            $state = 'paused';
        } elseif ($ratio >= 0.8) {
            $state = 'warning';
        }

        return [
            'state' => $state,
            'remaining_seconds' => $remaining,
            'ratio' => round($ratio, 3),
        ];
    }
}
