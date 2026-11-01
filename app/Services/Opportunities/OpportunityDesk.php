<?php

namespace App\Services\Opportunities;

use App\Events\OpportunityWon;
use App\Models\Approval;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\ExchangeRate;
use App\Models\Lead;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\OpportunityStageHistory;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerRecords;
use App\Services\Leads\LeadMachine;
use App\Support\Access\AccessGate;
use App\Support\Access\FieldPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpportunityDesk
{
    public function __construct(
        private AccessGate $access,
        private AuditLogger $audit,
        private CustomerRecords $customers,
        private OpportunityFlow $flow,
        private CoverageGate $coverage,
        private WonGate $won,
        private OpportunityMoney $money,
        private LeadMachine $leads,
    ) {}

    public function visible(User $viewer): Builder
    {
        $query = Opportunity::query();
        $view = $this->access->scopeFor($viewer, 'opportunities.view');
        $won = $this->access->allows($viewer, 'opportunities.view_won');

        if ($view === null && ! $won) {
            return $query->whereRaw('0 = 1');
        }

        if ($view === null) {
            return $query->whereHas('stage', fn (Builder $stage) => $stage->where('is_won', true));
        }

        if ($view === 'all') {
            return $query;
        }

        if ($view === 'team') {
            return $this->access->restrict($query, $viewer, 'opportunities.view', 'owner_id', 'team_id');
        }

        return $query->where('owner_id', $viewer->id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function openFromLead(Lead $lead, User $actor, Request $request, array $extra = []): Opportunity
    {
        if (! $this->leads->allows($lead->status, 'converted')) {
            throw ValidationException::withMessages(['status' => __('ui.opportunities.not_ready')]);
        }

        return DB::transaction(function () use ($lead, $actor, $request, $extra) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ($locked->converted_opportunity_id) {
                return Opportunity::query()->findOrFail($locked->converted_opportunity_id);
            }

            $type = in_array($extra['customer_type'] ?? '', ['individual', 'business', 'reseller'], true)
                ? (string) $extra['customer_type']
                : 'individual';
            $customer = $this->customerFor($locked, $actor, $request, $type);
            $contact = Contact::query()->firstOrCreate(
                ['customer_id' => $customer->id, 'phone_e164' => $locked->phone_e164],
                ['name' => $locked->displayName() !== '' ? $locked->displayName() : $customer->name_en, 'is_primary' => true],
            );
            $pipeline = $this->pipelineFor($type);
            $stage = $pipeline->stages()->where('entity', 'opportunity')->orderBy('sort')->firstOrFail();
            $opportunity = Opportunity::query()->create([
                'customer_id' => $customer->id,
                'contact_id' => $contact->id,
                'lead_id' => $locked->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_id' => $actor->id,
                'team_id' => $actor->teams()->value('id'),
                'area_id' => $locked->area_id,
                'service_type' => $locked->service_type_interest,
                'mrc' => $extra['mrc'] ?? 0,
                'nrc' => $extra['nrc'] ?? 0,
                'currency' => 'USD',
                'probability' => $stage->probability,
                'stage_entered_at' => now(),
                'coverage_note' => $locked->notes,
            ]);

            $locked->status = 'converted';
            $locked->handed_to_sales_at = now();
            $locked->converted_customer_id = $customer->id;
            $locked->converted_opportunity_id = $opportunity->id;
            $locked->save();

            $this->audit->record('opportunity.opened', $actor, [
                'opportunity_id' => $opportunity->id,
                'number' => $opportunity->fresh()->number,
                'lead_id' => $locked->id,
                'customer_id' => $customer->id,
                'pipeline' => $pipeline->key,
            ], $request);

            return $opportunity->fresh() ?? $opportunity;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function move(Opportunity $opportunity, string $stageKey, User $actor, Request $request, array $input = []): void
    {
        $opportunity->load(['stage.pipeline', 'pipeline']);
        $current = $opportunity->stage;
        $pipeline = $opportunity->pipeline;
        $target = PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('entity', 'opportunity')
            ->where('key', $stageKey)
            ->first();

        if (! $current instanceof PipelineStage || ! $target instanceof PipelineStage || ! $pipeline instanceof Pipeline) {
            throw ValidationException::withMessages(['stage' => __('ui.opportunities.illegal')]);
        }

        $skip = in_array((string) $opportunity->service_type, config('fibes.pipelines.skip_survey', []), true);

        if (! $this->flow->allows($pipeline->key, $current->key, $target->key, $skip)) {
            throw ValidationException::withMessages(['stage' => __('ui.opportunities.illegal')]);
        }

        if ($target->key === 'proposal') {
            if (! $this->coverage->proposalAllowed($opportunity)) {
                throw ValidationException::withMessages(['coverage_note' => __('ui.opportunities.coverage_needed')]);
            }
        }

        if ($target->key === 'agreement') {
            $approved = Approval::query()->where('type', 'reseller_agreement')->where('subject_type', 'opportunity')->where('subject_id', $opportunity->id)->where('status', 'approved')->exists();
            $pending = Approval::query()->where('type', 'reseller_agreement')->where('subject_type', 'opportunity')->where('subject_id', $opportunity->id)->where('status', 'pending')->exists();

            if (! $approved) {
                if (! $pending) {
                    app(ApprovalDesk::class)->open($actor, 'reseller_agreement', 'opportunity', $opportunity->id, [], (string) $opportunity->number, $request);
                }

                throw ValidationException::withMessages(['stage' => __('ui.opportunities.management_needed')]);
            }

            $opportunity->management_confirmed = true;
        }

        if ($target->is_won) {
            if (! $this->access->allows($actor, 'opportunities.close')) {
                throw ValidationException::withMessages(['stage' => __('ui.opportunities.illegal')]);
            }

            $reason = $input['override_reason'] ?? null;
            $reason = is_string($reason) ? $reason : null;
            $approved = Approval::query()->where('type', 'install_exception')->where('subject_type', 'opportunity')->where('subject_id', $opportunity->id)->where('status', 'approved')->exists();
            $documentsReady = Quote::query()
                ->where('opportunity_id', $opportunity->id)
                ->where('status', 'accepted')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('contracts')
                        ->whereColumn('contracts.quote_id', 'quotes.id')
                        ->where('contracts.status', 'signed');
                })
                ->exists();

            if (! $documentsReady && ! $approved) {
                if (! $this->won->allowed($actor, $reason)) {
                    throw ValidationException::withMessages(['override_reason' => __('ui.opportunities.manager_needed')]);
                }

                app(ApprovalDesk::class)->open($actor, 'install_exception', 'opportunity', $opportunity->id, ['reason' => trim((string) $reason)], trim((string) $reason), $request);

                throw ValidationException::withMessages(['override_reason' => __('ui.approvals.waiting')]);
            }

            $opportunity->override_reason = trim((string) $reason);
            $opportunity->won_at = now();
        }

        if ($target->is_lost) {
            if (! $this->access->allows($actor, 'opportunities.close')) {
                throw ValidationException::withMessages(['stage' => __('ui.opportunities.illegal')]);
            }

            $reason = LossReason::query()->find($input['loss_reason_id'] ?? 0);

            if (! $reason instanceof LossReason) {
                throw ValidationException::withMessages(['loss_reason_id' => __('ui.opportunities.loss_needed')]);
            }

            $opportunity->loss_reason_id = $reason->id;
            $opportunity->lost_at = now();
            $opportunity->competitor = $input['competitor'] ?? $opportunity->competitor;
        }

        if ($target->key === 'agreement' && ! $opportunity->management_confirmed) {
            $opportunity->management_confirmed = true;
        }

        $entered = $opportunity->stage_entered_at ?? $opportunity->created_at ?? now();
        $seconds = max(0, now()->getTimestamp() - $entered->getTimestamp());
        OpportunityStageHistory::query()->create([
            'opportunity_id' => $opportunity->id,
            'stage_id' => $current->id,
            'entered_at' => $entered,
            'left_at' => now(),
            'seconds' => $seconds,
        ]);

        $opportunity->stage_id = $target->id;
        $opportunity->probability = $target->probability;
        $opportunity->stage_entered_at = now();
        $opportunity->save();

        $this->audit->record('opportunity.moved', $actor, [
            'opportunity_id' => $opportunity->id,
            'from' => $current->key,
            'to' => $target->key,
            'seconds' => $seconds,
            'reason' => $opportunity->override_reason,
            'loss_reason_id' => $opportunity->loss_reason_id,
        ], $request);

        if ($target->is_won) {
            OpportunityWon::dispatch($opportunity);
        }

        if ($target->is_lost) {
            $this->winBack($opportunity->fresh() ?? $opportunity, $actor);
        }
    }

    public function applyApproved(Opportunity $opportunity, string $to, User $actor): void
    {
        $opportunity->loadMissing(['stage', 'pipeline']);
        $current = $opportunity->stage;
        $target = PipelineStage::query()->where('pipeline_id', $opportunity->pipeline_id)->where('key', $to)->first();

        if (! $current instanceof PipelineStage || ! $target instanceof PipelineStage) {
            return;
        }

        if ($to === 'agreement') {
            $opportunity->management_confirmed = true;
        }

        if ($target->is_won) {
            $opportunity->won_at = now();
        }

        $entered = $opportunity->stage_entered_at ?? $opportunity->created_at ?? now();
        OpportunityStageHistory::query()->create([
            'opportunity_id' => $opportunity->id,
            'stage_id' => $current->id,
            'entered_at' => $entered,
            'left_at' => now(),
            'seconds' => max(0, now()->getTimestamp() - $entered->getTimestamp()),
        ]);
        $opportunity->stage_id = $target->id;
        $opportunity->probability = $target->probability;
        $opportunity->stage_entered_at = now();
        $opportunity->save();
        $this->audit->record('opportunity.moved', $actor, [
            'opportunity_id' => $opportunity->id,
            'from' => $current->key,
            'to' => $target->key,
            'approval' => true,
        ], null);

        if ($target->is_won) {
            OpportunityWon::dispatch($opportunity);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function reassign(array $ids, User $assignee, User $actor, Request $request): int
    {
        if ($this->access->scopeFor($actor, 'opportunities.edit') !== 'all') {
            throw ValidationException::withMessages(['assignee_id' => __('ui.opportunities.illegal')]);
        }

        $count = 0;

        foreach ($this->visible($actor)->whereIn('id', $ids)->get() as $opportunity) {
            $opportunity->owner_id = $assignee->id;
            $opportunity->save();
            $count++;
        }

        $this->audit->record('opportunity.reassigned', $actor, [
            'ids' => $ids,
            'assignee_id' => $assignee->id,
            'count' => $count,
        ], $request);

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function funnel(Pipeline $pipeline): array
    {
        $total = Opportunity::query()->where('pipeline_id', $pipeline->id)->count();
        $counts = Opportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->selectRaw('stage_id, count(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id');

        return $pipeline->stages()->where('entity', 'opportunity')->get()->map(function (PipelineStage $stage) use ($counts, $total) {
            $count = (int) ($counts[$stage->id] ?? 0);

            return [
                'key' => $stage->key,
                'name_en' => $stage->name_en,
                'name_ar' => $stage->name_ar,
                'count' => $count,
                'conversion' => $total === 0 ? 0 : (int) round(($count / $total) * 100),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Opportunity $opportunity, User $viewer): array
    {
        $opportunity->loadMissing(['customer:id,name_en,name_ar,account_no,status,latitude,longitude', 'stage', 'owner:id,name', 'pipeline']);
        $rate = (string) (ExchangeRate::query()->latest('id')->value('usd_lbp') ?: '89500.0000');
        $stale = $this->isStale($opportunity);
        $masked = app(FieldPolicy::class)->present($viewer, [
            'mrc' => (string) $opportunity->mrc,
            'nrc' => (string) $opportunity->nrc,
        ]);

        return [
            'id' => $opportunity->id,
            'number' => $opportunity->number,
            'name' => $opportunity->customer?->name_en,
            'name_ar' => $opportunity->customer?->name_ar,
            'account_no' => $opportunity->customer?->account_no,
            'customer_status' => $opportunity->customer?->status,
            'stage' => $opportunity->stage?->key,
            'stage_en' => $opportunity->stage?->name_en,
            'stage_ar' => $opportunity->stage?->name_ar,
            'pipeline' => $opportunity->pipeline?->key,
            'service' => $opportunity->service_type,
            'owner' => $opportunity->owner?->name,
            'owner_id' => $opportunity->owner_id,
            'mrc' => $masked['mrc'] ?? null,
            'nrc' => $masked['nrc'] ?? null,
            'mrc_lbp' => isset($masked['mrc']) ? $this->money->lbp((string) $opportunity->mrc, $rate) : null,
            'currency' => $opportunity->currency,
            'probability' => $opportunity->probability,
            'stale' => $stale,
            'coverage_note' => $opportunity->coverage_note,
            'latitude' => $opportunity->customer?->latitude,
            'longitude' => $opportunity->customer?->longitude,
            'area_id' => $opportunity->area_id,
            'expected_close' => $opportunity->expected_close?->toDateString(),
            'age_days' => (int) ($opportunity->created_at?->diffInDays(now()) ?? 0),
        ];
    }

    private function isStale(Opportunity $opportunity): bool
    {
        $days = $opportunity->stage?->stale_after_days;
        $entered = $opportunity->stage_entered_at;

        if ($days === null || $entered === null) {
            return false;
        }

        return $entered->copy()->addDays((int) $days)->lessThanOrEqualTo(now());
    }

    private function customerFor(Lead $lead, User $actor, Request $request, string $type): Customer
    {
        $existingId = is_array($lead->custom) ? ($lead->custom['existing_customer_id'] ?? null) : null;

        if (is_numeric($existingId)) {
            $found = Customer::query()->find((int) $existingId);

            if ($found instanceof Customer) {
                return $found;
            }
        }

        $phone = CustomerPhone::query()->where('phone_e164', $lead->phone_e164)->first();

        if ($phone instanceof CustomerPhone) {
            return $phone->customer()->firstOrFail();
        }

        $name = $lead->displayName() !== '' ? $lead->displayName() : $lead->phone_e164;

        return $this->customers->create([
            'type' => $type,
            'status' => 'prospect',
            'name_en' => $name,
            'name_ar' => $name,
            'phones' => [$lead->phone_e164],
            'email' => $lead->email,
            'area_id' => $lead->area_id,
            'latitude' => $lead->latitude,
            'longitude' => $lead->longitude,
            'tags' => '',
        ], $actor, $request);
    }

    private function pipelineFor(string $type): Pipeline
    {
        $key = match ($type) {
            'business' => 'business',
            'reseller' => 'reseller',
            default => 'retail',
        };

        return Pipeline::query()->where('key', $key)->firstOrFail();
    }

    private function isManagement(User $actor): bool
    {
        return in_array($actor->role, ['super_admin', 'management'], true)
            || $actor->hasRole('super_admin')
            || $actor->hasRole('management');
    }

    private function winBack(Opportunity $opportunity, User $actor): void
    {
        $reason = $opportunity->lossReason;

        if (! $reason instanceof LossReason || ! $reason->win_back) {
            return;
        }

        $exists = WorkTask::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('kind', 'win_back')
            ->exists();

        if ($exists) {
            return;
        }

        $days = (int) config('fibes.pipelines.winback_days', 75);
        WorkTask::query()->create([
            'kind' => 'win_back',
            'title' => __('ui.opportunities.win_back_title', ['number' => $opportunity->number]),
            'customer_id' => $opportunity->customer_id,
            'lead_id' => $opportunity->lead_id,
            'opportunity_id' => $opportunity->id,
            'assignee_id' => $opportunity->owner_id ?? $actor->id,
            'owner_id' => $actor->id,
            'due_at' => now()->addDays($days),
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $this->audit->record('opportunity.win_back', $actor, [
            'opportunity_id' => $opportunity->id,
            'due_in_days' => $days,
        ]);
    }
}
