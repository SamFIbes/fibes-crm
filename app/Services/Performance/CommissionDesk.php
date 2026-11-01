<?php

namespace App\Services\Performance;

use App\Models\Approval;
use App\Models\CommissionEntry;
use App\Models\CommissionPlan;
use App\Models\CustomerService;
use App\Models\DealEvent;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Target;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionDesk
{
    /** @var list<string> */
    public const METRICS = ['new_sales_count', 'mrc_added', 'leads_converted', 'calls_handled', 'fcr_pct', 'collections_amount'];

    public function __construct(
        private CommercialSettings $commercial,
        private ApprovalDesk $approvals,
        private AuditLogger $audit,
        private AccessGate $access,
        private SettingsStore $settings,
    ) {}

    public function onWon(Opportunity $opportunity): void
    {
        $ownerId = (int) ($opportunity->owner_id ?? 0);

        if ($ownerId < 1) {
            return;
        }

        $amount = bcadd((string) ($opportunity->mrc ?? '0'), '0', 2);
        $this->earn($ownerId, $opportunity->team_id ? (int) $opportunity->team_id : null, 'opportunity', $opportunity->id, 'mrc', $this->mrcCommission($ownerId, $amount), $opportunity->id, null, null);
        $this->announce('won', $opportunity->id, null, $ownerId, $opportunity->team_id ? (int) $opportunity->team_id : null, $amount, $this->label($opportunity));
    }

    public function onActivated(Order $order): void
    {
        $ownerId = (int) ($order->owner_id ?? 0);

        if ($ownerId < 1) {
            return;
        }

        $fixed = $this->moneyRule('install_amount');

        if (bccomp($fixed, '0', 2) === 1) {
            $this->earn($ownerId, $order->team_id ? (int) $order->team_id : null, 'opportunity', $order->opportunity_id ? (int) $order->opportunity_id : $order->id, 'install', $fixed, $order->opportunity_id ? (int) $order->opportunity_id : null, $order->id, null);
        }

        if ($this->commercial->get('tv_trigger') === 'activated') {
            $amount = bcadd((string) ($order->total ?? '0'), '0', 2);
            $this->announce('activated', $order->opportunity_id ? (int) $order->opportunity_id : null, $order->id, $ownerId, $order->team_id ? (int) $order->team_id : null, $amount, (string) ($order->number ?: 'Order'));
        }
    }

    public function onPayment(Payment $payment): void
    {
        if ((string) $payment->status !== 'confirmed') {
            return;
        }

        $percent = $this->moneyRule('payment_percent');

        if (bccomp($percent, '0', 2) !== 1) {
            return;
        }

        $userId = (int) ($payment->received_by ?: $payment->created_by ?: 0);

        if ($userId < 1) {
            return;
        }

        $this->earn($userId, null, 'payment', $payment->id, 'payment', $this->percentOf((string) $payment->amount, $percent), null, null, $payment->id);
    }

    public function proposeClawback(CustomerService $service): void
    {
        $order = $service->order_id ? Order::query()->find($service->order_id) : null;
        $opportunityId = $order?->opportunity_id;

        if (! $opportunityId) {
            return;
        }

        $activated = $service->activated_at ?? $service->terminated_at ?? now();
        $days = (int) $this->rule('clawback_days');

        if ($activated->lt(now()->subDays($days))) {
            return;
        }

        $earned = $this->earned((int) $opportunityId);

        if (bccomp($earned, '0', 2) !== 1) {
            return;
        }

        $ownerId = (int) (Opportunity::query()->whereKey($opportunityId)->value('owner_id') ?: 0);
        $requester = User::query()->find($ownerId);

        if (! $requester instanceof User) {
            return;
        }

        if (Approval::query()->where('type', 'commission_clawback')->where('subject_type', 'customer_service')->where('subject_id', $service->id)->where('status', 'pending')->exists()) {
            return;
        }

        $this->approvals->open($requester, 'commission_clawback', 'customer_service', $service->id, [
            'amount' => bcsub('0', $earned, 2),
            'user_id' => $requester->id,
            'opportunity_id' => (int) $opportunityId,
            'team_id' => $order?->team_id,
        ], 'Claw back '.$earned.' within '.$days.' days', Request::create('/'));
    }

    public function applyApproved(Approval $approval, User $actor, ?Request $request = null): void
    {
        if (CommissionEntry::query()->where('approval_id', $approval->id)->exists()) {
            return;
        }

        $payload = $approval->payload ?? [];
        $userId = (int) ($payload['user_id'] ?? 0);
        $amount = bcadd((string) ($payload['amount'] ?? '0'), '0', 2);

        if ($userId < 1 || bccomp($amount, '0', 2) === 0) {
            return;
        }

        $kind = $approval->type === 'commission_clawback' ? 'clawback' : 'adjustment';
        $this->insert([
            'user_id' => $userId,
            'team_id' => $payload['team_id'] ?? null,
            'source_type' => $kind,
            'source_id' => $approval->id,
            'kind' => $kind,
            'amount' => $amount,
            'status' => $kind === 'clawback' ? 'clawed_back' : 'approved',
            'opportunity_id' => $payload['opportunity_id'] ?? null,
            'approval_id' => $approval->id,
            'notes' => $approval->summary,
            'created_by' => $actor->id,
        ]);
        $this->audit->record('commission.posted', $actor, [
            'approval_id' => $approval->id,
            'user_id' => $userId,
            'amount' => $amount,
            'kind' => $kind,
        ], $request);
    }

    public function requestAdjustment(User $actor, int $userId, string $amount, string $reason, Request $request): void
    {
        $this->guardSubject($actor, $userId);
        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) === 0) {
            throw ValidationException::withMessages(['amount' => __('ui.performance.amount_needed')]);
        }

        $this->approvals->open($actor, 'commission_adjustment', 'user', $userId, [
            'amount' => $amount,
            'user_id' => $userId,
        ], $reason, $request);
        $this->audit->record('commission.adjustment_requested', $actor, ['user_id' => $userId, 'amount' => $amount], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function setTarget(User $actor, array $input, Request $request): Target
    {
        $metric = (string) $input['metric'];

        if (! in_array($metric, self::METRICS, true)) {
            throw ValidationException::withMessages(['metric' => __('ui.performance.illegal')]);
        }

        $subjectType = (string) $input['subject_type'];

        if (! in_array($subjectType, ['user', 'team', 'role'], true)) {
            throw ValidationException::withMessages(['subject_type' => __('ui.performance.illegal')]);
        }

        $subjectId = isset($input['subject_id']) ? (int) $input['subject_id'] : null;

        if ($subjectType === 'user' && $subjectId) {
            $this->guardSubject($actor, $subjectId);
        }

        $start = Carbon::parse((string) $input['period_start'])->startOfMonth()->toDateString();
        $existing = Target::query()
            ->where('period', $input['period'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectType === 'role' ? null : $subjectId)
            ->where('subject_key', $subjectType === 'role' ? (string) ($input['subject_key'] ?? '') : null)
            ->where('metric', $metric)
            ->whereDate('period_start', $start)
            ->whereNull('archived_at')
            ->first();

        if ($existing instanceof Target) {
            $existing->value = bcadd((string) $input['value'], '0', 2);
            $existing->updated_by = $actor->id;
            $existing->version = $existing->version + 1;
            $existing->save();
            $target = $existing;
        } else {
            $target = Target::query()->create([
                'period' => $input['period'],
                'subject_type' => $subjectType,
                'subject_id' => $subjectType === 'role' ? null : $subjectId,
                'subject_key' => $subjectType === 'role' ? (string) ($input['subject_key'] ?? '') : null,
                'metric' => $metric,
                'value' => bcadd((string) $input['value'], '0', 2),
                'period_start' => $start,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $this->audit->record('target.set', $actor, ['target_id' => $target->id, 'metric' => $metric, 'value' => (string) $target->value], $request);

        return $target;
    }

    /**
     * @return list<array{user_id: int, name: string, total: string, deals: int}>
     */
    public function leaderboard(string $period, ?int $teamId = null): array
    {
        [$from, $to] = $this->range($period);
        $query = DealEvent::query()->where('kind', 'won')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->selectRaw('owner_id, sum(amount) as total, count(*) as deals')
            ->groupBy('owner_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'user_id' => (int) $row->owner_id,
                    'name' => (string) (User::query()->whereKey($row->owner_id)->value('name') ?: ''),
                    'total' => bcadd((string) $row->total, '0', 2),
                    'deals' => (int) $row->deals,
                ];
            })
            ->all();
    }

    /**
     * @return array{totals: array{count: int, amount: string}, targets: list<array<string, mixed>>, leaders: list<array<string, mixed>>, deals: list<array<string, mixed>>}
     */
    public function feed(): array
    {
        $kind = $this->commercial->get('tv_trigger') === 'activated' ? 'activated' : 'won';
        [$from, $to] = $this->range('month');
        $deals = DealEvent::query()->where('kind', $kind)->latest('id')->limit(8)->get();
        $month = DealEvent::query()->where('kind', $kind)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $targets = Target::query()->whereNull('archived_at')->where('metric', 'mrc_added')->whereDate('period_start', now()->startOfMonth()->toDateString())->limit(6)->get();

        return [
            'totals' => [
                'count' => (clone $month)->count(),
                'amount' => bcadd((string) ((clone $month)->sum('amount') ?: '0'), '0', 2),
            ],
            'targets' => $targets->map(fn (Target $target) => [
                'label' => $this->targetLabel($target),
                'value' => (string) $target->value,
                'progress' => $this->progress($target, $from, $to),
            ])->all(),
            'leaders' => $this->leaderboard('month'),
            'deals' => $deals->map(fn (DealEvent $deal) => [
                'id' => $deal->id,
                'label' => $deal->label,
                'amount' => (string) $deal->amount,
                'owner' => (string) (User::query()->whereKey($deal->owner_id)->value('name') ?: ''),
                'big' => (bool) $deal->is_big_deal,
                'fanfare' => (bool) $deal->fanfare,
            ])->all(),
        ];
    }

    public function issueToken(User $actor, Request $request): string
    {
        $plain = Str::random(40);
        $this->settings->put('tv_board', ['hash' => hash('sha256', $plain)], $actor, $request);
        $this->audit->record('tv.token', $actor, ['rotated' => true], $request);

        return $plain;
    }

    public function tokenMatches(string $plain): bool
    {
        $stored = $this->settings->get('tv_board', []);
        $hash = $stored['hash'] ?? null;

        return is_string($hash) && $hash !== '' && hash_equals($hash, hash('sha256', $plain));
    }

    public function visible(User $user): Builder
    {
        return $this->access->restrict(CommissionEntry::query(), $user, 'commissions.view', 'user_id', 'team_id');
    }

    /**
     * @param  array<string, mixed>  $next
     */
    public function revisePlan(array $next, User $actor): CommissionPlan
    {
        $current = CommissionPlan::query()->where('is_current', true)->latest('id')->first();
        $version = $current instanceof CommissionPlan ? $current->version + 1 : 1;

        if ($current instanceof CommissionPlan) {
            $current->is_current = false;
            $current->valid_to = now()->toDateString();
            $current->save();
        }

        return CommissionPlan::query()->create([
            'version' => $version,
            'rules' => $this->rulesFrom($next),
            'valid_from' => now()->toDateString(),
            'is_current' => true,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function progressFor(Target $target): string
    {
        [$from, $to] = $this->range($target->period === 'week' ? 'week' : 'month');

        return $this->progress($target, $from, $to);
    }

    public function auditExport(User $actor, int $count, Request $request): void
    {
        $this->audit->record('commission.exported', $actor, ['rows' => $count], $request);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function range(string $period): array
    {
        $now = now();

        if ($period === 'day') {
            return [$now->toDateString(), $now->toDateString()];
        }

        if ($period === 'week') {
            return [$now->copy()->startOfWeek()->toDateString(), $now->copy()->endOfWeek()->toDateString()];
        }

        return [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()];
    }

    private function mrcCommission(int $userId, string $mrc): string
    {
        $percent = $this->moneyRule('mrc_percent');
        $target = Target::query()
            ->where('subject_type', 'user')
            ->where('subject_id', $userId)
            ->where('metric', 'mrc_added')
            ->whereDate('period_start', now()->startOfMonth()->toDateString())
            ->whereNull('archived_at')
            ->value('value');

        if ($target !== null) {
            [$from, $to] = $this->range('month');
            $soFar = bcadd((string) (DealEvent::query()->where('kind', 'won')->where('owner_id', $userId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->sum('amount') ?: '0'), $mrc, 2);
            $line = bcdiv(bcmul((string) $target, (string) (int) $this->rule('accelerator_above'), 2), '100', 2);

            if (bccomp($soFar, $line, 2) >= 0) {
                $percent = $this->moneyRule('accelerator_percent');
            }
        }

        return $this->percentOf($mrc, $percent);
    }

    private function earn(int $userId, ?int $teamId, string $sourceType, ?int $sourceId, string $kind, string $amount, ?int $opportunityId, ?int $orderId, ?int $paymentId): void
    {
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $exists = CommissionEntry::query()->where('user_id', $userId)->where('kind', $kind)->where('source_type', $sourceType)->where('source_id', $sourceId)->exists();

        if ($exists) {
            return;
        }

        $this->insert([
            'user_id' => $userId,
            'team_id' => $teamId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'kind' => $kind,
            'amount' => $amount,
            'status' => 'approved',
            'opportunity_id' => $opportunityId,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(array $row): CommissionEntry
    {
        $plan = CommissionPlan::query()->where('is_current', true)->first();

        return CommissionEntry::query()->create($row + [
            'currency' => 'USD',
            'period' => now()->startOfMonth()->toDateString(),
            'plan_id' => $plan?->id,
        ]);
    }

    private function announce(string $kind, ?int $opportunityId, ?int $orderId, int $ownerId, ?int $teamId, string $amount, string $label): void
    {
        if (DealEvent::query()->where('kind', $kind)->where('opportunity_id', $opportunityId)->where('order_id', $orderId)->exists()) {
            return;
        }

        [$from, $to] = $this->range('month');
        $before = bcadd((string) (DealEvent::query()->where('kind', $kind)->where('owner_id', $ownerId)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->sum('amount') ?: '0'), '0', 2);
        $after = bcadd($before, $amount, 2);
        $target = Target::query()->where('subject_type', 'user')->where('subject_id', $ownerId)->where('metric', 'mrc_added')->whereDate('period_start', now()->startOfMonth()->toDateString())->whereNull('archived_at')->value('value');
        $fanfare = $target !== null && bccomp($before, (string) $target, 2) < 0 && bccomp($after, (string) $target, 2) >= 0;
        $big = bccomp($amount, $this->commercial->money('big_deal_amount'), 2) >= 0;
        DealEvent::query()->create([
            'kind' => $kind,
            'opportunity_id' => $opportunityId,
            'order_id' => $orderId,
            'owner_id' => $ownerId,
            'team_id' => $teamId,
            'amount' => $amount,
            'currency' => 'USD',
            'is_big_deal' => $big,
            'fanfare' => $fanfare,
            'label' => $label,
        ]);
    }

    private function earned(int $opportunityId): string
    {
        $sum = CommissionEntry::query()->where('opportunity_id', $opportunityId)->whereIn('kind', ['mrc', 'install'])->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 2);
    }

    private function guardSubject(User $actor, int $userId): void
    {
        if ($this->access->scopeFor($actor, 'targets.set') === 'all' || $this->access->scopeFor($actor, 'commissions.view') === 'all') {
            return;
        }

        if ($userId === $actor->id) {
            return;
        }

        $teamIds = $actor->teams()->pluck('teams.id');
        $same = User::query()->whereKey($userId)->whereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $teamIds))->exists();

        if (! $same) {
            throw ValidationException::withMessages(['user_id' => __('ui.performance.illegal')]);
        }
    }

    private function label(Opportunity $opportunity): string
    {
        $opportunity->loadMissing('customer');

        return (string) ($opportunity->customer?->name_en ?: $opportunity->number ?: 'Deal');
    }

    private function targetLabel(Target $target): string
    {
        if ($target->subject_type === 'role') {
            return (string) $target->subject_key;
        }

        if ($target->subject_type === 'team') {
            $team = Team::query()->find($target->subject_id);

            return $team instanceof Team ? $team->label() : 'Team';
        }

        return (string) (User::query()->whereKey($target->subject_id)->value('name') ?: 'Agent');
    }

    private function progress(Target $target, string $from, string $to): string
    {
        $owners = $this->subjectUsers($target);

        return match ($target->metric) {
            'new_sales_count' => (string) DealEvent::query()->where('kind', 'won')->whereIn('owner_id', $owners)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
            'mrc_added' => bcadd((string) (DealEvent::query()->where('kind', 'won')->whereIn('owner_id', $owners)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->sum('amount') ?: '0'), '0', 2),
            'leads_converted' => (string) Lead::query()->whereIn('owner_id', $owners)->where('status', 'converted')->whereDate('updated_at', '>=', $from)->whereDate('updated_at', '<=', $to)->count(),
            'calls_handled' => (string) Interaction::query()->whereIn('user_id', $owners)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
            'collections_amount' => bcadd((string) (Payment::query()->whereIn('received_by', $owners)->where('status', 'confirmed')->whereDate('received_at', '>=', $from)->whereDate('received_at', '<=', $to)->sum('amount') ?: '0'), '0', 2),
            'fcr_pct' => $this->fcr($owners, $from, $to),
            default => '0',
        };
    }

    /**
     * @param  list<int>  $owners
     */
    private function fcr(array $owners, string $from, string $to): string
    {
        $tickets = Ticket::query()->whereIn('owner_id', $owners)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $count = (clone $tickets)->count();

        if ($count < 1) {
            return '0.00';
        }

        $l1 = (clone $tickets)->where('status', 'resolved')->whereNull('escalation_reason')->whereNull('escalation_tier_id')->count();

        return bcadd(bcdiv(bcmul((string) $l1, '100', 2), (string) $count, 2), '0', 2);
    }

    /**
     * @return list<int>
     */
    private function subjectUsers(Target $target): array
    {
        if ($target->subject_type === 'user') {
            return [(int) $target->subject_id];
        }

        if ($target->subject_type === 'team') {
            return User::query()->whereHas('teams', fn (Builder $teams) => $teams->where('teams.id', $target->subject_id))->pluck('id')->all();
        }

        return User::query()->where('role', $target->subject_key)->pluck('id')->all();
    }

    private function moneyRule(string $key): string
    {
        return bcadd((string) $this->rule($key), '0', 2);
    }

    private function rule(string $key): mixed
    {
        $plan = CommissionPlan::query()->where('is_current', true)->first();
        $rules = $plan?->rules ?? [];

        if (array_key_exists($key, $rules)) {
            return $rules[$key];
        }

        return $this->commercial->get('commission_'.$key);
    }

    /**
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function rulesFrom(array $next): array
    {
        return [
            'mrc_percent' => bcadd((string) ($next['commission_mrc_percent'] ?? '10'), '0', 2),
            'install_amount' => bcadd((string) ($next['commission_install_amount'] ?? '5'), '0', 2),
            'payment_percent' => bcadd((string) ($next['commission_payment_percent'] ?? '0'), '0', 2),
            'accelerator_above' => (int) ($next['commission_accelerator_above'] ?? 100),
            'accelerator_percent' => bcadd((string) ($next['commission_accelerator_percent'] ?? '15'), '0', 2),
            'clawback_days' => (int) ($next['commission_clawback_days'] ?? 90),
        ];
    }

    private function percentOf(string $base, string $percent): string
    {
        return bcadd(bcdiv(bcmul($base, $percent, 4), '100', 4), '0', 2);
    }
}
