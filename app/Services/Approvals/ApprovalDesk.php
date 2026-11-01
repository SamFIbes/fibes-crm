<?php

namespace App\Services\Approvals;

use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\CustomerService;
use App\Models\Opportunity;
use App\Models\Order;
use App\Models\PriceChangeRequest;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\ReferralCredit;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingDesk;
use App\Services\Catalog\CatalogDesk;
use App\Services\Catalog\PriceResolver;
use App\Services\Collections\CollectionsDesk;
use App\Services\Opportunities\OpportunityDesk;
use App\Services\Orders\OrderDesk;
use App\Services\Orders\PurchasingDesk;
use App\Services\Payments\PaymentDesk;
use App\Services\Performance\CommissionDesk;
use App\Services\Quotes\QuoteDesk;
use App\Services\Retention\RetentionDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ApprovalDesk
{
    public function __construct(
        private ApprovalFlow $flow,
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function open(User $requester, string $type, ?string $subjectType, ?int $subjectId, array $payload, string $summary, ?Request $request = null): Approval
    {
        $policy = ApprovalPolicy::query()->where('key', $type)->where('is_active', true)->firstOrFail();
        $this->cancelPending($type, $subjectType, $subjectId, $requester, $request);

        if ($this->auto($policy, $requester, $payload)) {
            $approval = Approval::query()->create([
                'policy_id' => $policy->id,
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'requester_id' => $requester->id,
                'status' => 'approved',
                'current_step' => 0,
                'steps' => [],
                'payload' => $payload,
                'summary' => $summary,
                'decided_at' => now(),
            ]);
            $this->audit->record('approval.auto', $requester, ['approval_id' => $approval->id, 'type' => $type], $request);
            $this->finish($approval, $requester, $request);

            return $approval;
        }

        $steps = $this->stepsFor($policy, $payload);
        $hours = (int) ($steps[0]['timeout_hours'] ?? $policy->timeout_hours ?: 4);
        $approval = Approval::query()->create([
            'policy_id' => $policy->id,
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'requester_id' => $requester->id,
            'status' => 'pending',
            'subject_state' => 'pending',
            'current_step' => 0,
            'steps' => $steps,
            'payload' => $payload,
            'summary' => $summary,
            'due_at' => $hours > 0 ? now()->addHours($hours) : null,
        ]);
        $this->audit->record('approval.requested', $requester, [
            'approval_id' => $approval->id,
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ], $request);
        $this->notifyStep($approval, __('ui.approvals.requested_title'), __('ui.approvals.requested_body', ['summary' => $summary]));

        return $approval;
    }

    public function approve(Approval $approval, User $actor, ?string $comment, Request $request): Approval
    {
        return $this->decide($approval, $actor, 'approve', $comment, $request);
    }

    public function reject(Approval $approval, User $actor, ?string $comment, Request $request): Approval
    {
        return $this->decide($approval, $actor, 'reject', $comment, $request);
    }

    public function cancel(Approval $approval, User $actor, Request $request): void
    {
        if ((int) $approval->requester_id !== (int) $actor->id || ! $this->flow->allows($approval->status, 'cancelled')) {
            throw ValidationException::withMessages(['approval' => __('ui.approvals.cannot_cancel')]);
        }

        $approval->status = 'cancelled';
        $approval->subject_state = 'draft';
        $approval->decided_at = now();
        $approval->save();
        $this->audit->record('approval.cancelled', $actor, ['approval_id' => $approval->id], $request);
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulk(array $ids, User $actor, Request $request): int
    {
        $rows = Approval::query()->with('policy')->whereIn('id', $ids)->get();

        foreach ($rows as $row) {
            if (! $row->policy?->low_risk) {
                throw ValidationException::withMessages(['approval' => __('ui.approvals.bulk_blocked')]);
            }
        }

        $done = 0;

        foreach ($rows as $row) {
            $this->approve($row, $actor, null, $request);
            $done++;
        }

        return $done;
    }

    public function delegate(User $user, User $delegate, string $starts, string $ends, User $actor, Request $request): ApprovalDelegation
    {
        $row = ApprovalDelegation::query()->create([
            'user_id' => $user->id,
            'delegate_id' => $delegate->id,
            'starts_on' => $starts,
            'ends_on' => $ends,
        ]);
        $this->audit->record('approval.delegated', $actor, [
            'user_id' => $user->id,
            'delegate_id' => $delegate->id,
            'starts_on' => $starts,
            'ends_on' => $ends,
        ], $request);

        return $row;
    }

    public function sweep(): int
    {
        $fired = 0;

        Approval::query()->where('status', 'pending')->whereNotNull('due_at')->orderBy('id')->each(function (Approval $approval) use (&$fired): void {
            $start = $approval->created_at ?? now();
            $due = $approval->due_at;
            $half = $start->copy()->addSeconds((int) ($start->diffInSeconds($due) / 2));

            if ($approval->reminded_at === null && now()->greaterThanOrEqualTo($half)) {
                $approval->reminded_at = now();
                $approval->save();
                $this->notifyStep($approval, __('ui.approvals.reminder_title'), __('ui.approvals.reminder_body', ['summary' => $approval->summary]));
            }

            if ($approval->escalated_at === null && now()->greaterThan($due)) {
                $approval->status = 'escalated';
                $approval->escalated_at = now();
                $approval->save();
                $role = $approval->policy?->escalate_to;
                $steps = $approval->steps ?? [];
                $index = (int) $approval->current_step;

                if ($role && isset($steps[$index]) && $this->flow->allows('escalated', 'pending')) {
                    $steps[$index]['roles'] = [$role];
                    $approval->steps = $steps;
                    $approval->status = 'pending';
                    $hours = (int) ($approval->policy?->timeout_hours ?: 4);
                    $approval->due_at = $hours > 0 ? now()->addHours($hours) : null;
                    $approval->save();
                }

                $this->notifyStep($approval, __('ui.approvals.timeout_title'), __('ui.approvals.timeout_body', ['summary' => $approval->summary]));
                $this->audit->record('approval.escalated', null, ['approval_id' => $approval->id], null);
                $fired++;
            }
        });

        return $fired;
    }

    public function waitingFor(User $user): int
    {
        return Approval::query()->where('status', 'pending')->get()->filter(fn (Approval $approval) => $this->canAct($approval, $user))->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inbox(User $user): array
    {
        $scope = $this->access->scopeFor($user, 'approvals.view');
        $query = Approval::query()->with(['requester:id,name', 'decisions.approver:id,name', 'policy'])->latest('id');

        if ($scope === 'own') {
            $query->where('requester_id', $user->id);
        } elseif ($scope !== 'all') {
            $query->whereRaw('0 = 1');
        }

        return $query->limit(80)->get()->map(function (Approval $approval) use ($user) {
            $comment = $approval->decisions->sortByDesc('id')->first()?->comment;

            return [
                'id' => $approval->id,
                'type' => $approval->type,
                'name_en' => $approval->policy?->name_en,
                'name_ar' => $approval->policy?->name_ar,
                'summary' => $approval->summary,
                'status' => $approval->status,
                'subject_state' => $approval->subject_state,
                'requester' => $approval->requester?->name,
                'mine' => (int) $approval->requester_id === (int) $user->id,
                'can_decide' => $this->canAct($approval, $user),
                'low_risk' => (bool) $approval->policy?->low_risk,
                'due_at' => $approval->due_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                'comment' => $comment,
                'step' => $approval->current_step + 1,
                'steps' => count($approval->steps ?? []),
            ];
        })->values()->all();
    }

    private function decide(Approval $approval, User $actor, string $decision, ?string $comment, Request $request): Approval
    {
        if ($approval->status !== 'pending') {
            throw ValidationException::withMessages(['approval' => __('ui.approvals.closed')]);
        }

        if ((int) $approval->requester_id === (int) $actor->id) {
            throw ValidationException::withMessages(['approval' => __('ui.approvals.own_request')]);
        }

        if (! $this->canAct($approval, $actor)) {
            throw ValidationException::withMessages(['approval' => __('ui.approvals.not_yours')]);
        }

        $needsComment = $decision === 'reject' || $this->commentRequired($approval);

        if ($needsComment && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => __('ui.approvals.comment_needed')]);
        }

        $target = $decision === 'reject' ? 'rejected' : 'approved';

        if (! $this->flow->allows($approval->status, $target) && ! ($decision === 'approve' && $this->flow->allows($approval->status, 'pending'))) {
            throw ValidationException::withMessages(['approval' => __('ui.approvals.closed')]);
        }

        ApprovalDecision::query()->create([
            'approval_id' => $approval->id,
            'step' => $approval->current_step,
            'approver_id' => $actor->id,
            'decision' => $decision,
            'comment' => $comment,
            'decided_at' => now(),
        ]);

        if ($decision === 'reject') {
            $approval->status = 'rejected';
            $approval->subject_state = 'draft';
            $approval->decided_at = now();
            $approval->save();
            $this->audit->record('approval.rejected', $actor, ['approval_id' => $approval->id, 'comment' => $comment], $request);
            $this->tellRequester($approval, __('ui.approvals.rejected_title'), (string) $comment);
            $this->releaseQuote($approval, $actor, $request);
            $this->releaseOrder($approval, $actor, $request);

            return $approval;
        }

        $steps = $approval->steps ?? [];
        $step = $steps[$approval->current_step] ?? [];

        if (($step['mode'] ?? 'any_of') === 'all_of' && ! $this->everyoneApproved($approval, $step)) {
            $this->audit->record('approval.step', $actor, ['approval_id' => $approval->id, 'step' => $approval->current_step], $request);

            return $approval;
        }

        $next = $approval->current_step + 1;

        if ($next < count($steps)) {
            $hours = (int) ($steps[$next]['timeout_hours'] ?? $approval->policy?->timeout_hours ?: 4);
            $approval->current_step = $next;
            $approval->status = 'pending';
            $approval->due_at = $hours > 0 ? now()->addHours($hours) : null;
            $approval->reminded_at = null;
            $approval->escalated_at = null;
            $approval->save();
            $this->audit->record('approval.step', $actor, ['approval_id' => $approval->id, 'step' => $next], $request);
            $this->notifyStep($approval, __('ui.approvals.requested_title'), __('ui.approvals.requested_body', ['summary' => $approval->summary]));

            return $approval;
        }

        $approval->status = 'approved';
        $approval->subject_state = 'approved';
        $approval->decided_at = now();
        $approval->save();
        $this->audit->record('approval.approved', $actor, ['approval_id' => $approval->id], $request);
        $this->finish($approval, $actor, $request);
        $this->tellRequester($approval, __('ui.approvals.approved_title'), (string) $approval->summary);

        return $approval;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function auto(ApprovalPolicy $policy, User $requester, array $payload): bool
    {
        $conditions = $policy->conditions ?? [];
        $role = (string) $requester->role;
        $roles = $conditions['auto_roles'] ?? null;

        if (is_array($roles) && ! in_array($role, $roles, true) && ! isset($conditions['auto_percent'][$role])) {
            return false;
        }

        if (isset($conditions['auto_percent'][$role]) && isset($payload['percent'])) {
            return bccomp((string) $payload['percent'], (string) $conditions['auto_percent'][$role], 2) <= 0;
        }

        if (isset($conditions['auto_units'], $payload['units']) && (is_array($roles) ? in_array($role, $roles, true) : true)) {
            return bccomp((string) $payload['units'], (string) $conditions['auto_units'], 2) <= 0;
        }

        if (isset($conditions['auto_amount'], $payload['amount']) && is_array($roles) && in_array($role, $roles, true)) {
            return bccomp((string) $payload['amount'], (string) $conditions['auto_amount'], 2) <= 0;
        }

        if (isset($conditions['auto_days'], $payload['days']) && is_array($roles) && in_array($role, $roles, true)) {
            return bccomp((string) $payload['days'], (string) $conditions['auto_days'], 2) <= 0;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function stepsFor(ApprovalPolicy $policy, array $payload): array
    {
        $matched = [];

        foreach ($policy->steps ?? [] as $step) {
            if ($this->matches($step['when'] ?? [], $payload)) {
                $matched[] = $step;
            }
        }

        if ($matched === []) {
            return $policy->steps ?? [];
        }

        if ($policy->pick === 'ladder') {
            return [end($matched)];
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $when
     * @param  array<string, mixed>  $payload
     */
    private function matches(array $when, array $payload): bool
    {
        if ($when === []) {
            return true;
        }

        foreach (['percent', 'amount', 'units', 'days', 'months'] as $key) {
            if (isset($when[$key.'_above']) && bccomp((string) ($payload[$key] ?? 0), (string) $when[$key.'_above'], 2) <= 0) {
                return false;
            }

            if (isset($when[$key.'_up_to']) && bccomp((string) ($payload[$key] ?? 0), (string) $when[$key.'_up_to'], 2) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function canAct(Approval $approval, User $user): bool
    {
        if ($approval->status !== 'pending' || (int) $approval->requester_id === (int) $user->id) {
            return false;
        }

        $step = ($approval->steps ?? [])[$approval->current_step] ?? [];
        $users = array_map('intval', $step['users'] ?? []);

        if ($users !== [] && in_array((int) $user->id, $users, true)) {
            return ! $this->alreadyDecided($approval, $user);
        }

        foreach ($step['roles'] ?? [] as $role) {
            if ($user->hasRole((string) $role) || $this->covers($user, (string) $role)) {
                return ! $this->alreadyDecided($approval, $user);
            }
        }

        return false;
    }

    private function covers(User $delegate, string $role): bool
    {
        $today = now()->toDateString();

        return ApprovalDelegation::query()
            ->where('delegate_id', $delegate->id)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->whereIn('user_id', User::query()->where('role', $role)->pluck('id'))
            ->exists();
    }

    private function alreadyDecided(Approval $approval, User $user): bool
    {
        return $approval->decisions()->where('step', $approval->current_step)->where('approver_id', $user->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function everyoneApproved(Approval $approval, array $step): bool
    {
        $needed = array_map('intval', $step['users'] ?? []);
        $have = $approval->decisions()->where('step', $approval->current_step)->where('decision', 'approve')->pluck('approver_id')->map(fn ($id) => (int) $id)->all();

        foreach ($needed as $id) {
            if (! in_array($id, $have, true)) {
                return false;
            }
        }

        return $needed !== [];
    }

    private function commentRequired(Approval $approval): bool
    {
        $above = $approval->policy?->comment_above;

        if ($above === null) {
            return false;
        }

        return bccomp((string) ($approval->payload['percent'] ?? 0), (string) $above, 2) > 0;
    }

    private function cancelPending(string $type, ?string $subjectType, ?int $subjectId, User $actor, ?Request $request): void
    {
        if ($subjectType === null || $subjectId === null) {
            return;
        }

        Approval::query()
            ->where('type', $type)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'pending')
            ->get()
            ->each(function (Approval $approval) use ($actor, $request): void {
                $approval->status = 'cancelled';
                $approval->subject_state = 'draft';
                $approval->decided_at = now();
                $approval->save();
                $this->audit->record('approval.cancelled', $actor, ['approval_id' => $approval->id, 'reason' => 'replaced'], $request);
            });
    }

    private function notifyStep(Approval $approval, string $title, string $body): void
    {
        $step = ($approval->steps ?? [])[$approval->current_step] ?? [];
        $roles = $step['roles'] ?? [];
        $ids = array_map('intval', $step['users'] ?? []);
        $people = User::query()->where('is_active', true)->where('id', '!=', $approval->requester_id)->where(function ($query) use ($roles, $ids): void {
            if ($roles !== []) {
                $query->whereIn('role', $roles);
            }

            if ($ids !== []) {
                $query->orWhereIn('id', $ids);
            }
        })->get();

        if ($people->isEmpty()) {
            return;
        }

        Notification::send($people, new WorkspaceNotification($title, $body, '/approvals'));
    }

    private function tellRequester(Approval $approval, string $title, string $body): void
    {
        $requester = $approval->requester;

        if ($requester instanceof User) {
            $requester->notify(new WorkspaceNotification($title, $body, '/approvals'));
        }
    }

    private function finish(Approval $approval, User $actor, ?Request $request): void
    {
        $payload = $approval->payload ?? [];

        if ($approval->type === 'reseller_agreement' && $approval->subject_id) {
            $deal = Opportunity::query()->find($approval->subject_id);

            if ($deal instanceof Opportunity) {
                app(OpportunityDesk::class)->applyApproved($deal, 'agreement', $actor);
            }
        }

        if ($approval->type === 'install_exception' && $approval->subject_id) {
            $deal = Opportunity::query()->find($approval->subject_id);

            if ($deal instanceof Opportunity) {
                $deal->override_reason = (string) ($payload['reason'] ?? $deal->override_reason);
                $deal->save();
                app(OpportunityDesk::class)->applyApproved($deal, 'won', $actor);
            }
        }

        if ($approval->type === 'price_list' && isset($payload['request_id'])) {
            $change = PriceChangeRequest::query()->find($payload['request_id']);

            if ($change instanceof PriceChangeRequest && $change->status === 'pending') {
                app(CatalogDesk::class)->addPrice([
                    'product_id' => $change->product_id,
                    'price' => $change->price,
                    'currency' => $change->currency,
                    'valid_from' => $change->valid_from?->toDateString(),
                    'customer_type' => $change->customer_type,
                    'area_id' => $change->area_id,
                    'note' => $change->note,
                ], $actor, $request ?? Request::create('/'));
                $change->status = 'applied';
                $change->decided_by = $actor->id;
                $change->decided_at = now();
                $change->save();
            }
        }

        if ($approval->type === 'plan_migration' && isset($payload['plan_id'], $payload['product_id'])) {
            $quote = app(PriceResolver::class)->resolve((int) $payload['product_id'], now()->toDateString());

            if ($quote !== null) {
                CustomerService::query()->where('plan_id', $payload['plan_id'])->update(['monthly_usd' => $quote['price']]);
                $this->audit->record('catalog.migrated', $actor, ['plan_id' => $payload['plan_id'], 'price' => $quote['price']], $request);
            }
        }

        if (in_array($approval->type, ['quote_discount', 'custom_price', 'free_install', 'promo_approval'], true) && $approval->subject_type === 'quote' && $approval->subject_id) {
            $quote = Quote::query()->find($approval->subject_id);

            if ($quote instanceof Quote) {
                app(QuoteDesk::class)->approveFromInbox($quote, $actor, $approval);
            }
        }

        if (in_array($approval->type, ['plan_migration', 'contract_change'], true) && $approval->subject_type === 'order' && $approval->subject_id) {
            $order = Order::query()->find($approval->subject_id);

            if ($order instanceof Order) {
                app(OrderDesk::class)->approveFromInbox($order, $actor, $request);
            }
        }

        if ($approval->type === 'payment_reversal' && $approval->subject_id) {
            app(PaymentDesk::class)->applyReversal((int) $approval->subject_id, $actor, $request);
        }

        if ($approval->type === 'cash_variance' && $approval->subject_id) {
            app(PaymentDesk::class)->applyClose((int) $approval->subject_id, $actor, $request);
        }

        if (in_array($approval->type, ['commission_clawback', 'commission_adjustment'], true)) {
            app(CommissionDesk::class)->applyApproved($approval, $actor, $request);
        }

        if ($approval->type === 'retention_offer' && $approval->subject_id) {
            app(RetentionDesk::class)->applyApproved((int) $approval->subject_id, $actor, $request);
        }

        if ($approval->type === 'suspension_exemption' && $approval->subject_id) {
            app(CollectionsDesk::class)->applyExemption(
                (int) $approval->subject_id,
                (string) ($payload['kind'] ?? 'extension'),
                isset($payload['until']) && is_string($payload['until']) ? $payload['until'] : null,
                $approval->id,
                $actor,
                $request,
            );
        }

        if (in_array($approval->type, ['credit_note', 'write_off', 'refund'], true)) {
            $amount = (string) ($payload['amount'] ?? '0');
            $subject = (int) ($approval->subject_id ?? 0);

            if ($subject > 0) {
                app(BillingDesk::class)->applyApproved($approval->type, $subject, $amount, $actor, $request);
            }
        }

        if ($approval->type === 'purchase_order' && $approval->subject_type === 'purchase_order' && $approval->subject_id) {
            $purchase = PurchaseOrder::query()->find($approval->subject_id);

            if ($purchase instanceof PurchaseOrder) {
                app(PurchasingDesk::class)->markApproved($purchase, $actor);
            }
        }

        if ($approval->type === 'referral_reward' && $approval->subject_id) {
            ReferralCredit::query()->whereKey($approval->subject_id)->update([
                'status' => 'approved',
                'needs_approval' => false,
            ]);
        }
    }

    private function releaseQuote(Approval $approval, User $actor, ?Request $request): void
    {
        if ($approval->subject_type !== 'quote' || ! $approval->subject_id) {
            return;
        }

        if (! in_array($approval->type, ['quote_discount', 'custom_price', 'free_install', 'promo_approval'], true)) {
            return;
        }

        $quote = Quote::query()->find($approval->subject_id);

        if ($quote instanceof Quote) {
            app(QuoteDesk::class)->reopen($quote, $actor, $request);
        }
    }

    private function releaseOrder(Approval $approval, User $actor, ?Request $request): void
    {
        if (! in_array($approval->type, ['plan_migration', 'contract_change'], true) || $approval->subject_type !== 'order' || ! $approval->subject_id) {
            return;
        }

        $order = Order::query()->find($approval->subject_id);

        if ($order instanceof Order) {
            app(OrderDesk::class)->reopen($order, $actor, $request);
        }
    }
}
