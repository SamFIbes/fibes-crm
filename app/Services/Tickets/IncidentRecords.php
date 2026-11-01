<?php

namespace App\Services\Tickets;

use App\Models\Customer;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentRecords
{
    public function __construct(
        private AuditLogger $audit,
        private TicketRecords $tickets,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, Request $request): Incident
    {
        return DB::transaction(function () use ($input, $actor, $request) {
            $incident = Incident::query()->create([
                'number' => $this->nextNumber(),
                'title' => $input['title'],
                'status' => 'open',
                'governorate_id' => $input['governorate_id'] ?? null,
                'district_id' => $input['district_id'] ?? null,
                'area_id' => $input['area_id'] ?? null,
                'service_type' => $input['service_type'] ?? null,
                'network_element' => $input['network_element'] ?? null,
                'network_node_id' => $input['network_node_id'] ?? null,
                'summary' => $input['summary'] ?? null,
                'opened_by' => $actor->id,
            ]);
            $this->audit->record('incident.created', $actor, [
                'incident_id' => $incident->id,
                'number' => $incident->number,
                'area_id' => $incident->area_id,
                'service_type' => $incident->service_type,
            ], $request);

            return $incident;
        });
    }

    public function link(Incident $incident, Ticket $ticket, User $actor, Request $request): void
    {
        $this->guardOpen($incident);

        if ($ticket->incident_id && (int) $ticket->incident_id !== (int) $incident->id) {
            throw ValidationException::withMessages(['ticket_id' => __('ui.incidents.already_linked')]);
        }

        $ticket->incident_id = $incident->id;
        $ticket->save();
        $this->audit->record('incident.linked', $actor, [
            'incident_id' => $incident->id,
            'ticket_id' => $ticket->id,
            'customer_id' => $ticket->customer_id,
        ], $request);
    }

    public function unlink(Incident $incident, Ticket $ticket, User $actor, Request $request): void
    {
        if ((int) $ticket->incident_id !== (int) $incident->id) {
            return;
        }

        $ticket->incident_id = null;
        $ticket->save();
        $this->audit->record('incident.unlinked', $actor, [
            'incident_id' => $incident->id,
            'ticket_id' => $ticket->id,
            'customer_id' => $ticket->customer_id,
        ], $request);
    }

    public function linkMatching(Incident $incident, User $actor, Request $request): int
    {
        $this->guardOpen($incident);
        $count = 0;

        $this->matchingQuery($incident)->orderBy('id')->each(function (Ticket $ticket) use ($incident, $actor, $request, &$count) {
            $ticket->incident_id = $incident->id;
            $ticket->save();
            $count++;
            $this->audit->record('incident.linked', $actor, [
                'incident_id' => $incident->id,
                'ticket_id' => $ticket->id,
                'customer_id' => $ticket->customer_id,
                'bulk' => true,
            ], $request);
        });

        $this->audit->record('incident.bulk_linked', $actor, [
            'incident_id' => $incident->id,
            'count' => $count,
        ], $request);

        return $count;
    }

    public function resolve(Incident $incident, string $mode, string $note, User $actor, Request $request): int
    {
        $this->guardOpen($incident);
        $updated = 0;

        $incident->tickets()->whereNotIn('status', ['closed'])->orderBy('id')->each(function (Ticket $ticket) use ($mode, $note, $actor, $request, $incident, &$updated) {
            $body = $incident->number.': '.$note;
            $this->tickets->reply($ticket, $body, 'public', $actor, $request);

            if ($mode === 'resolve' && ! in_array($ticket->fresh()->status, ['resolved', 'closed'], true)) {
                $this->tickets->changeStatus($ticket->fresh(), 'resolved', $actor, $request);
            }

            $updated++;
        });

        $incident->fill([
            'status' => 'resolved',
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_mode' => $mode,
            'resolution_note' => $note,
        ])->save();

        $this->audit->record('incident.resolved', $actor, [
            'incident_id' => $incident->id,
            'mode' => $mode,
            'tickets' => $updated,
        ], $request);

        return $updated;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bannersFor(Customer $customer): array
    {
        return Incident::query()
            ->where('status', 'open')
            ->with(['area', 'district', 'governorate'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Incident $incident) => $this->matchesCustomer($incident, $customer))
            ->map(fn (Incident $incident) => $this->present($incident))
            ->values()
            ->all();
    }

    public function matchesCustomer(Incident $incident, Customer $customer): bool
    {
        $customer->loadMissing('area.district', 'services');
        $hasGeo = $incident->area_id || $incident->district_id || $incident->governorate_id;
        $hasService = filled($incident->service_type);

        if (! $hasGeo && ! $hasService) {
            return false;
        }

        $geo = true;

        if ($incident->area_id) {
            $geo = (int) $customer->area_id === (int) $incident->area_id;
        } elseif ($incident->district_id) {
            $geo = (int) $customer->area?->district_id === (int) $incident->district_id;
        } elseif ($incident->governorate_id) {
            $geo = (int) $customer->area?->district?->governorate_id === (int) $incident->governorate_id;
        }

        $service = ! $hasService || $customer->services->contains(
            fn ($row) => $row->service_type === $incident->service_type,
        );

        if ($hasGeo && $hasService) {
            return $geo && $service;
        }

        return $hasGeo ? $geo : $service;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Incident $incident, bool $detail = false): array
    {
        $incident->loadMissing(['area', 'district', 'governorate']);
        $row = [
            'id' => $incident->id,
            'number' => $incident->number,
            'title' => $incident->title,
            'status' => $incident->status,
            'service_type' => $incident->service_type,
            'network_element' => $incident->network_element,
            'summary' => $incident->summary,
            'area' => $incident->area ? ['name_en' => $incident->area->name_en, 'name_ar' => $incident->area->name_ar] : null,
            'district' => $incident->district ? ['name_en' => $incident->district->name_en, 'name_ar' => $incident->district->name_ar] : null,
            'governorate' => $incident->governorate ? ['name_en' => $incident->governorate->name_en, 'name_ar' => $incident->governorate->name_ar] : null,
            'tickets' => $incident->tickets()->count(),
        ];

        if ($detail) {
            $row['resolution_note'] = $incident->resolution_note;
            $row['resolution_mode'] = $incident->resolution_mode;
            $row['linked'] = $incident->tickets()->with('customer:id,name_en,name_ar,account_no')->latest()->limit(100)->get()->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'customer' => $ticket->customer?->name_en,
                'customer_ar' => $ticket->customer?->name_ar,
            ])->all();
        }

        return $row;
    }

    private function matchingQuery(Incident $incident): Builder
    {
        $query = Ticket::query()->whereNotIn('status', ['resolved', 'closed'])->whereNull('incident_id');

        if ($incident->area_id) {
            $query->whereHas('customer', fn (Builder $customers) => $customers->where('area_id', $incident->area_id));
        } elseif ($incident->district_id) {
            $query->whereHas('customer.area', fn (Builder $areas) => $areas->where('district_id', $incident->district_id));
        } elseif ($incident->governorate_id) {
            $query->whereHas('customer.area.district', fn (Builder $districts) => $districts->where('governorate_id', $incident->governorate_id));
        } elseif (! filled($incident->service_type)) {
            return $query->whereRaw('0 = 1');
        }

        if (filled($incident->service_type)) {
            $type = $incident->service_type;
            $query->where(function (Builder $inner) use ($type) {
                $inner->whereHas('service', fn (Builder $services) => $services->where('service_type', $type))
                    ->orWhereHas('customer.services', fn (Builder $services) => $services->where('service_type', $type));
            });
        }

        return $query;
    }

    private function guardOpen(Incident $incident): void
    {
        if ($incident->status !== 'open') {
            throw ValidationException::withMessages(['incident' => __('ui.incidents.closed')]);
        }
    }

    private function nextNumber(): string
    {
        $last = Incident::query()->lockForUpdate()->orderByDesc('id')->value('number');
        $next = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
            $next = ((int) $match[1]) + 1;
        }

        return 'INC-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
