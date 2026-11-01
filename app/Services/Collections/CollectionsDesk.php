<?php

namespace App\Services\Collections;

use App\Jobs\RequestReactivation;
use App\Mail\OrderNotice;
use App\Models\CollectionCase;
use App\Models\CollectionEvent;
use App\Models\CollectionExemption;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingDesk;
use App\Services\Billing\Ledger;
use App\Services\Billing\SubscriptionMachine;
use App\Services\Retention\RetentionDesk;
use App\Services\Settings\CommercialSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CollectionsDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private CollectionMachine $machine,
        private SubscriptionMachine $subscriptions,
        private Ledger $ledger,
        private AuditLogger $audit,
        private RadiusDriver $radius,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return [
            ['action' => 'reminder', 'offset' => (int) $this->commercial->get('dunning_reminder_days')],
            ['action' => 'expiry', 'offset' => (int) $this->commercial->get('dunning_expiry_days')],
            ['action' => 'warning', 'offset' => (int) $this->commercial->get('dunning_warning_days')],
            ['action' => 'suspend', 'offset' => (int) $this->commercial->get('dunning_suspend_days')],
            ['action' => 'terminate', 'offset' => (int) $this->commercial->get('dunning_terminate_days')],
        ];
    }

    public function syncPolicy(): void
    {
        DB::table('dunning_policies')->updateOrInsert(['key' => 'default'], [
            'steps' => json_encode($this->steps()),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function run(?User $actor): int
    {
        $this->syncPolicy();
        $count = 0;
        $who = $actor ?? User::query()->where('role', 'super_admin')->first();

        if (! $who instanceof User) {
            return 0;
        }

        Subscription::query()->whereIn('status', ['active', 'grace', 'expired'])->orderBy('id')->each(function (Subscription $subscription) use ($who, &$count): void {
            $days = $this->daysAfter($subscription->current_period_end);

            foreach ($this->steps() as $step) {
                if ($days < (int) $step['offset']) {
                    continue;
                }

                if ($this->fired($subscription, (string) $step['action'])) {
                    continue;
                }

                $this->fire($subscription, (string) $step['action'], $who);
                $count++;
            }

            $this->renewalTask($subscription, $who);
        });

        CollectionCase::query()->where('stage', 'promise')->whereDate('promise_to_pay_date', '<', now()->toDateString())->orderBy('id')->each(function (CollectionCase $case) use ($who): void {
            $this->move($case, 'broken', $who, Request::create('/'));
        });

        return $count;
    }

    public function promise(CollectionCase $case, string $date, string $amount, User $actor, Request $request): void
    {
        $this->move($case, 'promise', $actor, $request);
        $case->loadMissing('customer');
        $case->promise_to_pay_date = $date;
        $case->promise_amount = bcadd($amount, '0', 2);
        $case->updated_by = $actor->id;
        $case->save();
        WorkTask::query()->create([
            'kind' => 'collection',
            'title' => __('ui.collections.promise_task', ['name' => $case->customer?->name_en ?? $case->customer_id]),
            'notes' => __('ui.collections.promise_note', ['date' => $date, 'amount' => $amount]),
            'status' => 'open',
            'priority' => 'normal',
            'customer_id' => $case->customer_id,
            'assignee_id' => $case->assigned_to ?: $actor->id,
            'owner_id' => $actor->id,
            'due_at' => Carbon::parse($date)->setTime(9, 0),
        ]);
        $this->audit->record('collection.promise', $actor, [
            'case_id' => $case->id,
            'customer_id' => $case->customer_id,
            'date' => $date,
            'amount' => $amount,
        ], $request);
    }

    public function assign(CollectionCase $case, int $userId, User $actor, Request $request): void
    {
        $case->assigned_to = $userId;
        $case->updated_by = $actor->id;
        $case->save();
        $this->audit->record('collection.assigned', $actor, ['case_id' => $case->id, 'assigned_to' => $userId], $request);
    }

    public function suspend(CollectionCase $case, User $actor, Request $request): void
    {
        if ($this->exempt($case->customer_id)) {
            $this->audit->record('collection.exempt_skipped', $actor, ['customer_id' => $case->customer_id, 'case_id' => $case->id], $request);

            return;
        }

        $this->suspendService($case->customer_id, $actor, 'suspend');
        if ($this->machine->allows((string) $case->stage, 'suspended')) {
            $this->move($case, 'suspended', $actor, $request);
        }
    }

    public function terminate(CollectionCase $case, User $actor, Request $request): void
    {
        $subscription = $case->subscription_id ? Subscription::query()->find($case->subscription_id) : null;

        if ($subscription instanceof Subscription && $this->subscriptions->allows((string) $subscription->status, 'terminated')) {
            $subscription->status = 'terminated';
            $subscription->updated_by = $actor->id;
            $subscription->save();
        }

        CustomerService::query()->where('customer_id', $case->customer_id)->where('status', 'suspended')->update([
            'status' => 'terminated',
            'terminated_at' => now(),
        ]);
        $this->kryptonTask($case->customer_id, 'terminate', $actor);
        if ($this->machine->allows((string) $case->stage, 'terminated')) {
            $this->move($case, 'terminated', $actor, $request);
        }
        $this->audit->record('collection.terminated', $actor, ['case_id' => $case->id, 'customer_id' => $case->customer_id], $request);
    }

    public function requestExemption(Customer $customer, string $kind, ?string $until, User $actor, Request $request): void
    {
        $days = $until ? max(1, (int) now()->startOfDay()->diffInDays(Carbon::parse($until)->startOfDay(), false)) : 365;
        app(ApprovalDesk::class)->open($actor, 'suspension_exemption', 'customer', $customer->id, [
            'days' => $days,
            'kind' => $kind,
            'until' => $until,
        ], __('ui.collections.exempt'), $request);
        $this->audit->record('collection.exemption_requested', $actor, ['customer_id' => $customer->id, 'kind' => $kind, 'until' => $until], $request);
    }

    public function applyExemption(int $customerId, string $kind, ?string $until, int $approvalId, User $actor, ?Request $request): void
    {
        $request ??= Request::create('/');
        CollectionExemption::query()->create([
            'customer_id' => $customerId,
            'kind' => $kind,
            'until' => $until,
            'approval_id' => $approvalId,
            'active' => true,
            'created_by' => $actor->id,
        ]);
        $this->audit->record('collection.exempted', $actor, [
            'customer_id' => $customerId,
            'kind' => $kind,
            'until' => $until,
            'approval_id' => $approvalId,
        ], $request);
    }

    public function onPayment(int $customerId, User $actor): void
    {
        $suspended = Subscription::query()->where('customer_id', $customerId)->where('status', 'suspended')->exists()
            || CustomerService::query()->where('customer_id', $customerId)->where('status', 'suspended')->exists();
        $balance = $this->ledger->balance($customerId);

        if (bccomp($balance, '0', 2) === 1) {
            return;
        }

        if ($suspended) {
            RequestReactivation::dispatch($customerId, $actor->id);

            return;
        }

        $this->markPaid($customerId, $actor);
    }

    public function reactivateRequest(int $customerId, User $actor): void
    {
        $this->kryptonTask($customerId, 'reactivate', $actor);
        $fee = $this->commercial->money('reconnect_fee');

        if (bccomp($fee, '0', 2) === 1) {
            app(BillingDesk::class)->createDocument($customerId, 'invoice', 'INV', [[
                'description' => __('ui.collections.reconnect'),
                'qty' => '1.00',
                'unit_price' => $fee,
                'is_recurring' => false,
            ]], $actor, Request::create('/'));
        }

        $username = (string) (CustomerService::query()->where('customer_id', $customerId)->value('radius_username') ?: 'customer-'.$customerId);
        $this->radius->reactivate($username);
        $this->markPaid($customerId, $actor);
        $this->audit->record('collection.reactivation_requested', $actor, ['customer_id' => $customerId, 'fee' => $fee], Request::create('/'));
    }

    public function markReactivated(CollectionCase $case, User $actor, Request $request): void
    {
        CustomerService::query()->where('customer_id', $case->customer_id)->where('status', 'suspended')->update([
            'status' => 'active',
            'suspended_at' => null,
        ]);
        Subscription::query()->where('customer_id', $case->customer_id)->where('status', 'suspended')->update([
            'status' => 'active',
            'updated_by' => $actor->id,
        ]);
        if ($this->machine->allows((string) $case->stage, 'paid')) {
            $this->move($case, 'paid', $actor, $request);
        }
        $this->audit->record('collection.reactivated', $actor, ['case_id' => $case->id, 'customer_id' => $case->customer_id], $request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function renewals(): array
    {
        $window = max(1, (int) $this->commercial->get('renewal_window_days'));
        $until = now()->addDays($window)->toDateString();
        $rows = [];

        Subscription::query()
            ->with('customer:id,name_en,name_ar')
            ->where('status', 'active')
            ->where('prepaid', true)
            ->whereDate('current_period_end', '>=', now()->toDateString())
            ->whereDate('current_period_end', '<=', $until)
            ->orderBy('current_period_end')
            ->each(function (Subscription $subscription) use (&$rows): void {
                $rows[] = [
                    'id' => $subscription->id,
                    'customer_id' => $subscription->customer_id,
                    'customer' => $subscription->customer?->name_en,
                    'customer_ar' => $subscription->customer?->name_ar,
                    'ends' => $subscription->current_period_end?->timezone((string) config('app.timezone'))->format('d/m/Y'),
                    'ends_on' => $subscription->current_period_end?->toDateString(),
                    'auto_renew' => (bool) $subscription->auto_renew,
                    'price' => (string) $subscription->price,
                ];
            });

        return $rows;
    }

    public function renewalQueryCount(): int
    {
        $window = max(1, (int) $this->commercial->get('renewal_window_days'));

        return Subscription::query()
            ->where('status', 'active')
            ->where('prepaid', true)
            ->whereDate('current_period_end', '>=', now()->toDateString())
            ->whereDate('current_period_end', '<=', now()->addDays($window)->toDateString())
            ->count();
    }

    public function bucket(CollectionCase $case): string
    {
        $days = (int) $case->opened_on->startOfDay()->diffInDays(now()->startOfDay());

        if ($days <= 7) {
            return '0-7';
        }

        if ($days <= 30) {
            return '8-30';
        }

        if ($days <= 60) {
            return '31-60';
        }

        return '60+';
    }

    public function exempt(int $customerId): bool
    {
        return CollectionExemption::query()->where('customer_id', $customerId)->where('active', true)->get()->contains(function (CollectionExemption $row): bool {
            return $row->until === null || $row->until->toDateString() >= now()->toDateString();
        });
    }

    private function fire(Subscription $subscription, string $action, User $actor): void
    {
        $period = $subscription->current_period_end?->toDateString() ?? now()->toDateString();
        $inserted = DB::table('dunning_fires')->insertOrIgnore([
            'subscription_id' => $subscription->id,
            'period_end' => $period,
            'action' => $action,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $case = $this->ensureCase($subscription, $actor);
        $request = Request::create('/');

        if (in_array($action, ['reminder', 'expiry', 'warning'], true)) {
            $this->remind($subscription, $case, $action, $actor);
            if ($this->machine->allows((string) $case->stage, 'reminded')) {
                $this->move($case, 'reminded', $actor, $request);
            }
        }

        if ($action === 'suspend') {
            $grace = (int) $subscription->grace_days;
            $offset = (int) $this->commercial->get('dunning_suspend_days');
            $days = $this->daysAfter($subscription->current_period_end);

            if ($days < max($offset, $grace)) {
                DB::table('dunning_fires')->where('subscription_id', $subscription->id)->where('period_end', $period)->where('action', 'suspend')->delete();

                return;
            }

            if ($this->exempt($subscription->customer_id)) {
                $this->audit->record('collection.exempt_skipped', $actor, ['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id], $request);

                return;
            }

            $this->suspendService($subscription->customer_id, $actor, 'suspend');
            if ($this->machine->allows((string) $case->fresh()->stage, 'suspended')) {
                $this->move($case->fresh() ?? $case, 'suspended', $actor, $request);
            }
        }

        if ($action === 'terminate') {
            $customer = Customer::query()->find($subscription->customer_id);

            if ($customer instanceof Customer) {
                app(RetentionDesk::class)->open($customer, $actor, null, $subscription->id);
            }

            $this->audit->record('collection.terminate_requested', $actor, ['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id], $request);
        }
    }

    private function remind(Subscription $subscription, CollectionCase $case, string $action, User $actor): void
    {
        $customer = Customer::query()->find($subscription->customer_id);
        $link = $this->whatsapp($customer, $action);
        $notes = $link ?? __('ui.collections.no_whatsapp');

        if ($customer instanceof Customer && is_string($customer->email) && $customer->email !== '') {
            Mail::to($customer->email)->send(new OrderNotice(__('ui.collections.mail_'.$action, ['name' => $customer->name_en]), $link));
        }

        WorkTask::query()->create([
            'kind' => 'collection',
            'title' => __('ui.collections.task_'.$action),
            'notes' => $notes,
            'status' => 'open',
            'priority' => $action === 'warning' ? 'high' : 'normal',
            'customer_id' => $subscription->customer_id,
            'assignee_id' => $case->assigned_to,
            'owner_id' => $actor->id,
            'due_at' => now()->addDay(),
        ]);
        $this->audit->record('collection.'.$action, $actor, [
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'whatsapp' => $link !== null,
        ], Request::create('/'));
    }

    private function suspendService(int $customerId, User $actor, string $action): void
    {
        Subscription::query()->where('customer_id', $customerId)->whereIn('status', ['active', 'grace', 'expired'])->orderBy('id')->each(function (Subscription $subscription) use ($actor): void {
            if ($this->subscriptions->allows((string) $subscription->status, 'suspended')) {
                $subscription->status = 'suspended';
                $subscription->updated_by = $actor->id;
                $subscription->save();
            }
        });
        CustomerService::query()->where('customer_id', $customerId)->where('status', 'active')->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'non-payment',
        ]);
        $username = (string) (CustomerService::query()->where('customer_id', $customerId)->value('radius_username') ?: 'customer-'.$customerId);
        $this->radius->suspend($username);
        $this->kryptonTask($customerId, $action, $actor);
        $this->audit->record('collection.suspended', $actor, ['customer_id' => $customerId], Request::create('/'));
    }

    private function kryptonTask(int $customerId, string $action, User $actor): void
    {
        WorkTask::query()->create([
            'kind' => 'noc_krypton',
            'title' => __('ui.collections.krypton_'.$action),
            'notes' => __('ui.collections.krypton_steps'),
            'status' => 'open',
            'priority' => 'high',
            'customer_id' => $customerId,
            'owner_id' => $actor->id,
            'due_at' => now()->addHour(),
        ]);
    }

    private function ensureCase(Subscription $subscription, User $actor): CollectionCase
    {
        $existing = CollectionCase::query()->where('customer_id', $subscription->customer_id)->whereNotIn('stage', ['paid', 'terminated', 'written_off'])->latest('id')->first();

        if ($existing instanceof CollectionCase) {
            return $existing;
        }

        $invoiceId = DB::table('invoices')->where('subscription_id', $subscription->id)->where('type', 'invoice')->whereNotIn('status', ['voided', 'draft', 'paid'])->orderBy('id')->value('id');

        return CollectionCase::query()->create([
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoiceId,
            'amount_due' => $this->ledger->balance($subscription->customer_id),
            'stage' => 'open',
            'opened_on' => now()->toDateString(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function move(CollectionCase $case, string $to, User $actor, Request $request): void
    {
        $from = (string) $case->stage;
        $this->machine->assert($from, $to);
        $case->stage = $to;
        $case->updated_by = $actor->id;
        $case->save();
        CollectionEvent::query()->create([
            'collection_case_id' => $case->id,
            'from_stage' => $from,
            'to_stage' => $to,
            'actor_id' => $actor->id,
        ]);
        $this->audit->record('collection.'.$to, $actor, ['case_id' => $case->id, 'customer_id' => $case->customer_id, 'from' => $from], $request);
    }

    private function markPaid(int $customerId, User $actor): void
    {
        CollectionCase::query()->where('customer_id', $customerId)->whereNotIn('stage', ['paid', 'terminated', 'written_off'])->orderBy('id')->each(function (CollectionCase $case) use ($actor): void {
            if ($this->machine->allows((string) $case->stage, 'paid')) {
                $this->move($case, 'paid', $actor, Request::create('/'));
            }
        });
    }

    private function daysAfter(?Carbon $end): int
    {
        if (! $end instanceof Carbon) {
            return 0;
        }

        return (int) floor($end->copy()->startOfDay()->diffInSeconds(now()->startOfDay(), false) / 86400);
    }

    private function fired(Subscription $subscription, string $action): bool
    {
        return DB::table('dunning_fires')->where('subscription_id', $subscription->id)->where('period_end', $subscription->current_period_end?->toDateString())->where('action', $action)->exists();
    }

    private function whatsapp(?Customer $customer, string $action): ?string
    {
        if (! $customer instanceof Customer || ! $customer->whatsapp_consent || ! $customer->whatsapp_e164) {
            return null;
        }

        $text = rawurlencode(__('ui.collections.wa_'.$action, ['name' => $customer->name_en]));

        return 'https://wa.me/'.ltrim((string) $customer->whatsapp_e164, '+').'?text='.$text;
    }

    private function renewalTask(Subscription $subscription, User $actor): void
    {
        if (! $subscription->prepaid || $subscription->auto_renew) {
            return;
        }

        $soon = max(1, (int) $this->commercial->get('renewal_soon_days'));
        $end = $subscription->current_period_end;

        if (! $end instanceof Carbon || $end->toDateString() > now()->addDays($soon)->toDateString() || $end->toDateString() < now()->toDateString()) {
            return;
        }

        if ($this->fired($subscription, 'renewal')) {
            return;
        }

        DB::table('dunning_fires')->insert([
            'subscription_id' => $subscription->id,
            'period_end' => $end->toDateString(),
            'action' => 'renewal',
            'created_at' => now(),
        ]);
        WorkTask::query()->create([
            'kind' => 'collection',
            'title' => __('ui.collections.renewal_task'),
            'notes' => __('ui.collections.renewal_note'),
            'status' => 'open',
            'priority' => 'normal',
            'customer_id' => $subscription->customer_id,
            'owner_id' => $actor->id,
            'due_at' => $end,
        ]);
    }
}
