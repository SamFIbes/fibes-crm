<?php

namespace App\Services\Onboarding;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\OnboardingCard;
use App\Models\OnboardingSetting;
use App\Models\OnboardingStage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Leads\LeadDesk;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OnboardingDesk
{
    public function __construct(
        private AccessGate $access,
        private LeadDesk $leads,
        private AuditLogger $audit,
    ) {}

    public function canView(User $user): bool
    {
        return $this->access->allows($user, 'leads.view')
            || $this->access->allows($user, 'opportunities.view');
    }

    public function canEdit(User $user): bool
    {
        return $this->access->allows($user, 'leads.edit')
            || $this->access->allows($user, 'opportunities.edit');
    }

    public function canManage(User $user): bool
    {
        return $this->access->allows($user, 'settings.manage')
            || $this->access->allows($user, 'leads.assign');
    }

    /** @return Builder<OnboardingCard> */
    public function visible(User $user): Builder
    {
        $query = OnboardingCard::query();

        if (! $this->canView($user)) {
            return $query->whereRaw('0 = 1');
        }

        if ($this->access->scopeFor($user, 'leads.view') === 'all'
            || $this->access->scopeFor($user, 'opportunities.view') === 'all') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->id)
                ->orWhere('salesperson_id', $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('owner_id', $user->id))
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('owner_id', $user->id));
        });
    }

    public function ensureStages(): void
    {
        if (OnboardingStage::query()->exists()) {
            return;
        }

        $stages = config('fibes.onboarding.stages');

        if (! is_array($stages) || $stages === []) {
            $stages = $this->defaultStages();
        }

        $timestamp = now();

        foreach ($stages as $stage) {
            DB::table('onboarding_stages')->insertOrIgnore([
                'key' => $stage['key'],
                'name_en' => $stage['name_en'],
                'name_ar' => $stage['name_ar'],
                'position' => $stage['position'],
                'task_title_en' => $stage['task_title_en'],
                'task_title_ar' => $stage['task_title_ar'],
                'is_terminal' => $stage['is_terminal'] ?? false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /** @return Collection<int, OnboardingStage> */
    public function stages(): Collection
    {
        $this->ensureStages();

        return OnboardingStage::query()->orderBy('position')->orderBy('id')->get();
    }

    public function staleDays(): int
    {
        return $this->settingDays('stale_days', 5, 90);
    }

    public function backfillDays(): int
    {
        return $this->settingDays('backfill_days', 30, 365);
    }

    private function settingDays(string $key, int $default, int $max): int
    {
        $value = OnboardingSetting::query()->find($key)?->value
            ?? config("fibes.onboarding.{$key}", $default);

        return max(1, min($max, is_numeric($value) ? (int) $value : $default));
    }

    public function terminalKey(): ?string
    {
        $stages = $this->stages();

        return $stages->firstWhere('is_terminal', true)?->key ?? $stages->last()?->key;
    }

    public function isStale(OnboardingCard $card): bool
    {
        return $card->stage_key !== $this->terminalKey()
            && $card->last_activity_at->lessThanOrEqualTo(now()->subDays($this->staleDays()));
    }

    public function followupDue(OnboardingCard $card): bool
    {
        $card->loadMissing('followup');

        return $card->followup !== null
            && $card->followup->due_on->format('Y-m-d') <= now()->toDateString();
    }

    public function needsAttention(OnboardingCard $card): bool
    {
        return $card->stage_key !== $this->terminalKey()
            && ($this->isStale($card) || $this->followupDue($card));
    }

    public function present(OnboardingCard $card, User $user): array
    {
        $card->loadMissing(['area', 'assignee', 'followup']);
        $stage = $this->stages()->firstWhere('key', $card->stage_key);

        return [
            'id' => $card->id,
            'title' => $card->title,
            'area_id' => $card->area_id,
            'area_en' => $card->area?->name_en,
            'area_ar' => $card->area?->name_ar,
            'client_name' => $card->client_name,
            'source_kind' => $card->source_kind,
            'source_id' => $card->source_id,
            'salesperson_name' => $card->salesperson_name,
            'salesperson_id' => $card->salesperson_id,
            'stage_key' => $card->stage_key,
            'stage_entered_at' => $card->stage_entered_at->format('Y-m-d H:i'),
            'last_activity_at' => $card->last_activity_at->format('Y-m-d H:i'),
            'assigned_to' => $card->assigned_to,
            'assignee_name' => $card->assignee?->name,
            'days_in_stage' => max(0, (int) $card->stage_entered_at->diffInDays(now(), false)),
            'task_title_en' => $stage?->task_title_en,
            'task_title_ar' => $stage?->task_title_ar,
            'stale' => $this->isStale($card),
            'followup_due' => $card->stage_key !== $this->terminalKey() && $this->followupDue($card),
            'followup_on' => $card->followup?->due_on?->format('Y-m-d'),
            'lead_id' => $card->lead_id,
            'customer_id' => $card->customer_id,
            'language' => $card->language,
        ];
    }

    public function meta(User $user, array $filters = []): array
    {
        $counts = $this->filtered($user, $filters)
            ->selectRaw('stage_key, count(*) as aggregate')
            ->groupBy('stage_key')
            ->pluck('aggregate', 'stage_key');

        $visible = $this->visible($user);
        $people = User::query()
            ->where(function (Builder $query) use ($visible): void {
                $query->whereIn('id', (clone $visible)->select('assigned_to')->whereNotNull('assigned_to'))
                    ->orWhereIn('id', (clone $visible)->select('salesperson_id')->whereNotNull('salesperson_id'))
                    ->orWhere(function (Builder $query): void {
                        $query->where('is_active', true)
                            ->whereIn('role', ['sales_agent', 'sales_manager', 'super_admin', 'cc_supervisor']);
                    });
            })
            ->orderBy('name')->limit(100)->get(['id', 'name']);

        return [
            'stages' => $this->stages()->map(fn (OnboardingStage $stage): array => [
                'key' => $stage->key,
                'name_en' => $stage->name_en,
                'name_ar' => $stage->name_ar,
                'position' => $stage->position,
                'is_terminal' => $stage->is_terminal,
                'count' => (int) ($counts[$stage->key] ?? 0),
            ])->values()->all(),
            'people' => $people,
            'areas' => Area::query()->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_ar']),
            'stale_days' => $this->staleDays(),
            'can_edit' => $this->canEdit($user),
            'can_manage' => $this->canManage($user),
            'attention' => $this->attention($user),
        ];
    }

    public function column(User $user, string $stageKey, array $filters = [], ?int $afterId = null): array
    {
        $this->ensureStages();
        abort_unless(OnboardingStage::query()->where('key', $stageKey)->exists(), 404);

        $size = max(1, min(100, (int) config('fibes.onboarding.column_page', 20)));
        $query = $this->filtered($user, $filters)
            ->where('stage_key', $stageKey)
            ->with(['area', 'assignee', 'followup'])
            ->orderByDesc('id');

        if ($afterId !== null) {
            $query->where('id', '<', $afterId);
        }

        $rows = $query->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $items = $rows->take($size);

        return [
            'items' => $items->map(fn (OnboardingCard $card) => $this->present($card, $user))->values()->all(),
            'next_cursor' => $hasMore ? $items->last()?->id : null,
            'has_more' => $hasMore,
        ];
    }

    /** @return Builder<OnboardingCard> */
    private function filtered(User $user, array $filters): Builder
    {
        $query = $this->visible($user);

        if (! empty($filters['assignee'])) {
            $query->where('assigned_to', $filters['assignee'] === 'me' ? $user->id : $filters['assignee']);
        }

        if (! empty($filters['area_id'])) {
            $query->where('area_id', $filters['area_id']);
        }

        if (! empty($filters['mine'])) {
            $query->where('assigned_to', $user->id);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn (Builder $query) => $query->where('title', 'like', $term)
                ->orWhere('client_name', 'like', $term)
                ->orWhere('salesperson_name', 'like', $term));
        }

        if (! empty($filters['stale'])) {
            $this->applyAttention($query);
        }

        return $query;
    }

    private function applyAttention(Builder $query): void
    {
        $terminal = $this->terminalKey();

        if ($terminal !== null) {
            $query->where('stage_key', '!=', $terminal);
        }

        $query->where(function (Builder $query): void {
            $query->where('last_activity_at', '<=', now()->subDays($this->staleDays()))
                ->orWhereHas('followup', fn (Builder $followup) => $followup->whereDate('due_on', '<=', now()->toDateString()));
        });
    }

    public function attention(User $user): array
    {
        $query = $this->visible($user);
        $this->applyAttention($query);

        return [
            'total' => (clone $query)->count(),
            'mine' => (clone $query)->where('assigned_to', $user->id)->count(),
        ];
    }

    public function move(User $user, OnboardingCard $card, string $toStageKey): OnboardingCard
    {
        return DB::transaction(function () use ($user, $card, $toStageKey): OnboardingCard {
            $this->ensureStages();
            $stage = OnboardingStage::query()->where('key', $toStageKey)->lockForUpdate()->first();

            if (! $stage) {
                throw ValidationException::withMessages(['stage' => __('ui.onboarding.invalid_stage')]);
            }

            $card = OnboardingCard::query()->lockForUpdate()->findOrFail($card->id);

            if ($card->stage_key === $toStageKey) {
                return $card;
            }

            $from = $card->stage_key;
            $card->update(['stage_key' => $toStageKey, 'stage_entered_at' => now(), 'last_activity_at' => now()]);
            $this->event($card, $user, 'moved', null, $from, $toStageKey);
            $this->audit->record('onboarding.moved', $user, ['card_id' => $card->id, 'from_stage' => $from, 'to_stage' => $toStageKey]);

            return $card;
        });
    }

    public function assign(User $user, OnboardingCard $card, ?int $userId): OnboardingCard
    {
        return DB::transaction(function () use ($user, $card, $userId): OnboardingCard {
            $card->update(['assigned_to' => $userId, 'last_activity_at' => now()]);
            $this->event($card, $user, 'assigned', $userId === null ? null : (string) $userId);
            $this->audit->record('onboarding.assigned', $user, ['card_id' => $card->id, 'assigned_to' => $userId]);

            return $card;
        });
    }

    public function addNote(User $user, OnboardingCard $card, string $body): void
    {
        $data = Validator::make(['body' => trim($body)], [
            'body' => ['required', 'string', 'max:2000'],
        ])->validate();

        DB::transaction(function () use ($user, $card, $data): void {
            $this->event($card, $user, 'note', $data['body']);
            $card->update(['last_activity_at' => now()]);
        });
    }

    public function setFollowup(User $user, OnboardingCard $card, ?string $dueOn, ?string $note): void
    {
        $data = Validator::make(['due_on' => $dueOn, 'note' => $note], [
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        DB::transaction(function () use ($user, $card, $data): void {
            if (empty($data['due_on'])) {
                $card->followup()->delete();
            } else {
                $card->followup()->updateOrCreate([], [
                    'due_on' => $data['due_on'],
                    'note' => $data['note'] ?? null,
                    'set_by' => $user->id,
                    'set_at' => now(),
                ]);
            }

            $this->event($card, $user, 'followup', $data['due_on'] ?? null);
            $this->audit->record('onboarding.followup', $user, ['card_id' => $card->id, 'due_on' => $data['due_on'] ?? null]);
        });

        $card->unsetRelation('followup');
    }

    public function snooze(User $user, OnboardingCard $card, int $days): void
    {
        if (! in_array($days, [1, 3, 7], true)) {
            throw ValidationException::withMessages(['snooze_days' => __('ui.onboarding.invalid_snooze')]);
        }

        DB::transaction(function () use ($user, $card, $days): void {
            $card = OnboardingCard::query()->lockForUpdate()->findOrFail($card->id);
            $date = now()->startOfDay()->addDays($days)->toDateString();
            $note = $card->followup()->value('note');

            $card->followup()->updateOrCreate([], [
                'due_on' => $date,
                'note' => $note,
                'set_by' => $user->id,
                'set_at' => now(),
            ]);
            $this->event($card, $user, 'snoozed', $date);
            $this->audit->record('onboarding.followup', $user, ['card_id' => $card->id, 'due_on' => $date, 'snooze_days' => $days]);
        });

        $card->unsetRelation('followup');
    }

    public function detail(User $user, OnboardingCard $card): array
    {
        $card->loadMissing(['lead', 'customer', 'followup']);

        return $this->present($card, $user) + [
            'client_phone' => $card->client_phone,
            'client_email' => $card->client_email,
            'events' => $card->events()->orderByDesc('id')->limit(100)->get([
                'id', 'occurred_at', 'actor_id', 'actor_name', 'kind', 'from_stage', 'to_stage', 'detail',
            ]),
            'followup' => $card->followup,
            'lead_number' => $card->lead?->number,
            'customer_account_no' => $card->customer?->account_no,
            'next_stages' => $this->stages()->map(fn (OnboardingStage $stage): array => [
                'key' => $stage->key,
                'name_en' => $stage->name_en,
                'name_ar' => $stage->name_ar,
                'position' => $stage->position,
                'is_terminal' => $stage->is_terminal,
            ])->values()->all(),
        ];
    }

    public function createManual(User $user, array $data): OnboardingCard
    {
        $data = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'language' => ['nullable', 'string', 'max:5'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        return DB::transaction(function () use ($user, $data): OnboardingCard {
            $salesperson = isset($data['salesperson_id']) ? User::query()->find($data['salesperson_id']) : null;
            $card = OnboardingCard::query()->create($data + [
                'source_kind' => 'manual',
                'source_id' => null,
                'salesperson_name' => $salesperson?->name,
                'stage_key' => $this->firstStageKey(),
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
            ]);
            $this->event($card, $user, 'created');

            return $card;
        });
    }

    public function fromLead(User $user, Lead $lead): OnboardingCard
    {
        abort_unless($this->leads->visible($user)->whereKey($lead->id)->exists(), 404);

        return $this->intakeLead($lead, $user)[0];
    }

    public function fromCustomer(User $user, Customer $customer): OnboardingCard
    {
        $all = $this->access->scopeFor($user, 'leads.view') === 'all'
            || $this->access->scopeFor($user, 'opportunities.view') === 'all';
        $visibleLead = $this->leads->visible($user)->where('converted_customer_id', $customer->id)->exists();

        abort_unless($this->canView($user) && ($all || (int) $customer->owner_id === (int) $user->id || $visibleLead), 404);

        return $this->intakeCustomer($customer, $user)[0];
    }

    public function syncIntake(?User $user): int
    {
        $created = 0;
        $cutoff = now()->subDays($this->backfillDays());

        Lead::query()
            ->where('status', '!=', 'disqualified')
            ->where(fn (Builder $query) => $query->whereNotNull('handed_to_sales_at')
                ->orWhereIn('status', ['qualified', 'converted']))
            ->where(fn (Builder $query) => $query->where('created_at', '>=', $cutoff)
                ->orWhere('handed_to_sales_at', '>=', $cutoff)
                ->orWhere('updated_at', '>=', $cutoff))
            ->orderBy('id')
            ->chunkById(200, function ($leads) use ($user, &$created): void {
                foreach ($leads as $lead) {
                    [, $wasCreated] = $this->intakeLead($lead, $user);
                    $created += (int) $wasCreated;
                }
            });

        Customer::query()
            ->where('created_at', '>=', $cutoff)
            ->whereIn('id', Lead::query()->whereNotNull('converted_customer_id')->select('converted_customer_id'))
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($user, &$created): void {
                foreach ($customers as $customer) {
                    [, $wasCreated] = $this->intakeCustomer($customer, $user);
                    $created += (int) $wasCreated;
                }
            });

        return $created;
    }

    private function intakeLead(Lead $lead, ?User $user): array
    {
        return DB::transaction(function () use ($lead, $user): array {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $card = OnboardingCard::query()
                ->where(fn (Builder $query) => $query->where('lead_id', $lead->id)
                    ->orWhere(fn (Builder $query) => $query->where('source_kind', 'lead')->where('source_id', $lead->id)))
                ->lockForUpdate()->first();

            if (! $card && $lead->converted_customer_id) {
                $card = OnboardingCard::query()->where('customer_id', $lead->converted_customer_id)->lockForUpdate()->first();
            }

            $ownerId = $lead->owner_id ?: $lead->brought_by_id;
            $owner = $ownerId ? User::query()->find($ownerId) : null;
            $name = $lead->displayName();
            $fields = [
                'title' => $name !== '' ? $name : (string) $lead->number,
                'client_name' => $name !== '' ? $name : null,
                'client_phone' => $lead->phone_e164,
                'client_email' => $lead->email,
                'language' => $lead->language,
                'area_id' => $lead->area_id,
                'salesperson_id' => $owner?->id,
                'salesperson_name' => $owner?->name,
                'lead_id' => $lead->id,
                'customer_id' => $lead->converted_customer_id ?: $card?->customer_id,
            ];

            if ($card) {
                $card->update($fields);

                return [$card, false];
            }

            $card = OnboardingCard::query()->create($fields + [
                'source_kind' => 'lead',
                'source_id' => $lead->id,
                'stage_key' => $this->firstStageKey(),
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
                'assigned_to' => $lead->owner_id,
            ]);
            $this->event($card, $user, 'created');

            return [$card, true];
        });
    }

    private function intakeCustomer(Customer $customer, ?User $user): array
    {
        return DB::transaction(function () use ($customer, $user): array {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $leadIds = Lead::query()->where('converted_customer_id', $customer->id)->select('id');
            $card = OnboardingCard::query()
                ->where(function (Builder $query) use ($customer, $leadIds): void {
                    $query->where('customer_id', $customer->id)
                        ->orWhere(fn (Builder $query) => $query->where('source_kind', 'customer')->where('source_id', $customer->id))
                        ->orWhereIn('lead_id', $leadIds);
                })
                ->lockForUpdate()->first();

            if ($card && $card->lead_id) {
                if ((int) $card->customer_id !== (int) $customer->id) {
                    $card->update(['customer_id' => $customer->id]);
                }

                return [$card, false];
            }

            $owner = $customer->owner_id ? User::query()->find($customer->owner_id) : null;
            $title = $customer->name_en ?: ($customer->name_ar ?: ($customer->company_name ?: $customer->account_no));
            $fields = [
                'title' => $title,
                'client_name' => $title,
                'client_phone' => $customer->whatsapp_e164,
                'client_email' => $customer->email,
                'area_id' => $customer->area_id,
                'salesperson_id' => $owner?->id,
                'salesperson_name' => $owner?->name,
                'customer_id' => $customer->id,
            ];

            if ($card) {
                $card->update($fields);

                return [$card, false];
            }

            $card = OnboardingCard::query()->create($fields + [
                'source_kind' => 'customer',
                'source_id' => $customer->id,
                'stage_key' => $this->firstStageKey(),
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
                'assigned_to' => $customer->owner_id,
            ]);
            $this->event($card, $user, 'created');

            return [$card, true];
        });
    }

    private function firstStageKey(): string
    {
        return $this->stages()->firstOrFail()->key;
    }

    private function event(OnboardingCard $card, ?User $user, string $kind, ?string $detail = null, ?string $from = null, ?string $to = null): void
    {
        $card->events()->create([
            'occurred_at' => now(),
            'actor_id' => $user?->id,
            'actor_name' => $user?->name ?? __('ui.onboarding.system'),
            'kind' => $kind,
            'from_stage' => $from,
            'to_stage' => $to,
            'detail' => $detail,
        ]);
    }

    private function defaultStages(): array
    {
        return [
            ['key' => 'new_lead', 'name_en' => 'New lead from sales', 'name_ar' => 'عميل محتمل جديد من المبيعات', 'position' => 1, 'task_title_en' => 'Contact the client', 'task_title_ar' => 'التواصل مع العميل', 'is_terminal' => false],
            ['key' => 'contacted', 'name_en' => 'Contacted', 'name_ar' => 'تم التواصل', 'position' => 2, 'task_title_en' => 'Book a site survey', 'task_title_ar' => 'تحديد موعد معاينة الموقع', 'is_terminal' => false],
            ['key' => 'site_survey', 'name_en' => 'Site survey', 'name_ar' => 'معاينة الموقع', 'position' => 3, 'task_title_en' => 'Complete the site survey', 'task_title_ar' => 'إتمام معاينة الموقع', 'is_terminal' => false],
            ['key' => 'quote_sent', 'name_en' => 'Quote sent', 'name_ar' => 'أُرسل عرض السعر', 'position' => 4, 'task_title_en' => 'Follow up on the quote', 'task_title_ar' => 'متابعة عرض السعر', 'is_terminal' => false],
            ['key' => 'contract_signed', 'name_en' => 'Contract signed', 'name_ar' => 'وُقّع العقد', 'position' => 5, 'task_title_en' => 'Schedule the installation', 'task_title_ar' => 'تحديد موعد التركيب', 'is_terminal' => false],
            ['key' => 'installation_scheduled', 'name_en' => 'Installation scheduled', 'name_ar' => 'حُدد موعد التركيب', 'position' => 6, 'task_title_en' => 'Confirm the installation', 'task_title_ar' => 'تأكيد التركيب', 'is_terminal' => false],
            ['key' => 'active', 'name_en' => 'Active', 'name_ar' => 'نشط', 'position' => 7, 'task_title_en' => 'Confirm the service is active', 'task_title_ar' => 'تأكيد أن الخدمة نشطة', 'is_terminal' => true],
        ];
    }
}
