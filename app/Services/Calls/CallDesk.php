<?php

namespace App\Services\Calls;

use App\Models\Customer;
use App\Models\EscalationTier;
use App\Models\Incident;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WrapUpCode;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerRecords;
use App\Services\Leads\LeadDesk;
use App\Services\Tickets\IncidentRecords;
use App\Services\Tickets\TicketRecords;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class CallDesk
{
    public function __construct(
        private PhoneNumbers $phones,
        private CustomerRecords $customers,
        private TicketRecords $tickets,
        private IncidentRecords $incidents,
        private AuditLogger $audit,
        private LeadDesk $leads,
    ) {}

    public function seedCodes(): void
    {
        $rows = [
            ['resolved', 'Resolved on the call', 'حُل أثناء المكالمة'],
            ['ticket_opened', 'Ticket opened', 'فُتحت تذكرة'],
            ['callback', 'Callback', 'اتصال لاحق'],
            ['no_answer', 'No answer', 'لا رد'],
            ['wrong_number', 'Wrong number', 'رقم خاطئ'],
            ['info', 'Information given', 'أُعطيت معلومة'],
            ['escalated', 'Escalated', 'تم التصعيد'],
            ['other', 'Other', 'أخرى'],
        ];

        foreach ($rows as $index => [$key, $en, $ar]) {
            WrapUpCode::query()->updateOrCreate(['key' => $key], [
                'name_en' => $en,
                'name_ar' => $ar,
                'is_active' => true,
                'sort' => $index,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $raw, User $viewer): array
    {
        $e164 = $this->tryNormalize($raw);
        $digits = preg_replace('/\D+/', '', $e164 ?? $raw) ?? '';
        $customer = null;

        if ($digits !== '') {
            $customer = $this->customers->query($viewer)
                ->with(['phones', 'services', 'area.district.governorate'])
                ->where(function ($inner) use ($digits) {
                    $inner->whereHas('phones', fn ($phones) => $phones->where('phone_e164', 'like', '%'.$digits.'%'))
                        ->orWhere('whatsapp_e164', 'like', '%'.$digits.'%');
                })
                ->first();
        }

        if (! $customer instanceof Customer) {
            return [
                'matched' => false,
                'phone' => $e164,
                'phone_display' => $e164 ? $this->phones->display($e164) : $raw,
                'whatsapp_url' => $this->whatsappUrl($e164),
            ];
        }

        $card = $this->customers->present($customer, $viewer, true);
        $openTickets = collect($this->tickets->forCustomer($customer, $viewer))
            ->filter(fn (array $ticket) => ! in_array($ticket['status'], ['resolved', 'closed'], true))
            ->values()
            ->all();

        return [
            'matched' => true,
            'phone' => $e164,
            'phone_display' => $e164 ? $this->phones->display($e164) : $raw,
            'whatsapp_url' => $this->whatsappUrl($customer->whatsapp_e164 ?: $e164),
            'customer' => $card,
            'open_tickets' => $openTickets,
            'incidents' => $this->incidents->bannersFor($customer),
            'interactions' => $this->recent($customer->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function log(array $input, User $actor, Request $request): Interaction
    {
        $phone = $this->tryNormalize((string) ($input['phone'] ?? ''));
        $interaction = Interaction::query()->create([
            'direction' => $input['direction'],
            'channel' => $input['channel'],
            'wrap_up_code_id' => $input['wrap_up_code_id'],
            'duration_seconds' => isset($input['duration_minutes']) && $input['duration_minutes'] !== ''
                ? (int) $input['duration_minutes'] * 60
                : null,
            'notes' => $input['notes'] ?? null,
            'phone_e164' => $phone,
            'customer_id' => $input['customer_id'] ?? null,
            'ticket_id' => $input['ticket_id'] ?? null,
            'lead_id' => $input['lead_id'] ?? null,
            'user_id' => $actor->id,
            'callback_at' => $input['callback_at'] ?? null,
        ]);
        $this->audit->record('call.logged', $actor, [
            'interaction_id' => $interaction->id,
            'customer_id' => $interaction->customer_id,
            'ticket_id' => $interaction->ticket_id,
            'lead_id' => $interaction->lead_id,
            'direction' => $interaction->direction,
            'channel' => $interaction->channel,
        ], $request);

        return $interaction;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createLead(array $input, User $actor, Request $request): Lead
    {
        return $this->leads->capture([
            'name' => $input['name'] ?? null,
            'phone' => $input['phone'],
            'notes' => $input['notes'] ?? null,
            'channel' => 'call',
        ], $actor, $request);
    }

    public function setDuty(User $actor, bool $onDuty, Request $request): void
    {
        $tier = $this->tierFor($actor);
        $tier->members()->syncWithoutDetaching([
            $actor->id => ['on_duty' => $onDuty],
        ]);
        $this->audit->record('call.duty', $actor, [
            'user_id' => $actor->id,
            'on_duty' => $onDuty,
            'tier_id' => $tier->id,
        ], $request);
    }

    public function onDuty(User $user): bool
    {
        return $user->escalationTiers()->wherePivot('on_duty', true)->exists();
    }

    public function setExtension(User $actor, ?string $extension, Request $request): void
    {
        $actor->extension = $extension === '' ? null : $extension;
        $actor->save();
        $this->audit->record('user.extension', $actor, [
            'user_id' => $actor->id,
            'extension' => $actor->extension,
        ], $request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function rememberPop(string $extension, array $payload): void
    {
        Cache::put($this->popKey($extension), $payload, now()->addMinutes(2));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pullPop(User $user): ?array
    {
        if (! filled($user->extension)) {
            return null;
        }

        $payload = Cache::pull($this->popKey((string) $user->extension));

        return is_array($payload) ? $payload : null;
    }

    public function whatsappUrl(?string $e164): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164) ?? '';

        return $digits === '' ? null : 'https://wa.me/'.$digits;
    }

    public function tryNormalize(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return $this->phones->normalize($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function tierFor(User $user): EscalationTier
    {
        $existing = $user->escalationTiers()->orderBy('level')->first();

        if ($existing instanceof EscalationTier) {
            return $existing;
        }

        $level = match (true) {
            $user->hasRole('noc_engineer') => 2,
            $user->hasRole('field_technician'), $user->hasRole('installer') => 3,
            default => 1,
        };

        return EscalationTier::query()->where('level', $level)->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(int $customerId): array
    {
        return Interaction::query()
            ->with(['wrapUpCode', 'agent:id,name'])
            ->where('customer_id', $customerId)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Interaction $row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'channel' => $row->channel,
                'notes' => $row->notes,
                'duration_seconds' => $row->duration_seconds,
                'agent' => $row->agent?->name,
                'wrap_up_en' => $row->wrapUpCode?->name_en,
                'wrap_up_ar' => $row->wrapUpCode?->name_ar,
                'at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function popKey(string $extension): string
    {
        return 'telephony:pop:'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $tiers = EscalationTier::query()->with(['members:id,name,extension'])->orderBy('level')->get();
        $open = Ticket::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->selectRaw('escalation_tier_id, count(*) as total')
            ->groupBy('escalation_tier_id')
            ->pluck('total', 'escalation_tier_id');

        return [
            'tiers' => $tiers->map(fn (EscalationTier $tier) => [
                'id' => $tier->id,
                'level' => $tier->level,
                'name_en' => $tier->name_en,
                'name_ar' => $tier->name_ar,
                'open_tickets' => (int) ($open[$tier->id] ?? 0),
                'agents' => $tier->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'extension' => $user->extension,
                    'on_duty' => (bool) $user->pivot->on_duty,
                ])->values(),
            ])->all(),
            'at_risk' => Ticket::query()
                ->with('tier')
                ->whereNotIn('status', ['resolved', 'closed'])
                ->where(function ($inner) {
                    $inner->where('response_warned', true)
                        ->orWhere('resolve_warned', true)
                        ->orWhere('response_breached', true)
                        ->orWhere('resolve_breached', true);
                })
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    'number' => $ticket->number,
                    'subject' => $ticket->subject,
                    'priority' => $ticket->priority,
                    'breached' => $ticket->response_breached || $ticket->resolve_breached,
                    'tier_en' => $ticket->tier?->name_en,
                    'tier_ar' => $ticket->tier?->name_ar,
                ])
                ->all(),
            'incidents' => Incident::query()->where('status', 'open')->latest()->limit(20)->get(['id', 'number', 'title'])->map(fn (Incident $incident) => [
                'id' => $incident->id,
                'number' => $incident->number,
                'title' => $incident->title,
            ])->all(),
            'lead_breaches' => $this->leads->breachedCount(),
        ];
    }
}
