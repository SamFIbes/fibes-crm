<?php

namespace App\Services\Orders;

use App\Events\OrderActivated;
use App\Mail\OrderNotice;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\ExchangeRate;
use App\Models\Holiday;
use App\Models\Job;
use App\Models\Opportunity;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLine;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Approvals\ApprovalDesk;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockLedger;
use App\Services\Jobs\FieldWork;
use App\Services\Retention\RetentionDesk;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OrderDesk
{
    public function __construct(
        private OrderMachine $machine,
        private BillingGate $billing,
        private AuditLogger $audit,
        private AccessGate $access,
        private FieldWork $jobs,
        private StockLedger $stock,
    ) {}

    public function openFromWon(Opportunity $opportunity, ?User $actor = null, ?Request $request = null): ?Order
    {
        $existing = Order::query()->where('opportunity_id', $opportunity->id)->whereNull('archived_at')->first();

        if ($existing instanceof Order) {
            return $existing;
        }

        if (! $opportunity->customer_id) {
            return null;
        }

        $actor ??= $opportunity->owner ?? User::query()->where('role', 'super_admin')->first();

        if (! $actor instanceof User) {
            return null;
        }

        $quote = Quote::query()->where('opportunity_id', $opportunity->id)->where('status', 'accepted')->latest('id')->first();
        $contract = $quote instanceof Quote
            ? Contract::query()->where('quote_id', $quote->id)->where('status', 'signed')->latest('id')->first()
            : null;
        $request ??= Request::create('/');
        $lines = $this->linesFromQuote($quote, $opportunity);
        $order = $this->store($actor, [
            'customer_id' => $opportunity->customer_id,
            'opportunity_id' => $opportunity->id,
            'quote_id' => $quote?->id,
            'contract_id' => $contract?->id,
            'type' => 'new',
            'status' => 'submitted',
            'currency' => $quote?->currency ?? 'USD',
            'exchange_rate' => $quote?->exchange_rate ?? $this->rate(),
            'area_id' => $opportunity->area_id,
            'notes' => $contract ? null : 'Opened from a won deal.',
        ], $lines, $request);
        $this->ensureJob($order, $actor, $request);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function openChange(User $actor, array $input, Request $request): Order
    {
        $type = (string) $input['type'];
        $customer = Customer::query()->findOrFail((int) $input['customer_id']);
        $service = ! empty($input['customer_service_id'])
            ? CustomerService::query()->where('customer_id', $customer->id)->find((int) $input['customer_service_id'])
            : null;
        $price = isset($input['unit_price']) && is_numeric($input['unit_price']) ? bcadd((string) $input['unit_price'], '0', 2) : null;
        $current = $service instanceof CustomerService ? (string) $service->monthly_usd : '0.00';
        $changed = in_array($type, ['upgrade', 'downgrade'], true) && $price !== null && bccomp($price, $current, 2) !== 0;
        $needs = $type === 'termination' || $changed;
        $description = $service?->plan_name ?: __('ui.orders.type_'.$type);
        $lines = [[
            'description' => $description,
            'qty' => '1.00',
            'unit_price' => $price ?? $current,
            'line_total' => $price ?? $current,
            'is_recurring' => in_array($type, ['upgrade', 'downgrade', 'addon', 'reactivation'], true),
            'product_id' => $input['product_id'] ?? null,
            'stock_item_id' => $input['stock_item_id'] ?? null,
        ]];
        $fee = app(CommercialSettings::class)->money('return_charge');

        if ($type === 'termination' && $service instanceof CustomerService && $service->order_id) {
            $prior = Order::query()->find($service->order_id);

            if ($prior instanceof Order && bccomp((string) $prior->return_charge, '0', 2) === 1) {
                $fee = (string) $prior->return_charge;
            }
        }

        $order = $this->store($actor, [
            'customer_id' => $customer->id,
            'customer_service_id' => $service?->id,
            'type' => $type,
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => $this->rate(),
            'area_id' => $customer->area_id,
            'address' => trim((string) $customer->building.' '.(string) $customer->landmark),
            'notes' => $input['notes'] ?? null,
            'return_charge' => $type === 'termination' ? ($input['return_charge'] ?? $fee) : '0.00',
        ], $lines, $request);

        if (! $needs) {
            $this->transition($order, 'submitted', $actor, $request, []);
            $this->ensureJob($order->fresh() ?? $order, $actor, $request);

            return $order->fresh() ?? $order;
        }

        $this->transition($order, 'pending_approval', $actor, $request, []);
        $approvalType = $type === 'termination' ? 'contract_change' : 'plan_migration';
        $amount = $changed ? bcsub((string) $price, $current, 2) : (string) $order->return_charge;
        $approval = app(ApprovalDesk::class)->open(
            $actor,
            $approvalType,
            'order',
            $order->id,
            ['amount' => ltrim($amount, '-'), 'percent' => '0'],
            (string) ($order->fresh()->number ?? $order->id),
            $request,
        );
        $order->approval_id = $approval->id;
        $order->save();

        if ($type === 'termination') {
            app(RetentionDesk::class)->open($customer, $actor, $order->id, $service?->subscription_id);
        }

        return $order->fresh() ?? $order;
    }

    public function approveFromInbox(Order $order, User $actor, ?Request $request = null): void
    {
        if ($order->status !== 'pending_approval') {
            return;
        }

        $request ??= Request::create('/');
        $this->transition($order, 'submitted', $actor, $request, []);
        $this->ensureJob($order->fresh() ?? $order, $actor, $request);
    }

    public function reopen(Order $order, ?User $actor, ?Request $request): void
    {
        if ($order->status !== 'pending_approval') {
            return;
        }

        $order->status = 'draft';
        $order->save();
        $this->audit->record('order.draft', $actor, ['order_id' => $order->id, 'customer_id' => $order->customer_id], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function transition(Order $order, string $to, User $actor, Request $request, array $input = []): Order
    {
        $from = (string) $order->status;

        if ($from === 'on_hold' && $to !== 'cancelled' && $order->paused_status && $to !== $order->paused_status) {
            throw ValidationException::withMessages(['status' => __('ui.orders.illegal')]);
        }

        $this->machine->assert($from, $to);
        $this->guard($order, $to, $input);

        if ($to === 'on_hold') {
            $order->paused_status = $from;
            $order->hold_reason = trim((string) ($input['reason'] ?? ''));
        }

        if ($to === 'cancelled') {
            $order->cancel_reason = trim((string) ($input['reason'] ?? ''));
        }

        if (! empty($input['billing_confirmed'])) {
            $order->billing_confirmed = true;
        }

        if (! empty($input['deposit_paid'])) {
            $order->deposit_paid = true;
        }

        if ($to === 'activated') {
            $this->activate($order, $actor, $request, $input);
        }

        if ($to === 'completed' && $order->type === 'termination') {
            $service = $order->customer_service_id ? CustomerService::query()->find($order->customer_service_id) : null;

            if ($service instanceof CustomerService) {
                $service->status = 'terminated';
                $service->terminated_at = now();
                $service->termination_reason = $order->cancel_reason ?: $order->notes;
                $service->save();
            }
        }

        $order->status = $to;
        $order->updated_by = $actor->id;
        $order->save();
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
        ]);
        $this->audit->record('order.'.$to, $actor, [
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'from' => $from,
            'to' => $to,
            'total' => (string) $order->total,
        ], $request);

        if ($to === 'cancelled') {
            foreach ($order->jobs as $job) {
                $this->stock->releaseJob($job, $actor, $request);
            }
        }

        if ($to === 'submitted') {
            $this->ensureJob($order, $actor, $request);
        }

        if (in_array($to, ['scheduled', 'in_progress', 'completed'], true)) {
            $this->notify($order, $to, $actor, $request);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function reserve(Order $order, array $input, User $actor, Request $request): void
    {
        $job = $this->ensureJob($order, $actor, $request);
        $item = StockItem::query()->findOrFail((int) $input['item_id']);
        $location = StockLocation::query()->findOrFail((int) $input['location_id']);

        try {
            $this->stock->reserve($job, $item, $location, (string) $input['quantity'], $actor, $request);
        } catch (ValidationException $exception) {
            app(PurchasingDesk::class)->draftShortage($actor, $item, (string) $input['quantity'], $order, $request);

            throw ValidationException::withMessages(['quantity' => __('ui.orders.shortage')]);
        }

        if (! $order->lines()->where('stock_item_id', $item->id)->exists()) {
            OrderLine::query()->create([
                'order_id' => $order->id,
                'stock_item_id' => $item->id,
                'description' => $item->name_en,
                'qty' => $input['quantity'],
                'unit_price' => $item->unit_cost_usd ?? '0.00',
                'line_total' => '0.00',
                'is_recurring' => false,
                'sort' => $order->lines()->count(),
            ]);
        }
    }

    public function ensureJob(Order $order, User $actor, ?Request $request = null): Job
    {
        $existing = $order->jobs()->first();

        if ($existing instanceof Job) {
            return $existing;
        }

        $type = match ($order->type) {
            'relocation' => 'relocation',
            'termination' => 'removal',
            'upgrade', 'downgrade' => 'equipment_swap',
            default => 'installation',
        };
        $job = $this->jobs->create([
            'type' => $type,
            'status' => 'requested',
            'customer_id' => $order->customer_id,
            'customer_service_id' => $order->customer_service_id,
            'opportunity_id' => $order->opportunity_id,
            'order_id' => $order->id,
            'notes' => 'Requested from '.$order->number,
        ], $actor, $request);
        $this->audit->record('order.job', $actor, [
            'order_id' => $order->id,
            'job_id' => $job->id,
            'customer_id' => $order->customer_id,
            'type' => $type,
        ], $request);

        return $job;
    }

    public function whatsapp(Order $order): ?string
    {
        $customer = $order->customer;

        if (! $customer instanceof Customer || ! $customer->whatsapp_consent || ! $customer->whatsapp_e164) {
            return null;
        }

        $text = rawurlencode(__('ui.orders.whatsapp_text', ['number' => $order->number]));

        return 'https://wa.me/'.ltrim((string) $customer->whatsapp_e164, '+').'?text='.$text;
    }

    public function visible(User $user): Builder
    {
        $query = Order::query()->whereNull('archived_at');

        if ($this->access->allows($user, 'orders.view')) {
            return $this->access->restrict($query, $user, 'orders.view', 'owner_id', 'team_id');
        }

        if ($this->access->allows($user, 'orders.create')) {
            return $this->access->restrict($query, $user, 'orders.create', 'owner_id', 'team_id');
        }

        if ($this->access->allows($user, 'orders.activate') || $this->access->allows($user, 'jobs.schedule')) {
            return $query;
        }

        return $query->whereRaw('0 = 1');
    }

    public function find(User $user, int $id): Order
    {
        $order = Order::query()->find($id);
        abort_unless($order instanceof Order, 404);
        abort_unless($this->visible($user)->whereKey($order->id)->exists(), 403);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $lines
     */
    private function store(User $actor, array $input, array $lines, Request $request): Order
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $total = bcadd($total, (string) ($line['line_total'] ?? '0'), 2);
        }

        $order = Order::query()->create([
            'customer_id' => $input['customer_id'],
            'opportunity_id' => $input['opportunity_id'] ?? null,
            'contract_id' => $input['contract_id'] ?? null,
            'quote_id' => $input['quote_id'] ?? null,
            'customer_service_id' => $input['customer_service_id'] ?? null,
            'type' => $input['type'],
            'status' => 'draft',
            'requested_on' => now()->toDateString(),
            'sla_due' => $this->businessDays((int) app(CommercialSettings::class)->get('install_business_days')),
            'area_id' => $input['area_id'] ?? null,
            'address' => $input['address'] ?? null,
            'notes' => $input['notes'] ?? null,
            'currency' => $input['currency'] ?? 'USD',
            'exchange_rate' => $input['exchange_rate'] ?? $this->rate(),
            'total' => $total,
            'return_charge' => $input['return_charge'] ?? '0.00',
            'owner_id' => $actor->id,
            'team_id' => $actor->teams()->value('teams.id'),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($lines as $index => $line) {
            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'] ?? null,
                'stock_item_id' => $line['stock_item_id'] ?? null,
                'description' => $line['description'],
                'qty' => $line['qty'] ?? '1.00',
                'unit_price' => $line['unit_price'] ?? '0.00',
                'line_total' => $line['line_total'] ?? '0.00',
                'is_recurring' => (bool) ($line['is_recurring'] ?? false),
                'sort' => $index,
            ]);
        }

        $this->audit->record('order.created', $actor, [
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'type' => $order->type,
            'total' => (string) $order->total,
            'currency' => $order->currency,
            'rate' => (string) $order->exchange_rate,
        ], $request);

        if (($input['status'] ?? 'draft') !== 'draft') {
            $this->transition($order, (string) $input['status'], $actor, $request, []);
        }

        return $order->fresh() ?? $order;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesFromQuote(?Quote $quote, Opportunity $opportunity): array
    {
        if (! $quote instanceof Quote) {
            return [[
                'description' => 'Service',
                'qty' => '1.00',
                'unit_price' => (string) $opportunity->mrc,
                'line_total' => (string) $opportunity->mrc,
                'is_recurring' => true,
            ]];
        }

        return $quote->lines->map(fn (QuoteLine $line) => [
            'product_id' => $line->product_id,
            'description' => $line->description,
            'qty' => (string) $line->qty,
            'unit_price' => (string) $line->unit_price,
            'line_total' => (string) $line->line_total,
            'is_recurring' => (bool) $line->is_recurring,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function guard(Order $order, string $to, array $input): void
    {
        if (in_array($to, ['cancelled', 'on_hold'], true) && trim((string) ($input['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => __('ui.orders.reason')]);
        }

        if ($to === 'ready_to_schedule' && (bool) app(CommercialSettings::class)->get('deposit_required') && ! $order->deposit_paid && empty($input['deposit_paid'])) {
            throw ValidationException::withMessages(['deposit' => __('ui.orders.deposit_needed')]);
        }

        if ($to === 'ready_to_schedule' && $this->needsGear($order)) {
            $jobIds = $order->jobs()->pluck('id');
            $held = StockReservation::query()->whereIn('job_id', $jobIds)->where('status', 'held')->exists();

            if (! $held) {
                throw ValidationException::withMessages(['reserve' => __('ui.orders.gear_needed')]);
            }
        }

        if ($to === 'activated' && trim((string) ($input['radius_username'] ?? '')) === '') {
            throw ValidationException::withMessages(['radius_username' => __('ui.orders.radius_needed')]);
        }

        if ($to === 'completed' && ! $order->billing_confirmed && empty($input['billing_confirmed'])) {
            throw ValidationException::withMessages(['billing' => __('ui.orders.billing_needed')]);
        }

        if ($to === 'completed' && $order->type === 'termination' && ! app(RetentionDesk::class)->recorded($order)) {
            throw ValidationException::withMessages(['retention' => __('ui.orders.retention_needed')]);
        }
    }

    private function needsGear(Order $order): bool
    {
        return $order->lines()->whereNotNull('stock_item_id')->exists()
            || $order->lines()->whereHas('product', fn ($query) => $query->where('kind', 'equipment'))->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function activate(Order $order, User $actor, Request $request, array $input): void
    {
        $username = trim((string) $input['radius_username']);
        $line = $order->lines()->where('is_recurring', true)->first() ?? $order->lines()->first();
        $service = $order->customer_service_id ? CustomerService::query()->find($order->customer_service_id) : null;

        if (! $service instanceof CustomerService) {
            $service = CustomerService::query()->create([
                'customer_id' => $order->customer_id,
                'service_type' => $order->opportunity?->service_type ?: 'ftth',
                'plan_name' => $line?->description,
                'status' => 'active',
                'monthly_usd' => $order->currency === 'USD' ? ($line?->unit_price ?? $order->total) : '0.00',
                'area_id' => $order->area_id,
            ]);
            $order->customer_service_id = $service->id;
        }

        if (trim((string) $service->service_type) === '' || trim((string) ($service->plan_name ?: $line?->description)) === '') {
            throw ValidationException::withMessages(['radius_username' => __('ui.orders.radius_needed')]);
        }

        $service->fill([
            'plan_name' => $service->plan_name ?: $line?->description,
            'status' => 'active',
            'radius_username' => $username,
            'vlan' => $input['vlan'] ?? $service->vlan,
            'static_ip' => $input['static_ip'] ?? $service->static_ip,
            'mac' => $input['mac'] ?? $service->mac,
            'order_id' => $order->id,
            'activated_at' => now(),
            'install_date' => now()->toDateString(),
        ])->save();
        WorkTask::query()->create([
            'kind' => 'noc_krypton',
            'title' => __('ui.orders.krypton', ['number' => $order->number ?? 'OR']),
            'notes' => __('ui.orders.krypton_steps'),
            'status' => 'open',
            'priority' => 'high',
            'customer_id' => $order->customer_id,
            'job_id' => $order->jobs()->value('id'),
            'assignee_id' => $actor->id,
            'owner_id' => $actor->id,
            'due_at' => now()->addDay(),
        ]);
        $this->audit->record('order.activated_service', $actor, [
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'service_id' => $service->id,
        ], $request);
        $order->updated_by = $actor->id;
        OrderActivated::dispatch($order);
    }

    private function notify(Order $order, string $step, User $actor, Request $request): void
    {
        $order->loadMissing('customer');
        $link = $this->whatsapp($order);
        $email = $order->customer?->email;

        if (is_string($email) && $email !== '') {
            Mail::to($email)->send(new OrderNotice(
                __('ui.orders.notify_'.$step, ['number' => $order->number]),
                $link,
            ));
        }

        $this->audit->record('order.notice', $actor, [
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'step' => $step,
            'whatsapp' => $link !== null,
        ], $request);
    }

    private function businessDays(int $days): string
    {
        $date = now()->startOfDay();
        $added = 0;

        while ($added < $days) {
            $date->addDay();

            if ($date->isWeekend()) {
                continue;
            }

            if (Holiday::query()->whereDate('observed_on', $date->toDateString())->exists()) {
                continue;
            }

            $added++;
        }

        return $date->toDateString();
    }

    private function rate(): string
    {
        $rate = ExchangeRate::query()->latest('id')->value('usd_lbp');

        if (is_string($rate) && $rate !== '') {
            return bcadd($rate, '0', 4);
        }

        return app(CommercialSettings::class)->rate();
    }
}
