<?php

namespace App\Services\Leads;

use App\Models\Campaign;
use App\Models\CoverageCheck;
use App\Models\CustomerPhone;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSource;
use App\Models\ReferralCredit;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Automations\AutomationDesk;
use App\Services\Settings\SettingsStore;
use App\Support\Access\AccessGate;
use App\Support\Access\FieldPolicy;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeadDesk
{
    public function __construct(
        private PhoneNumbers $phones,
        private AuditLogger $audit,
        private LeadClock $clock,
        private LeadMachine $machine,
        private AccessGate $access,
        private SettingsStore $settings,
    ) {}

    public function visible(User $viewer): Builder
    {
        $query = Lead::query();
        $scope = $this->access->scopeFor($viewer, 'leads.view');

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($scope === 'all' || $scope === 'team') {
            return $query;
        }

        $queues = [];

        if ($viewer->role === 'cc_agent' || $viewer->hasRole('cc_agent')) {
            $queues[] = 'cc';
        }

        if ($viewer->role === 'sales_agent' || $viewer->hasRole('sales_agent')) {
            $queues[] = 'sales';
        }

        return $query->where(function (Builder $inner) use ($viewer, $queues) {
            $inner->where('owner_id', $viewer->id);

            if ($queues !== []) {
                $inner->orWhere(function (Builder $queue) use ($queues) {
                    $queue->whereNull('owner_id')->whereIn('queue', $queues);
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function capture(array $input, ?User $actor, ?Request $request, bool $unattended = false): Lead
    {
        $phone = $this->optionalPhone($input['phone'] ?? null);
        $alt = $this->optionalPhone($input['alt_phone'] ?? null);
        $whatsapp = $this->optionalPhone($input['whatsapp'] ?? null);

        if ($phone === null) {
            throw new InvalidArgumentException('A phone number is required.');
        }

        $point = $this->mapPoint((string) ($input['location'] ?? ''));
        $matches = $this->matches($phone, $input['email'] ?? null, $input['name'] ?? null, $input['area_id'] ?? null);

        if (! empty($input['attach_to'])) {
            return $this->attach((int) $input['attach_to'], $matches, $actor, $request, (string) ($input['notes'] ?? ''));
        }

        $needsChoice = ($matches['leads'] !== [] || $matches['customers'] !== []) && ! $unattended && empty($input['force']);

        if ($needsChoice && $matches['customers'] !== [] && empty($input['intent'])) {
            throw new LeadDuplicateException($matches);
        }

        if ($needsChoice && $matches['leads'] !== []) {
            throw new LeadDuplicateException($matches);
        }

        if (! empty($input['force']) && trim((string) ($input['force_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['force_reason' => __('ui.leads.reason_required')]);
        }

        $channel = (string) ($input['channel'] ?? 'call');
        $source = LeadSource::query()->where('channel', $channel)->first();
        $sales = $actor instanceof User && in_array($actor->role, ['sales_agent', 'sales_manager'], true);
        $keep = $actor instanceof User && $channel === 'sales' && $sales;
        $name = trim((string) ($input['name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        $lead = Lead::query()->create([
            'name' => $name !== '' ? $name : null,
            'first_name' => $input['first_name'] ?? ($parts[0] ?? null),
            'last_name' => $input['last_name'] ?? ($parts[1] ?? null),
            'phone_e164' => $phone,
            'alt_phone' => $alt,
            'whatsapp_e164' => $whatsapp,
            'email' => $input['email'] ?? null,
            'status' => $keep ? 'assigned' : 'new',
            'source' => $channel,
            'source_id' => $source?->id,
            'campaign_id' => $input['campaign_id'] ?? null,
            'referrer_customer_id' => $input['referrer_customer_id'] ?? null,
            'owner_id' => $keep ? $actor->id : null,
            'team_id' => $actor?->teams()->value('id'),
            'queue' => $keep ? 'sales' : 'cc',
            'service_type_interest' => $input['service_type_interest'] ?? null,
            'area_id' => $input['area_id'] ?? null,
            'address_text' => $input['address_text'] ?? null,
            'latitude' => $input['latitude'] ?? ($point['latitude'] ?? null),
            'longitude' => $input['longitude'] ?? ($point['longitude'] ?? null),
            'brought_by_id' => $input['brought_by_id'] ?? ($channel === 'field_agent' ? $actor?->id : null),
            'language' => $input['language'] ?? app()->getLocale(),
            'create_reason' => $input['force_reason'] ?? null,
            'notes' => $input['notes'] ?? null,
            'utm' => $input['utm'] ?? null,
            'custom' => $this->custom($input, $matches),
            'duplicate_of_id' => $unattended && $matches['leads'] !== [] ? $matches['leads'][0]['id'] : null,
            'sla_stage' => 'first_response',
            'sla_started_at' => now(),
            'sla_due_at' => $this->clock->addOpenMinutes(now(), $this->clock->minutes('lead_first_response_minutes')),
            'qualify_due_at' => $this->clock->addOpenMinutes(now(), $this->clock->minutes('lead_qualify_minutes')),
        ]);

        $this->activity($lead, $actor, 'created', $input['notes'] ?? null);
        app(AutomationDesk::class)->emit('lead.created', [
            'subject_type' => 'lead', 'subject_id' => $lead->id, 'lead_id' => $lead->id, 'label' => (string) $lead->name,
        ]);

        if ($channel === 'referral' && $lead->referrer_customer_id) {
            $credit = ReferralCredit::query()->create([
                'lead_id' => $lead->id,
                'customer_id' => $lead->referrer_customer_id,
                'amount' => 0,
                'currency' => 'USD',
                'status' => 'pending',
                'needs_approval' => true,
            ]);
            $this->audit->record('referral_credit.pending', $actor, [
                'credit_id' => $credit->id,
                'lead_id' => $lead->id,
                'customer_id' => $credit->customer_id,
                'needs_approval' => true,
            ], $request);
            $who = $actor instanceof User ? $actor : User::query()->where('role', 'super_admin')->first();

            if ($who instanceof User) {
                app(ApprovalDesk::class)->open($who, 'referral_reward', 'referral_credit', $credit->id, [
                    'amount' => (string) $credit->amount,
                ], (string) $lead->number, $request);
            }
        }

        if ($unattended && $matches['leads'] !== []) {
            $this->activity($lead, $actor, 'duplicate_notice', __('ui.leads.duplicate'));
            $owner = Lead::query()->find($matches['leads'][0]['id'])?->owner;

            if ($owner instanceof User) {
                Notification::send($owner, new WorkspaceNotification(
                    __('ui.notifications.lead_attached_title', ['number' => $lead->number]),
                    __('ui.notifications.lead_attached_body'),
                ));
            }
        }

        $this->audit->record('lead.created', $actor, [
            'lead_id' => $lead->id,
            'number' => $lead->number,
            'phone_e164' => $lead->phone_e164,
            'channel' => $channel,
            'reason' => $lead->create_reason,
            'campaign_id' => $lead->campaign_id,
        ], $request);

        $this->autoAssign($lead->fresh() ?? $lead);

        return $lead->fresh() ?? $lead;
    }

    /**
     * @param  array<string, mixed>  $matches
     */
    private function attach(int $id, array $matches, ?User $actor, ?Request $request, string $note): Lead
    {
        $ids = collect($matches['leads'])->pluck('id');
        $lead = Lead::query()->findOrFail($id);

        if (! $ids->contains($lead->id)) {
            throw ValidationException::withMessages(['attach_to' => __('ui.leads.duplicate')]);
        }

        $this->activity($lead, $actor, 'attached', $note !== '' ? $note : __('ui.leads.attach'));
        $this->audit->record('lead.attached', $actor, ['lead_id' => $lead->id], $request);
        $owner = $lead->owner;

        if ($owner instanceof User) {
            Notification::send($owner, new WorkspaceNotification(
                __('ui.notifications.lead_attached_title', ['number' => $lead->number]),
                __('ui.notifications.lead_attached_body'),
            ));
        }

        return $lead;
    }

    /**
     * @return array{leads: list<array<string, mixed>>, customers: list<array<string, mixed>>, possible: list<array<string, mixed>>}
     */
    public function matches(?string $phone, mixed $email, mixed $name, mixed $areaId): array
    {
        $leads = [];
        $customers = [];
        $possible = [];
        $e164 = null;

        if (is_string($phone) && trim($phone) !== '') {
            try {
                $e164 = $this->phones->normalize($phone);
            } catch (InvalidArgumentException) {
                $e164 = null;
            }
        }

        if ($e164 !== null) {
            $leads = Lead::query()
                ->where('created_at', '>=', now()->subDays(180))
                ->whereNotIn('status', ['disqualified', 'merged'])
                ->where(function (Builder $inner) use ($e164) {
                    $inner->where('phone_e164', $e164)
                        ->orWhere('alt_phone', $e164)
                        ->orWhere('whatsapp_e164', $e164);
                })
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'number' => $lead->number,
                    'name' => $lead->displayName(),
                    'phone' => $lead->phone_e164,
                ])
                ->all();

            $customers = CustomerPhone::query()
                ->where('phone_e164', $e164)
                ->with('customer:id,name_en,name_ar,account_no')
                ->limit(5)
                ->get()
                ->map(function (CustomerPhone $row) {
                    $customer = $row->customer;

                    return [
                        'id' => $customer?->id,
                        'name' => $customer?->name_en ?? '',
                        'account_no' => $customer?->account_no ?? '',
                    ];
                })
                ->filter(fn (array $row) => $row['id'] !== null)
                ->values()
                ->all();
        }

        if (is_string($email) && trim($email) !== '') {
            $byEmail = Lead::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
                ->where('created_at', '>=', now()->subDays(180))
                ->whereNotIn('status', ['disqualified', 'merged'])
                ->limit(5)
                ->get();

            foreach ($byEmail as $lead) {
                $leads[] = [
                    'id' => $lead->id,
                    'number' => $lead->number,
                    'name' => $lead->displayName(),
                    'phone' => $lead->phone_e164,
                ];
            }
        }

        if (is_string($name) && trim($name) !== '' && is_numeric($areaId)) {
            Lead::query()
                ->where('area_id', (int) $areaId)
                ->where('created_at', '>=', now()->subDays(180))
                ->whereNotIn('status', ['disqualified', 'merged'])
                ->latest('id')
                ->limit(40)
                ->get()
                ->each(function (Lead $lead) use (&$possible, $name) {
                    $percent = 0.0;
                    similar_text(mb_strtolower(trim($name)), mb_strtolower($lead->displayName()), $percent);

                    if ($percent >= 60) {
                        $possible[] = [
                            'id' => $lead->id,
                            'number' => $lead->number,
                            'name' => $lead->displayName(),
                        ];
                    }
                });
        }

        return [
            'leads' => collect($leads)->unique('id')->values()->all(),
            'customers' => $customers,
            'possible' => $possible,
        ];
    }

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    public function mapPoint(string $text): ?array
    {
        $patterns = [
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return ['latitude' => $match[1], 'longitude' => $match[2]];
            }
        }

        return null;
    }

    public function claim(Lead $lead, User $actor, Request $request): void
    {
        DB::transaction(function () use ($lead, $actor, $request): void {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $queue = $actor->role === 'sales_agent' ? 'sales' : 'cc';

            if ($locked->owner_id !== null && (int) $locked->owner_id !== $actor->id) {
                throw ValidationException::withMessages(['lead' => __('ui.leads.taken')]);
            }

            if ($locked->queue !== $queue || ! in_array($locked->status, ['new', 'qualified'], true)) {
                throw ValidationException::withMessages(['lead' => __('ui.leads.taken')]);
            }

            $this->guardCap($actor);
            $locked->owner_id = $actor->id;
            $locked->claimed_at = now();

            if ($locked->status === 'new') {
                $locked->status = 'assigned';
            }

            $locked->save();
            $this->activity($locked, $actor, 'claimed', null);
            $this->audit->record('lead.claimed', $actor, ['lead_id' => $locked->id], $request);
        });
    }

    public function assign(Lead $lead, User $assignee, User $actor, Request $request): void
    {
        DB::transaction(function () use ($lead, $assignee, $actor, $request): void {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $locked->owner_id = $assignee->id;
            $locked->claimed_at = now();

            if ($locked->status === 'new' && $this->machine->allows('new', 'assigned')) {
                $locked->status = 'assigned';
            }

            $locked->save();
            $this->activity($locked, $actor, 'assigned', $assignee->name);
            $this->audit->record('lead.assigned', $actor, [
                'lead_id' => $locked->id,
                'assignee_id' => $assignee->id,
            ], $request);
        });
    }

    public function transition(Lead $lead, string $to, User $actor, Request $request, array $extra = []): void
    {
        if ($to === 'assigned' && $lead->status === 'disqualified' && ! $this->access->allows($actor, 'leads.assign')) {
            throw ValidationException::withMessages(['status' => __('ui.leads.taken')]);
        }

        if (! $this->machine->allows($lead->status, $to)) {
            throw ValidationException::withMessages(['status' => __('ui.leads.taken')]);
        }

        if ($to === 'qualified' && ! $this->canQualify($lead)) {
            throw ValidationException::withMessages(['status' => __('ui.leads.qualify_needs')]);
        }

        if ($to === 'disqualified' && empty($extra['disqualify_reason_id'])) {
            throw ValidationException::withMessages(['disqualify_reason_id' => __('ui.leads.reason_required')]);
        }

        if ($to === 'merged' && empty($extra['duplicate_of_id'])) {
            throw ValidationException::withMessages(['duplicate_of_id' => __('ui.leads.reason_required')]);
        }

        $from = $lead->status;
        $lead->status = $to;

        if ($to === 'contacted' && $lead->first_response_at === null) {
            $lead->first_response_at = now();

            if ($lead->sla_breached_at === null) {
                $lead->sla_stage = 'qualify';
                $lead->sla_started_at = $lead->created_at;
                $lead->sla_due_at = $lead->qualify_due_at;
            }
        }

        if ($to === 'qualified') {
            $lead->queue = 'sales';
            $lead->owner_id = null;
            $lead->claimed_at = null;
            $lead->handoff_due_at = $this->clock->addOpenMinutes(now(), $this->clock->minutes('lead_handoff_minutes'));

            if ($lead->sla_breached_at === null) {
                $lead->sla_stage = 'handoff';
                $lead->sla_started_at = now();
                $lead->sla_due_at = $lead->handoff_due_at;
            }
        }

        if ($to === 'converted') {
            $lead->handed_to_sales_at = now();
        }

        if ($to === 'disqualified') {
            $lead->disqualify_reason_id = $extra['disqualify_reason_id'];
        }

        if ($to === 'merged') {
            $lead->duplicate_of_id = $extra['duplicate_of_id'];
        }

        $lead->save();
        $this->activity($lead, $actor, 'status', $from.' → '.$to);
        $this->audit->record('lead.transition', $actor, [
            'lead_id' => $lead->id,
            'from' => $from,
            'to' => $to,
            'reason_id' => $lead->disqualify_reason_id,
        ], $request);
    }

    public function noAnswer(Lead $lead, User $actor, Request $request): void
    {
        $lead->no_answer_count = (int) $lead->no_answer_count + 1;
        $lead->save();
        $this->activity($lead, $actor, 'no_answer', (string) $lead->no_answer_count);
        $limit = (int) ($this->assignment()['no_answer_limit'] ?? 3);

        if ($lead->status === 'contacted' && $lead->no_answer_count >= $limit && $this->machine->allows('contacted', 'unreachable')) {
            $this->transition($lead, 'unreachable', $actor, $request);
        }
    }

    public function note(Lead $lead, User $actor, string $body, Request $request): void
    {
        $this->activity($lead, $actor, 'note', $body);
        $this->audit->record('lead.note', $actor, ['lead_id' => $lead->id], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Lead $lead, array $input, User $actor, Request $request): void
    {
        if (array_key_exists('phone', $input) && is_string($input['phone']) && $input['phone'] !== '') {
            $phone = $this->phones->normalize($input['phone']);
            $matches = $this->matches($phone, $input['email'] ?? $lead->email, $input['name'] ?? $lead->name, $input['area_id'] ?? $lead->area_id);
            $matches['leads'] = array_values(array_filter($matches['leads'], fn (array $row) => (int) $row['id'] !== $lead->id));

            if ($matches['leads'] !== [] && empty($input['force'])) {
                throw new LeadDuplicateException($matches);
            }

            $lead->phone_e164 = $phone;
        }

        foreach (['name', 'email', 'service_type_interest', 'address_text', 'notes', 'language'] as $field) {
            if (array_key_exists($field, $input)) {
                $lead->{$field} = $input[$field];
            }
        }

        if (array_key_exists('area_id', $input)) {
            $lead->area_id = $input['area_id'] ?: null;
        }

        $point = $this->mapPoint((string) ($input['location'] ?? ''));

        if ($point !== null) {
            $lead->latitude = $point['latitude'];
            $lead->longitude = $point['longitude'];
        }

        $lead->save();
        $this->audit->record('lead.updated', $actor, ['lead_id' => $lead->id], $request);
    }

    public function sweep(): int
    {
        $sent = 0;
        $open = ['new', 'assigned', 'contacted', 'qualified', 'unreachable', 'waitlisted'];

        Lead::query()
            ->whereNull('sla_breached_at')
            ->whereNotNull('sla_due_at')
            ->whereIn('status', $open)
            ->orderBy('id')
            ->each(function (Lead $lead) use (&$sent): void {
                $started = $lead->sla_started_at ?? $lead->created_at ?? now();
                $due = $lead->sla_due_at;

                if ($due === null || $started === null) {
                    return;
                }

                $total = max(1, $due->getTimestamp() - $started->getTimestamp());
                $elapsed = now()->getTimestamp() - $started->getTimestamp();
                $ratio = $elapsed / $total;

                if ($due->lessThanOrEqualTo(now())) {
                    $lead->sla_breached_at = now();
                    $lead->save();
                    $this->notifySupervisors(
                        __('ui.notifications.lead_breach_title', ['number' => $lead->number]),
                        __('ui.notifications.lead_breach_body'),
                    );
                    $this->audit->record('lead.sla_breached', null, ['lead_id' => $lead->id, 'stage' => $lead->sla_stage]);
                    $sent++;

                    return;
                }

                if ($ratio >= 0.8 && $lead->sla_warned_at === null) {
                    $lead->sla_warned_at = now();
                    $lead->save();
                    $this->notifySupervisors(
                        __('ui.notifications.lead_warning_title', ['number' => $lead->number]),
                        __('ui.notifications.lead_warning_body'),
                    );
                    $sent++;
                }
            });

        return $sent;
    }

    public function importCsv(string $contents, int $campaignId, User $actor, Request $request): int
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($contents)) ?: [];
        $header = str_getcsv((string) array_shift($lines));
        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), $header);
        $made = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $row = [];

            foreach ($header as $index => $key) {
                $row[$key] = $cells[$index] ?? null;
            }

            $this->capture([
                'name' => $row['name'] ?? null,
                'phone' => $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'channel' => 'social_ad',
                'campaign_id' => $campaignId,
                'force' => true,
                'force_reason' => 'csv',
            ], $actor, $request, true);
            $made++;
        }

        return $made;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function metaLead(array $payload): ?Lead
    {
        $changes = $payload['entry'][0]['changes'][0]['value'] ?? null;

        if (! is_array($changes)) {
            return null;
        }

        $fields = [];

        foreach ($changes['field_data'] ?? [] as $field) {
            if (is_array($field) && isset($field['name'])) {
                $fields[(string) $field['name']] = $field['values'][0] ?? null;
            }
        }

        $external = (string) ($changes['campaign_id'] ?? '');
        $campaign = $external !== '' ? Campaign::query()->where('external_id', $external)->first() : null;

        return $this->capture([
            'name' => $fields['full_name'] ?? $fields['name'] ?? null,
            'phone' => $fields['phone_number'] ?? $fields['phone'] ?? null,
            'email' => $fields['email'] ?? null,
            'channel' => 'social_ad',
            'campaign_id' => $campaign?->id,
            'force' => true,
            'force_reason' => 'meta',
            'utm' => ['campaign_id' => $external],
        ], null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(): array
    {
        return $this->settings->get('lead_assignment', [
            'round_robin' => false,
            'by_area' => false,
            'by_language' => false,
            'by_service' => false,
            'cap' => 0,
            'no_answer_limit' => 3,
        ]);
    }

    public function saveAssignment(array $value, User $actor, Request $request): void
    {
        $this->settings->put('lead_assignment', $value, $actor, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Lead $lead, User $viewer): array
    {
        $masked = app(FieldPolicy::class)->present($viewer, [
            'phone' => $lead->phone_e164,
            'whatsapp' => $lead->whatsapp_e164,
            'email' => $lead->email,
            'address' => $lead->address_text,
        ]);
        $digits = is_string($masked['phone'] ?? null) ? preg_replace('/\D+/', '', (string) $masked['phone']) : '';

        return [
            'id' => $lead->id,
            'number' => $lead->number,
            'name' => $lead->displayName(),
            'phone' => $masked['phone'] ?? null,
            'email' => $masked['email'] ?? null,
            'status' => $lead->status,
            'channel' => $lead->source,
            'queue' => $lead->queue,
            'owner' => $lead->owner?->name,
            'owner_id' => $lead->owner_id,
            'due_at' => $lead->sla_due_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            'breached' => $lead->sla_breached_at !== null,
            'warned' => $lead->sla_warned_at !== null,
            'service' => $lead->service_type_interest,
            'area_id' => $lead->area_id,
            'address' => $masked['address'] ?? null,
            'notes' => $lead->notes,
            'campaign_id' => $lead->campaign_id,
            'whatsapp_url' => $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode(__('ui.leads.whatsapp_template')) : null,
        ];
    }

    public function breachedCount(): int
    {
        return Lead::query()
            ->whereNotNull('sla_breached_at')
            ->whereNotIn('status', ['converted', 'disqualified', 'merged'])
            ->count();
    }

    private function canQualify(Lead $lead): bool
    {
        $located = $lead->area_id !== null || ($lead->latitude !== null && $lead->longitude !== null);
        $check = CoverageCheck::query()
            ->where('subject_type', 'lead')
            ->where('subject_id', $lead->id)
            ->latest('id')
            ->first();
        $allowed = $check && in_array($check->result, ['covered', 'near', 'needs_survey'], true);

        return $lead->phone_e164 !== '' && $located && filled($lead->service_type_interest) && $allowed;
    }

    private function guardCap(User $actor): void
    {
        $cap = (int) ($this->assignment()['cap'] ?? 0);

        if ($cap < 1) {
            return;
        }

        $open = Lead::query()
            ->where('owner_id', $actor->id)
            ->whereNotIn('status', ['converted', 'disqualified', 'merged'])
            ->count();

        if ($open >= $cap) {
            throw ValidationException::withMessages(['lead' => __('ui.leads.cap_hit')]);
        }
    }

    private function autoAssign(Lead $lead): void
    {
        $options = $this->assignment();

        if ($lead->owner_id !== null || empty($options['round_robin'])) {
            return;
        }

        if (! empty($options['by_area'])) {
            return;
        }

        $role = $lead->queue === 'sales' ? 'sales_agent' : 'cc_agent';
        $candidates = User::query()->where('role', $role)->where('is_active', true)->get();

        if (! empty($options['by_language']) && $lead->language) {
            $candidates = $candidates->filter(fn (User $user) => $user->locale === $lead->language)->values();
        }

        $cap = (int) ($options['cap'] ?? 0);
        $chosen = null;
        $lowest = null;

        foreach ($candidates as $candidate) {
            $open = Lead::query()->where('owner_id', $candidate->id)->whereNotIn('status', ['converted', 'disqualified', 'merged'])->count();

            if ($cap > 0 && $open >= $cap) {
                continue;
            }

            if ($lowest === null || $open < $lowest) {
                $lowest = $open;
                $chosen = $candidate;
            }
        }

        if ($chosen instanceof User) {
            $lead->owner_id = $chosen->id;
            $lead->claimed_at = now();

            if ($lead->status === 'new') {
                $lead->status = 'assigned';
            }

            $lead->save();
        }
    }

    private function notifySupervisors(string $title, string $body): void
    {
        $people = User::query()
            ->where('is_active', true)
            ->where(function (Builder $inner) {
                $inner->whereIn('role', ['cc_supervisor', 'sales_manager'])
                    ->orWhereHas('roles', fn (Builder $roles) => $roles->whereIn('key', ['cc_supervisor', 'sales_manager']));
            })
            ->get();

        if ($people->isNotEmpty()) {
            Notification::send($people, new WorkspaceNotification($title, $body));
        }
    }

    private function activity(Lead $lead, ?User $actor, string $kind, ?string $body): void
    {
        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'kind' => $kind,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $matches
     * @return array<string, mixed>|null
     */
    private function custom(array $input, array $matches): ?array
    {
        $custom = [];

        if (! empty($input['intent'])) {
            $custom['intent'] = $input['intent'];
        }

        if ($matches['customers'] !== []) {
            $custom['existing_customer_id'] = $matches['customers'][0]['id'];
        }

        return $custom === [] ? null : $custom;
    }

    private function optionalPhone(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->phones->normalize($value);
    }
}
