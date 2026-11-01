<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkTask;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Retention\RetentionDesk;
use App\Services\Settings\CommercialSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class AutomationDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private AuditLogger $audit,
        private WebhookDesk $webhooks,
        private RetentionDesk $retention,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $trigger, array $payload, int $depth = 0, bool $repeat = false): void
    {
        if ($this->paused()) {
            $this->run(null, $trigger, 'paused', $payload, $depth, 'paused');

            return;
        }

        $max = max(1, (int) $this->commercial->get('automations_loop_depth'));

        if ($depth >= $max) {
            $this->run(null, $trigger, 'blocked', $payload, $depth, 'loop');

            return;
        }

        Automation::query()->where('trigger', $trigger)->where('is_current', true)->where('enabled', true)->orderBy('id')->each(function (Automation $rule) use ($trigger, $payload, $depth, $repeat, $max): void {
            if (! $repeat && $this->already($rule, $payload)) {
                return;
            }

            if ($this->limited($rule)) {
                $this->run($rule, $trigger, 'limited', $payload, $depth, 'rate');

                return;
            }

            if (! $this->matches($rule->conditions ?? [], $payload)) {
                return;
            }

            $notes = [];

            foreach ($rule->actions ?? [] as $action) {
                if (! is_array($action)) {
                    continue;
                }

                $notes[] = $this->act($action, $payload, $depth, $max);
            }

            $this->run($rule, $trigger, 'done', $payload, $depth, implode(', ', $notes));
        });

        if ($depth === 0) {
            $this->webhooks->queue($trigger, $payload);
        }
    }

    /**
     * @return array{matches: int, preview: list<array<string, mixed>>}
     */
    public function dryRun(Automation $rule): array
    {
        $days = max(1, (int) $this->commercial->get('automations_dry_days'));
        $from = now()->subDays($days);
        $preview = [];

        foreach ($this->candidates((string) $rule->trigger, $from) as $payload) {
            if ($this->matches($rule->conditions ?? [], $payload)) {
                $preview[] = ['subject_type' => $payload['subject_type'] ?? null, 'subject_id' => $payload['subject_id'] ?? null, 'actions' => array_column($rule->actions ?? [], 'type')];
            }
        }

        return ['matches' => count($preview), 'preview' => array_slice($preview, 0, 20)];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function revise(Automation $current, array $input, User $actor, Request $request): Automation
    {
        $current->is_current = false;
        $current->save();
        $next = Automation::query()->create([
            'key' => $current->key,
            'version' => $current->version + 1,
            'name_en' => $input['name_en'] ?? $current->name_en,
            'name_ar' => $input['name_ar'] ?? $current->name_ar,
            'trigger' => $input['trigger'] ?? $current->trigger,
            'conditions' => $input['conditions'] ?? $current->conditions,
            'actions' => $input['actions'] ?? $current->actions,
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $current->enabled,
            'is_current' => true,
            'rate_per_hour' => (int) ($input['rate_per_hour'] ?? $current->rate_per_hour),
            'created_by' => $actor->id,
        ]);
        $this->audit->record('automation.revised', $actor, [
            'key' => $next->key,
            'version' => $next->version,
            'enabled' => $next->enabled,
        ], $request);

        return $next;
    }

    public function runDue(): int
    {
        $fired = 0;
        $minutes = max(1, (int) $this->commercial->get('p1_unassigned_minutes'));

        foreach (DB::table('leads')->whereNotNull('sla_breached_at')->where('sla_breached_at', '>=', now()->subDay())->orderBy('id')->limit(50)->get() as $lead) {
            $this->emit('lead.sla_breach', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'lead_id' => $lead->id, 'customer_id' => null]);
            $fired++;
        }

        foreach (DB::table('tickets')->where('priority', 'P1')->whereNull('assignee_id')->where('created_at', '<=', now()->subMinutes($minutes))->whereNotIn('status', ['resolved', 'closed'])->orderBy('id')->limit(50)->get() as $ticket) {
            $this->emit('ticket.p1_unassigned', ['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'ticket_id' => $ticket->id, 'customer_id' => $ticket->customer_id, 'priority' => 'P1']);
            $fired++;
        }

        foreach (DB::table('tickets')->where('status', 'resolved')->where('updated_at', '>=', now()->subDay())->orderBy('id')->limit(50)->get() as $ticket) {
            $this->emit('ticket.resolved', ['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'ticket_id' => $ticket->id, 'customer_id' => $ticket->customer_id]);
            $fired++;
        }

        $below = (int) $this->commercial->get('at_risk_below');

        foreach (Customer::query()->orderBy('id')->limit(100)->get() as $customer) {
            if ($this->retention->score($customer) < $below) {
                $this->emit('customer.health', ['subject_type' => 'customer', 'subject_id' => $customer->id, 'customer_id' => $customer->id]);
                $fired++;
            }
        }

        $days = max(1, (int) $this->commercial->get('renewal_soon_days'));

        foreach (DB::table('subscriptions')->whereNotNull('next_invoice_at')->whereDate('next_invoice_at', '<=', now()->addDays($days)->toDateString())->whereDate('next_invoice_at', '>=', now()->toDateString())->orderBy('id')->limit(50)->get() as $subscription) {
            $this->emit('subscription.expiring', ['subject_type' => 'subscription', 'subject_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
            $fired++;
        }

        foreach (DB::table('stock_items')->where('low_stock_alerted', true)->orderBy('id')->limit(50)->get() as $item) {
            $this->emit('stock.low', ['subject_type' => 'stock_item', 'subject_id' => $item->id, 'label' => $item->name_en]);
            $fired++;
        }

        return $fired;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function already(Automation $rule, array $payload): bool
    {
        $subject = $payload['subject_id'] ?? null;

        if (! is_numeric($subject)) {
            return false;
        }

        return AutomationRun::query()->where('automation_id', $rule->id)->where('subject_id', (int) $subject)->where('status', 'done')->exists();
    }

    private function limited(Automation $rule): bool
    {
        $count = AutomationRun::query()->where('automation_id', $rule->id)->where('created_at', '>=', now()->subHour())->where('status', 'done')->count();

        return $count >= max(1, (int) $rule->rate_per_hour);
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     */
    private function matches(array $conditions, array $payload): bool
    {
        $rules = $conditions['rules'] ?? [];

        if ($rules === []) {
            return true;
        }

        $checks = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $actual = $payload[$rule['field'] ?? ''] ?? null;
            $value = $rule['value'] ?? null;
            $checks[] = match ($rule['cmp'] ?? 'eq') {
                'gt' => (float) $actual > (float) $value,
                'lt' => (float) $actual < (float) $value,
                default => (string) $actual === (string) $value,
            };
        }

        if ($checks === []) {
            return true;
        }

        return ($conditions['op'] ?? 'and') === 'or' ? in_array(true, $checks, true) : ! in_array(false, $checks, true);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function act(array $action, array $payload, int $depth, int $max): string
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            'create_task', 'noc_task' => $this->task($action, $payload),
            'notify' => $this->notify($action, $payload),
            'add_tag' => $this->tag($action, $payload),
            'send_whatsapp' => $this->whatsapp($payload),
            'dispatch' => $this->retrigger((string) ($action['trigger'] ?? ''), $payload, $depth, $max),
            default => 'ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function task(array $action, array $payload): string
    {
        $title = (string) ($action['title'] ?? ($action['type'] === 'noc_task' ? 'RADIUS task' : 'Follow up'));
        $customerId = $payload['customer_id'] ?? null;
        $exists = WorkTask::query()->where('title', $title)->where('customer_id', $customerId)->where('status', 'open')->exists();

        if ($exists) {
            return 'task_exists';
        }

        WorkTask::query()->create([
            'kind' => 'task',
            'title' => $title,
            'status' => 'open',
            'priority' => 'normal',
            'customer_id' => $customerId,
            'ticket_id' => $payload['ticket_id'] ?? null,
            'lead_id' => $payload['lead_id'] ?? null,
            'due_at' => now()->addHours((int) ($action['hours'] ?? $this->commercial->get('followup_hours'))),
        ]);

        return 'task';
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function notify(array $action, array $payload): string
    {
        $role = (string) ($action['role'] ?? 'cc_supervisor');
        $users = User::query()->where('role', $role)->get();

        if ($users->isEmpty()) {
            return 'nobody';
        }

        Notification::send($users, new WorkspaceNotification(
            (string) ($action['title'] ?? 'Automation'),
            (string) ($payload['label'] ?? $action['title'] ?? 'Automation'),
            isset($payload['ticket_id']) ? '/tickets/'.$payload['ticket_id'] : '/automations',
        ));

        return 'notified';
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $payload
     */
    private function tag(array $action, array $payload): string
    {
        $customer = Customer::query()->find($payload['customer_id'] ?? 0);

        if (! $customer instanceof Customer) {
            return 'no_customer';
        }

        $tags = $customer->tags ?? [];
        $tag = (string) ($action['tag'] ?? 'at-risk');

        if (! in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $customer->tags = $tags;
            $customer->save();
        }

        return 'tagged';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function whatsapp(array $payload): string
    {
        $customer = Customer::query()->find($payload['customer_id'] ?? 0);

        if (! $customer instanceof Customer || ! $customer->whatsapp_consent || ! $customer->whatsapp_e164) {
            return 'skipped_consent';
        }

        return 'https://wa.me/'.ltrim((string) $customer->whatsapp_e164, '+');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function retrigger(string $trigger, array $payload, int $depth, int $max): string
    {
        if ($trigger === '' || $depth + 1 >= $max) {
            $this->run(null, $trigger === '' ? 'dispatch' : $trigger, 'blocked', $payload, $depth + 1, 'loop');

            return 'blocked';
        }

        $this->emit($trigger, $payload, $depth + 1, true);

        return 'dispatched';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(string $trigger, Carbon $from): array
    {
        return match ($trigger) {
            'lead.sla_breach', 'lead.sla_warning', 'lead.created' => DB::table('leads')->where('created_at', '>=', $from)->orderByDesc('id')->limit(50)->get()->map(fn ($lead) => ['subject_type' => 'lead', 'subject_id' => $lead->id, 'lead_id' => $lead->id])->all(),
            'ticket.resolved', 'ticket.p1_unassigned', 'ticket.escalated' => DB::table('tickets')->where('created_at', '>=', $from)->orderByDesc('id')->limit(50)->get()->map(fn ($ticket) => ['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'ticket_id' => $ticket->id, 'customer_id' => $ticket->customer_id, 'priority' => $ticket->priority])->all(),
            'customer.health' => Customer::query()->where('created_at', '>=', $from)->orderByDesc('id')->limit(50)->get()->map(fn (Customer $customer) => ['subject_type' => 'customer', 'subject_id' => $customer->id, 'customer_id' => $customer->id])->all(),
            default => [],
        };
    }

    private function paused(): bool
    {
        return (bool) $this->commercial->get('automations_paused');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(?Automation $rule, string $trigger, string $status, array $payload, int $depth, string $message): void
    {
        AutomationRun::query()->create([
            'automation_id' => $rule?->id,
            'trigger' => $trigger,
            'status' => $status,
            'dry_run' => false,
            'subject_type' => isset($payload['subject_type']) ? (string) $payload['subject_type'] : null,
            'subject_id' => isset($payload['subject_id']) ? (int) $payload['subject_id'] : null,
            'depth' => $depth,
            'message' => $message,
        ]);
    }
}
