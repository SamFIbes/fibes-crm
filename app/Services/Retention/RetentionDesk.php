<?php

namespace App\Services\Retention;

use App\Models\ApprovalPolicy;
use App\Models\CollectionCase;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\DiscountLimit;
use App\Models\Order;
use App\Models\Plan;
use App\Models\RetentionCase;
use App\Models\RetentionEvent;
use App\Models\RetentionOffer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Ledger;
use App\Services\Billing\MoneyMath;
use App\Services\Catalog\PriceResolver;
use App\Services\Quotes\DiscountCap;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetentionDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private RetentionMachine $machine,
        private Ledger $ledger,
        private MoneyMath $money,
        private DiscountCap $caps,
        private PriceResolver $prices,
        private AuditLogger $audit,
        private AccessGate $access,
    ) {}

    public function open(Customer $customer, User $actor, ?int $orderId = null, ?int $subscriptionId = null): RetentionCase
    {
        $existing = RetentionCase::query()->where('customer_id', $customer->id)->whereIn('stage', ['open', 'offered', 'accepted'])->latest('id')->first();

        if ($existing instanceof RetentionCase) {
            if ($orderId && ! $existing->order_id) {
                $existing->order_id = $orderId;
                $existing->save();
            }

            return $existing;
        }

        $case = RetentionCase::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'stage' => 'open',
            'opened_on' => now()->toDateString(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit->record('retention.opened', $actor, [
            'case_id' => $case->id,
            'customer_id' => $customer->id,
            'order_id' => $orderId,
        ], Request::create('/'));

        return $case;
    }

    public function recorded(Order $order): bool
    {
        if ($order->type !== 'termination') {
            return true;
        }

        $case = RetentionCase::query()->where('order_id', $order->id)->latest('id')->first();

        return $case instanceof RetentionCase && $case->stage !== 'open';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function offer(RetentionCase $case, array $input, User $actor, Request $request): RetentionOffer
    {
        $kind = (string) $input['kind'];
        $this->assertKind($kind);
        $months = max(1, (int) ($input['months'] ?? 1));
        $percent = bcadd((string) ($input['percent'] ?? '0'), '0', 2);
        $customer = Customer::query()->findOrFail($case->customer_id);
        $subscription = $case->subscription_id ? Subscription::query()->find($case->subscription_id) : Subscription::query()->where('customer_id', $customer->id)->latest('id')->first();
        $catalog = $this->catalogPrice($customer, $subscription, isset($input['product_id']) ? (int) $input['product_id'] : null);
        $amount = $kind === 'discount'
            ? $this->money->round(bcdiv(bcmul($catalog, $percent, 6), '100', 6))
            : $this->money->round(bcmul($catalog, (string) $months, 6));
        $overCap = $kind === 'discount' && $this->caps->assess($actor, [[
            'percent' => $percent,
            'amount' => $amount,
            'custom' => false,
            'free' => false,
        ]], $catalog, $amount)['needs'];
        $overMonths = $kind === 'free_month' && $months > (int) $this->commercial->get('retention_free_months');
        $needsApproval = $overCap || $overMonths || ! $this->access->allows($actor, 'retention.offer');

        $offer = RetentionOffer::query()->create([
            'retention_case_id' => $case->id,
            'kind' => $kind,
            'months' => $months,
            'percent' => $percent,
            'product_id' => $input['product_id'] ?? null,
            'catalog_price' => $catalog,
            'amount' => $amount,
            'status' => $needsApproval ? 'pending' : 'accepted',
            'created_by' => $actor->id,
        ]);

        if ($case->stage === 'open') {
            $this->move($case, 'offered', $actor, $request);
        }

        if ($needsApproval) {
            $approval = app(ApprovalDesk::class)->open($actor, 'retention_offer', 'retention_offer', $offer->id, [
                'months' => $months,
                'percent' => $percent,
                'amount' => $amount,
                'kind' => $kind,
            ], (string) $customer->name_en, $request);
            $offer->approval_id = $approval->id;
            $offer->save();

            if ($approval->status === 'approved') {
                $this->apply($offer, $actor, $request);
            }
        } else {
            $this->apply($offer, $actor, $request);
        }

        $this->audit->record('retention.offered', $actor, [
            'case_id' => $case->id,
            'offer_id' => $offer->id,
            'kind' => $kind,
            'months' => $months,
            'percent' => $percent,
            'catalog_price' => $catalog,
        ], $request);

        return $offer->fresh() ?? $offer;
    }

    public function decline(RetentionCase $case, string $reason, User $actor, Request $request): void
    {
        $this->assertReason($reason);
        $case->reason = $reason;
        $case->outcome = 'declined';
        if ($this->machine->allows((string) $case->stage, 'declined')) {
            $this->move($case, 'declined', $actor, $request);
        }
        $case->save();
        $this->audit->record('retention.declined', $actor, [
            'case_id' => $case->id,
            'customer_id' => $case->customer_id,
            'reason' => $reason,
        ], $request);
    }

    public function applyApproved(int $offerId, User $actor, ?Request $request): void
    {
        $offer = RetentionOffer::query()->find($offerId);

        if ($offer instanceof RetentionOffer && $offer->status === 'pending') {
            $this->apply($offer, $actor, $request ?? Request::create('/'));
        }
    }

    public function sweep(?User $actor): void
    {
        $who = $actor ?? User::query()->where('role', 'super_admin')->first();

        if (! $who instanceof User) {
            return;
        }

        Customer::query()->orderBy('id')->each(function (Customer $customer) use ($who): void {
            if ($this->score($customer) < (int) $this->commercial->get('at_risk_below')) {
                $this->once($customer, 'at_risk', $who, __('ui.retention.at_risk_task'));
                $tags = $customer->tags ?? [];

                if (! in_array('at-risk', $tags, true)) {
                    $tags[] = 'at-risk';
                    $customer->tags = $tags;
                    $customer->save();
                }
            }
        });

        $days = max(1, (int) $this->commercial->get('winback_days'));
        CustomerService::query()->where('status', 'terminated')->whereNotNull('terminated_at')->where('terminated_at', '<=', now()->subDays($days))->orderBy('id')->each(function (CustomerService $service) use ($who): void {
            $customer = Customer::query()->find($service->customer_id);

            if ($customer instanceof Customer) {
                $this->once($customer, 'winback', $who, __('ui.retention.winback_task'));
            }
        });
    }

    /**
     * @return array{saved: int, closed: int, rate: string}
     */
    public function saveRate(): array
    {
        $saved = RetentionCase::query()->where('stage', 'saved')->count();
        $lost = RetentionCase::query()->whereIn('stage', ['declined', 'churned'])->count();
        $closed = $saved + $lost;
        $rate = $closed > 0 ? bcdiv((string) ($saved * 100), (string) $closed, 2) : '0.00';

        return ['saved' => $saved, 'closed' => $closed, 'rate' => $rate];
    }

    public function score(Customer $customer): int
    {
        $score = 100;

        if (CollectionCase::query()->where('customer_id', $customer->id)->whereNotIn('stage', ['paid', 'terminated', 'written_off'])->exists()) {
            $score -= 40;
        }

        if (CustomerService::query()->where('customer_id', $customer->id)->where('status', 'suspended')->exists()) {
            $score -= 30;
        }

        if (bccomp($this->ledger->balance($customer->id), '0', 2) === 1) {
            $score -= 25;
        }

        return $score;
    }

    /**
     * @return array<string, int>
     */
    public function reasons(): array
    {
        $counts = [];

        foreach ($this->reasonList() as $reason) {
            $counts[$reason] = RetentionCase::query()->where('reason', $reason)->whereIn('stage', ['declined', 'churned'])->count();
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function reasonList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->commercial->get('churn_reasons')))));
    }

    public function syncPolicy(): void
    {
        $months = (int) $this->commercial->get('retention_free_months');
        $cap = DiscountLimit::query()->where('role', 'cc_supervisor')->value('line_percent');
        $percent = $cap !== null ? bcadd((string) $cap, '0', 2) : '5.00';
        $policy = ApprovalPolicy::query()->where('key', 'retention_offer')->first();

        if (! $policy instanceof ApprovalPolicy) {
            return;
        }

        $policy->steps = [
            ['roles' => ['cc_supervisor'], 'mode' => 'any_of', 'when' => ['months_up_to' => $months, 'percent_up_to' => $percent], 'timeout_hours' => 4],
            ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['months_above' => $months], 'timeout_hours' => 4],
            ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['percent_above' => $percent], 'timeout_hours' => 4],
        ];
        $policy->save();
    }

    private function apply(RetentionOffer $offer, User $actor, Request $request): void
    {
        $case = RetentionCase::query()->findOrFail($offer->retention_case_id);
        $subscription = $case->subscription_id
            ? Subscription::query()->find($case->subscription_id)
            : Subscription::query()->where('customer_id', $case->customer_id)->latest('id')->first();

        if ($offer->kind === 'free_month') {
            $this->ledger->post($case->customer_id, 'credit', '0.00', (string) $offer->amount, 'USD', app(CommercialSettings::class)->rate(), 'retention_offer', $offer->id, $actor);
        }

        if ($offer->kind === 'discount' && $subscription instanceof Subscription) {
            $next = $this->money->round(bcsub((string) $offer->catalog_price, (string) $offer->amount, 6));
            $subscription->price = $next;
            $subscription->updated_by = $actor->id;
            $subscription->save();
        }

        if ($offer->kind === 'upgrade' && $subscription instanceof Subscription) {
            $subscription->price = $offer->catalog_price;
            $subscription->plan_id = $offer->product_id ? Plan::query()->where('product_id', $offer->product_id)->value('id') : $subscription->plan_id;
            $subscription->updated_by = $actor->id;
            $subscription->save();
        }

        $offer->status = 'accepted';
        $offer->save();

        if ($this->machine->allows((string) $case->stage, 'accepted')) {
            $this->move($case, 'accepted', $actor, $request);
        }

        $case->refresh();

        if ($this->machine->allows((string) $case->stage, 'saved')) {
            $case->outcome = 'saved';
            $this->move($case, 'saved', $actor, $request);
        }

        $this->audit->record('retention.applied', $actor, [
            'offer_id' => $offer->id,
            'case_id' => $case->id,
            'customer_id' => $case->customer_id,
            'kind' => $offer->kind,
            'amount' => (string) $offer->amount,
        ], $request);
    }

    private function move(RetentionCase $case, string $to, User $actor, Request $request): void
    {
        $from = (string) $case->stage;
        $this->machine->assert($from, $to);
        $case->stage = $to;
        $case->updated_by = $actor->id;
        $case->save();
        RetentionEvent::query()->create([
            'retention_case_id' => $case->id,
            'from_stage' => $from,
            'to_stage' => $to,
            'actor_id' => $actor->id,
        ]);
    }

    private function catalogPrice(Customer $customer, ?Subscription $subscription, ?int $productId): string
    {
        if ($productId) {
            $quote = $this->prices->resolve($productId, now()->toDateString(), $customer->type, $customer->area_id);

            if (is_array($quote) && isset($quote['price'])) {
                return bcadd((string) $quote['price'], '0', 2);
            }
        }

        return bcadd((string) ($subscription?->price ?? '0.00'), '0', 2);
    }

    private function assertKind(string $kind): void
    {
        $allowed = [
            'free_month' => (bool) $this->commercial->get('retention_allow_free_month'),
            'discount' => (bool) $this->commercial->get('retention_allow_discount'),
            'upgrade' => (bool) $this->commercial->get('retention_allow_upgrade'),
        ];

        if (! ($allowed[$kind] ?? false)) {
            throw ValidationException::withMessages(['kind' => __('ui.retention.kind_off')]);
        }
    }

    private function assertReason(string $reason): void
    {
        if (! in_array($reason, $this->reasonList(), true)) {
            throw ValidationException::withMessages(['reason' => __('ui.retention.reason')]);
        }
    }

    private function once(Customer $customer, string $action, User $actor, string $title): void
    {
        $inserted = DB::table('retention_fires')->insertOrIgnore([
            'customer_id' => $customer->id,
            'action' => $action,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        WorkTask::query()->create([
            'kind' => 'retention',
            'title' => $title,
            'notes' => $title,
            'status' => 'open',
            'priority' => 'normal',
            'customer_id' => $customer->id,
            'owner_id' => $actor->id,
            'due_at' => now()->addDay(),
        ]);
        $this->audit->record('retention.'.$action, $actor, ['customer_id' => $customer->id], Request::create('/'));
    }
}
