<?php

namespace App\Services\Reports;

use App\Models\DashboardLayout;
use App\Models\User;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportDesk
{
    /** @var array<string, array{area: string, href: string}> */
    public const REPORTS = [
        'leads_by_source' => ['area' => 'sales', 'href' => '/leads'],
        'lead_sla' => ['area' => 'sales', 'href' => '/leads'],
        'funnel' => ['area' => 'sales', 'href' => '/opportunities'],
        'win_rate' => ['area' => 'sales', 'href' => '/opportunities'],
        'pipeline_value' => ['area' => 'sales', 'href' => '/opportunities'],
        'sales_cycle' => ['area' => 'sales', 'href' => '/opportunities'],
        'loss_reasons' => ['area' => 'sales', 'href' => '/opportunities'],
        'leaderboard' => ['area' => 'sales', 'href' => '/opportunities'],
        'quote_turnaround' => ['area' => 'sales', 'href' => '/quotes'],
        'discounts' => ['area' => 'sales', 'href' => '/quotes'],
        'campaign_roi' => ['area' => 'sales', 'href' => '/leads'],
        'referrals' => ['area' => 'sales', 'href' => '/leads'],
        'orders_backlog' => ['area' => 'operations', 'href' => '/orders'],
        'install_lead_time' => ['area' => 'operations', 'href' => '/orders'],
        'first_time_right' => ['area' => 'operations', 'href' => '/dispatch'],
        'failed_jobs' => ['area' => 'operations', 'href' => '/dispatch'],
        'tech_productivity' => ['area' => 'operations', 'href' => '/dispatch'],
        'materials_per_install' => ['area' => 'operations', 'href' => '/inventory'],
        'survey_rate' => ['area' => 'operations', 'href' => '/coverage'],
        'waitlist' => ['area' => 'operations', 'href' => '/leads'],
        'tickets_open' => ['area' => 'support', 'href' => '/tickets'],
        'sla_met' => ['area' => 'support', 'href' => '/tickets'],
        'mtta' => ['area' => 'support', 'href' => '/tickets'],
        'mttr' => ['area' => 'support', 'href' => '/tickets'],
        'reopen' => ['area' => 'support', 'href' => '/tickets'],
        'fcr' => ['area' => 'support', 'href' => '/tickets'],
        'escalation_rate' => ['area' => 'support', 'href' => '/tickets'],
        'incidents' => ['area' => 'support', 'href' => '/incidents'],
        'repeat_customers' => ['area' => 'support', 'href' => '/customers'],
        'csat' => ['area' => 'support', 'href' => '/tickets'],
        'calls' => ['area' => 'calls', 'href' => '/calls'],
        'aht' => ['area' => 'calls', 'href' => '/calls'],
        'dispositions' => ['area' => 'calls', 'href' => '/calls'],
        'callbacks' => ['area' => 'calls', 'href' => '/calls'],
        'agent_status' => ['area' => 'calls', 'href' => '/qa'],
        'qa_scores' => ['area' => 'calls', 'href' => '/qa'],
        'call_conversion' => ['area' => 'calls', 'href' => '/leads'],
        'mrc' => ['area' => 'finance', 'href' => '/billing'],
        'invoiced' => ['area' => 'finance', 'href' => '/billing'],
        'collected' => ['area' => 'finance', 'href' => '/billing'],
        'aging' => ['area' => 'finance', 'href' => '/billing'],
        'dso' => ['area' => 'finance', 'href' => '/billing'],
        'collections_by_method' => ['area' => 'finance', 'href' => '/billing'],
        'cash_variance' => ['area' => 'finance', 'href' => '/billing'],
        'refunds' => ['area' => 'finance', 'href' => '/billing'],
        'lbp_collections' => ['area' => 'finance', 'href' => '/billing'],
        'renewals' => ['area' => 'finance', 'href' => '/billing'],
        'suspended_unpaid' => ['area' => 'finance', 'href' => '/collections'],
        'active_base' => ['area' => 'customers', 'href' => '/customers'],
        'net_adds' => ['area' => 'customers', 'href' => '/customers'],
        'churn' => ['area' => 'customers', 'href' => '/customers'],
        'tenure' => ['area' => 'customers', 'href' => '/customers'],
        'health' => ['area' => 'customers', 'href' => '/customers'],
        'stock_on_hand' => ['area' => 'inventory', 'href' => '/inventory'],
        'van_stock' => ['area' => 'inventory', 'href' => '/inventory'],
        'movements' => ['area' => 'inventory', 'href' => '/inventory'],
        'variances' => ['area' => 'inventory', 'href' => '/inventory'],
        'rma' => ['area' => 'inventory', 'href' => '/inventory'],
        'serials' => ['area' => 'inventory', 'href' => '/inventory'],
        'open_pos' => ['area' => 'inventory', 'href' => '/orders'],
        'logins' => ['area' => 'audit', 'href' => '/audit'],
        'failed_logins' => ['area' => 'audit', 'href' => '/audit'],
        'permission_changes' => ['area' => 'audit', 'href' => '/audit'],
        'exports' => ['area' => 'audit', 'href' => '/reports'],
        'approvals_by_approver' => ['area' => 'audit', 'href' => '/approvals'],
    ];

    /** @var array<string, list<string>> */
    private const HOMES = [
        'management' => ['leads_by_source', 'win_rate', 'tickets_open', 'sla_met', 'calls', 'mrc', 'active_base', 'stock_on_hand'],
        'sales' => ['leads_by_source', 'win_rate', 'pipeline_value', 'leaderboard', 'discounts', 'referrals'],
        'supervisor' => ['tickets_open', 'sla_met', 'calls', 'aht', 'qa_scores', 'callbacks'],
        'agent' => ['calls', 'aht', 'tickets_open', 'qa_scores'],
        'operations' => ['orders_backlog', 'install_lead_time', 'failed_jobs', 'tech_productivity', 'survey_rate'],
        'noc' => ['tickets_open', 'sla_met', 'incidents', 'mtta', 'escalation_rate'],
        'finance' => ['mrc', 'invoiced', 'collected', 'aging', 'renewals', 'suspended_unpaid'],
        'warehouse' => ['stock_on_hand', 'van_stock', 'movements', 'open_pos'],
    ];

    public function __construct(
        private AccessGate $access,
        private CommercialSettings $commercial,
    ) {}

    public function home(User $user): string
    {
        return match ($user->role) {
            'sales_manager', 'sales_agent' => 'sales',
            'cc_supervisor' => 'supervisor',
            'cc_agent', 'support_agent' => 'agent',
            'ops_manager', 'field_technician', 'installer' => 'operations',
            'noc_engineer' => 'noc',
            'finance' => 'finance',
            'warehouse' => 'warehouse',
            default => 'management',
        };
    }

    public function allows(User $user, string $key): bool
    {
        $report = self::REPORTS[$key] ?? null;

        if ($report === null || $this->access->scopeFor($user, 'reports.view') === null) {
            return false;
        }

        if (in_array($user->role, ['super_admin', 'management', 'auditor'], true)) {
            return true;
        }

        $areas = match ($user->role) {
            'finance' => ['finance', 'customers'],
            'sales_manager', 'sales_agent' => ['sales'],
            'cc_supervisor' => ['calls', 'support'],
            'cc_agent' => ['calls'],
            'support_agent' => ['support'],
            'ops_manager', 'field_technician', 'installer' => ['operations'],
            'noc_engineer' => ['support'],
            'warehouse' => ['inventory'],
            default => [],
        };

        return in_array($report['area'], $areas, true);
    }

    /**
     * @return list<string>
     */
    public function allowedKeys(User $user): array
    {
        return array_values(array_filter(array_keys(self::REPORTS), fn (string $key) => $this->allows($user, $key)));
    }

    /**
     * @return list<array{key: string, value: string, href: string}>
     */
    public function tiles(User $user, string $from, string $to): array
    {
        $saved = DashboardLayout::query()->where('user_id', $user->id)->value('keys');
        $keys = is_array($saved) && $saved !== []
            ? array_values(array_filter($saved, fn ($key) => is_string($key) && $this->allows($user, $key)))
            : array_values(array_filter(self::HOMES[$this->home($user)] ?? [], fn (string $key) => $this->allows($user, $key)));

        return array_map(fn (string $key) => [
            'key' => $key,
            'value' => $this->value($key, $user, $from, $to),
            'href' => '/reports/'.$key.'?from='.$from.'&to='.$to,
        ], $keys);
    }

    /**
     * @param  list<string>  $keys
     */
    public function saveLayout(User $user, array $keys): void
    {
        $keys = array_values(array_filter($keys, fn (string $key) => $this->allows($user, $key)));
        DashboardLayout::query()->updateOrCreate(['user_id' => $user->id], ['keys' => $keys]);
    }

    public function value(string $key, User $user, string $from, string $to): string
    {
        $ids = $this->ids($user);

        return match ($key) {
            'leads_by_source' => $this->count($this->period(DB::table('leads'), 'created_at', $from, $to), $ids, 'owner_id'),
            'lead_sla' => $this->count($this->period(DB::table('leads')->whereNotNull('sla_breached_at'), 'created_at', $from, $to), $ids, 'owner_id'),
            'funnel' => $this->count($this->period(DB::table('opportunities'), 'created_at', $from, $to), $ids, 'owner_id'),
            'win_rate' => $this->rate(
                (int) $this->count($this->period(DB::table('opportunities')->whereNotNull('won_at'), 'won_at', $from, $to), $ids, 'owner_id'),
                (int) $this->count($this->period(DB::table('opportunities')->whereNotNull('won_at'), 'won_at', $from, $to), $ids, 'owner_id')
                    + (int) $this->count($this->period(DB::table('opportunities')->whereNotNull('lost_at'), 'lost_at', $from, $to), $ids, 'owner_id'),
            ),
            'pipeline_value' => $this->money($this->sum($this->period(DB::table('opportunities')->whereNull('won_at')->whereNull('lost_at'), 'created_at', $from, $to), 'mrc', $ids, 'owner_id')),
            'sales_cycle' => $this->span($this->own($this->period(DB::table('opportunities')->whereNotNull('won_at'), 'won_at', $from, $to), $ids, 'owner_id'), 'created_at', 'won_at', 86400),
            'loss_reasons' => $this->count($this->period(DB::table('opportunities')->whereNotNull('lost_at'), 'lost_at', $from, $to), $ids, 'owner_id'),
            'leaderboard' => $this->count($this->period(DB::table('opportunities')->whereNotNull('won_at'), 'won_at', $from, $to), $ids, 'owner_id'),
            'quote_turnaround' => $this->count($this->period(DB::table('quotes'), 'created_at', $from, $to), $ids, 'owner_id'),
            'discounts' => $this->money($this->sum($this->period(DB::table('quotes'), 'created_at', $from, $to), 'discount_total', $ids, 'owner_id')),
            'campaign_roi' => $this->count($this->period(DB::table('leads')->whereNotNull('campaign_id'), 'created_at', $from, $to), $ids, 'owner_id'),
            'referrals' => $this->count($this->period(DB::table('referral_credits'), 'created_at', $from, $to), null, 'id'),
            'orders_backlog' => $this->count($this->period(DB::table('orders')->whereNotIn('status', ['completed', 'cancelled']), 'created_at', $from, $to), $ids, 'owner_id'),
            'install_lead_time' => $this->span($this->own($this->period(DB::table('orders')->where('status', 'activated'), 'created_at', $from, $to), $ids, 'owner_id'), 'created_at', 'updated_at', 86400),
            'first_time_right' => $this->count($this->period(DB::table('field_jobs')->where('status', 'done')->whereNull('failure_reason'), 'created_at', $from, $to), $ids, 'technician_id'),
            'failed_jobs' => $this->count($this->period(DB::table('field_jobs')->where('status', 'failed'), 'created_at', $from, $to), $ids, 'technician_id'),
            'tech_productivity' => $this->count($this->period(DB::table('field_jobs'), 'created_at', $from, $to), $ids, 'technician_id'),
            'materials_per_install' => $this->count($this->period(DB::table('stock_movements'), 'created_at', $from, $to), $ids, 'actor_id'),
            'survey_rate' => $this->count($this->period(DB::table('survey_results')->where('feasible', true), 'created_at', $from, $to), null, 'id'),
            'waitlist' => $this->count($this->period(DB::table('leads')->where('status', 'waitlisted'), 'created_at', $from, $to), $ids, 'owner_id'),
            'tickets_open' => $this->count($this->period(DB::table('tickets')->whereNotIn('status', ['resolved', 'closed']), 'created_at', $from, $to), $ids, 'owner_id'),
            'sla_met' => $this->rate(
                (int) $this->count($this->period(DB::table('tickets')->whereIn('status', ['resolved', 'closed'])->where('resolve_breached', false), 'created_at', $from, $to), $ids, 'owner_id'),
                (int) $this->count($this->period(DB::table('tickets')->whereIn('status', ['resolved', 'closed']), 'created_at', $from, $to), $ids, 'owner_id'),
            ),
            'mtta' => $this->span($this->own($this->period(DB::table('tickets')->whereNotNull('first_response_at'), 'created_at', $from, $to), $ids, 'owner_id'), 'created_at', 'first_response_at', 60),
            'mttr' => $this->span($this->own($this->period(DB::table('tickets')->whereIn('status', ['resolved', 'closed']), 'created_at', $from, $to), $ids, 'owner_id'), 'created_at', 'updated_at', 60),
            'reopen' => $this->count($this->period(DB::table('tickets')->where('status', 'reopened'), 'created_at', $from, $to), $ids, 'owner_id'),
            'fcr' => $this->rate(
                (int) $this->count($this->period(DB::table('tickets')->whereIn('status', ['resolved', 'closed'])->whereNull('escalation_reason')->whereNull('escalation_tier_id'), 'created_at', $from, $to), $ids, 'owner_id'),
                (int) $this->count($this->period(DB::table('tickets'), 'created_at', $from, $to), $ids, 'owner_id'),
            ),
            'escalation_rate' => $this->rate(
                (int) $this->count($this->period(DB::table('ticket_escalations'), 'created_at', $from, $to), null, 'id'),
                (int) $this->count($this->period(DB::table('tickets'), 'created_at', $from, $to), $ids, 'owner_id'),
            ),
            'incidents' => $this->count($this->period(DB::table('incidents'), 'created_at', $from, $to), null, 'id'),
            'repeat_customers' => $this->repeats($from, $to, $ids),
            'csat' => $this->avg($this->own($this->period(DB::table('csat_responses')->whereNotNull('score'), 'created_at', $from, $to), $ids === null ? null : $this->ticketOwners($ids), 'ticket_id'), 'score'),
            'calls' => $this->count($this->period(DB::table('interactions'), 'created_at', $from, $to), $ids, 'user_id'),
            'aht' => $this->avgInt($this->own($this->period(DB::table('interactions'), 'created_at', $from, $to), $ids, 'user_id'), 'duration_seconds'),
            'dispositions' => $this->count($this->period(DB::table('interactions')->whereNotNull('wrap_up_code_id'), 'created_at', $from, $to), $ids, 'user_id'),
            'callbacks' => $this->count($this->period(DB::table('interactions')->whereNotNull('callback_at'), 'created_at', $from, $to), $ids, 'user_id'),
            'agent_status' => $this->count($this->period(DB::table('agent_status_log'), 'started_at', $from, $to), $ids, 'user_id'),
            'qa_scores' => $this->avg($this->own($this->period(DB::table('qa_evaluations'), 'created_at', $from, $to), $ids, 'agent_id'), 'total_pct'),
            'call_conversion' => $this->rate(
                (int) $this->count($this->period(DB::table('leads')->where('status', 'qualified'), 'created_at', $from, $to), $ids, 'owner_id'),
                (int) $this->count($this->period(DB::table('leads'), 'created_at', $from, $to), $ids, 'owner_id'),
            ),
            'mrc' => $this->money($this->sum($this->services($from, $to, $ids)->where('status', 'active'), 'monthly_usd', null, 'id')),
            'invoiced' => $this->money($this->sum($this->invoices($from, $to, $ids)->where('type', 'invoice')->where('status', '!=', 'voided'), 'total', null, 'id')),
            'collected' => $this->money($this->sum($this->period(DB::table('payments')->where('status', 'confirmed'), 'received_at', $from, $to), 'amount', $ids, 'received_by')),
            'aging' => $this->count($this->invoices($from, $to, $ids)->where('type', 'invoice')->whereNotIn('status', ['paid', 'voided', 'draft']), null, 'id'),
            'dso' => $this->dso($user, $from, $to),
            'collections_by_method' => $this->count($this->period(DB::table('payments')->where('status', 'confirmed'), 'received_at', $from, $to), $ids, 'received_by'),
            'cash_variance' => $this->money($this->sum($this->period(DB::table('cash_sessions')->whereNotNull('variance'), 'created_at', $from, $to), 'variance', $ids, 'user_id')),
            'refunds' => $this->count($this->period(DB::table('payments')->where('status', 'reversed'), 'created_at', $from, $to), $ids, 'received_by'),
            'lbp_collections' => $this->money($this->sum($this->period(DB::table('payments')->where('status', 'confirmed')->where('currency', 'LBP'), 'received_at', $from, $to), 'amount', $ids, 'received_by')),
            'renewals' => $this->count($this->period(DB::table('subscriptions'), 'next_invoice_at', $from, $to), null, 'id'),
            'suspended_unpaid' => $this->count($this->services($from, $to, $ids)->where('status', 'suspended'), null, 'id'),
            'active_base' => $this->count($this->period(DB::table('customers')->where('status', 'active'), 'created_at', $from, $to), $ids, 'owner_id'),
            'net_adds' => $this->count($this->period(DB::table('customers'), 'created_at', $from, $to), $ids, 'owner_id'),
            'churn' => $this->count($this->period($this->services($from, $to, $ids)->where('status', 'terminated'), 'terminated_at', $from, $to), null, 'id'),
            'tenure' => $this->count($this->services($from, $to, $ids)->where('status', 'active'), null, 'id'),
            'health' => $this->count(DB::table('customers')->where('status', 'active'), $ids, 'owner_id'),
            'stock_on_hand' => $this->count($this->period(DB::table('stock_items'), 'created_at', $from, $to), null, 'id'),
            'van_stock' => $this->count($this->period(DB::table('stock_locations')->where('kind', 'van'), 'created_at', $from, $to), $ids, 'user_id'),
            'movements' => $this->count($this->period(DB::table('stock_movements'), 'created_at', $from, $to), $ids, 'actor_id'),
            'variances' => $this->count($this->period(DB::table('stock_counts'), 'created_at', $from, $to), $ids, 'user_id'),
            'rma' => $this->count($this->period(DB::table('stock_movements')->where('kind', 'return'), 'created_at', $from, $to), $ids, 'actor_id'),
            'serials' => $this->count($this->period(DB::table('stock_serials')->whereNotNull('customer_service_id'), 'created_at', $from, $to), null, 'id'),
            'open_pos' => $this->count($this->period(DB::table('purchase_orders')->whereNotIn('status', ['received', 'cancelled']), 'created_at', $from, $to), $ids, 'owner_id'),
            'logins' => $this->count($this->period(DB::table('audit_log')->where('event', 'auth.login.succeeded'), 'occurred_at', $from, $to), $ids, 'actor_id'),
            'failed_logins' => $this->count($this->period(DB::table('audit_log')->where('event', 'auth.login.failed'), 'occurred_at', $from, $to), $ids, 'actor_id'),
            'permission_changes' => $this->count($this->period(DB::table('audit_log')->where('event', 'like', '%role%'), 'occurred_at', $from, $to), $ids, 'actor_id'),
            'exports' => $this->count($this->period(DB::table('audit_log')->where('event', 'report.exported'), 'occurred_at', $from, $to), $ids, 'actor_id'),
            'approvals_by_approver' => $this->count($this->period(DB::table('approval_decisions'), 'decided_at', $from, $to), $ids, 'approver_id'),
            default => '0',
        };
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    public function rows(string $key, User $user, string $from, string $to): array
    {
        $report = self::REPORTS[$key];
        $ids = $this->ids($user);
        $table = match ($report['area']) {
            'sales' => str_contains($key, 'quote') || $key === 'discounts' ? 'quotes' : (str_contains($key, 'lead') || $key === 'campaign_roi' || $key === 'waitlist' ? 'leads' : 'opportunities'),
            'operations' => in_array($key, ['orders_backlog', 'install_lead_time', 'open_pos'], true) ? 'orders' : 'field_jobs',
            'support' => $key === 'incidents' ? 'incidents' : 'tickets',
            'calls' => in_array($key, ['qa_scores', 'agent_status'], true) ? 'qa_evaluations' : 'interactions',
            'finance', 'customers' => 'customers',
            'inventory' => 'stock_items',
            default => 'audit_log',
        };

        if ($key === 'referrals') {
            $table = 'referral_credits';
        }

        $href = $report['href'];
        $query = DB::table($table);
        $owner = match ($table) {
            'leads', 'opportunities', 'orders', 'quotes', 'customers', 'purchase_orders' => 'owner_id',
            'tickets' => 'owner_id',
            'field_jobs' => 'technician_id',
            'interactions' => 'user_id',
            'qa_evaluations' => 'agent_id',
            'audit_log' => 'actor_id',
            default => null,
        };

        if ($owner !== null) {
            $query = $this->own($query, $ids, $owner);
        }

        return $query->orderByDesc('id')->limit(12)->get()->map(function ($row) use ($href, $table) {
            $id = (int) $row->id;
            $link = match ($table) {
                'tickets' => '/tickets/'.$id,
                'orders' => '/orders/'.$id,
                default => $href,
            };

            return ['label' => (string) ($row->number ?? $row->name_en ?? $row->name ?? $row->event ?? '#'.$id), 'href' => $link];
        })->all();
    }

    public function defaultFrom(): string
    {
        return now()->subDays(max(1, (int) $this->commercial->get('dashboard_default_days')))->toDateString();
    }

    public function defaultTo(): string
    {
        return now()->toDateString();
    }

    /**
     * Owner ids the user may report on: null means everyone, an empty list means no one.
     *
     * @return list<int>|null
     */
    public function ownerIds(User $user): ?array
    {
        return $this->ids($user);
    }

    /**
     * @return list<int>|null
     */
    private function ids(User $user): ?array
    {
        $scope = $this->access->scopeFor($user, 'reports.view');

        if ($scope === null) {
            return [];
        }

        if ($scope === 'all') {
            return null;
        }

        if ($scope === 'own') {
            return [$user->id];
        }

        $teamIds = $user->teams()->pluck('teams.id');

        if ($teamIds->isEmpty()) {
            return [$user->id];
        }

        return User::query()->where(function ($query) use ($user, $teamIds) {
            $query->whereKey($user->id)->orWhereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds));
        })->pluck('id')->all();
    }

    private function period(Builder $query, string $column, string $from, string $to): Builder
    {
        return $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function own(Builder $query, ?array $ids, string $column): Builder
    {
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function count(Builder $query, ?array $ids, string $column): string
    {
        return (string) $this->own($query, $ids, $column)->count();
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function sum(Builder $query, string $column, ?array $ids, string $owner): mixed
    {
        return $this->own($query, $ids, $owner)->sum($column);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: '0'), '0', 2);
    }

    private function rate(int $part, int $whole): string
    {
        if ($whole < 1) {
            return '0.00';
        }

        return number_format(($part / $whole) * 100, 2, '.', '');
    }

    private function span(Builder $query, string $start, string $end, int $divisor): string
    {
        $rows = $query->get([$start, $end]);

        if ($rows->isEmpty()) {
            return '0';
        }

        $total = 0.0;

        foreach ($rows as $row) {
            $left = strtotime((string) $row->{$start}) ?: 0;
            $right = strtotime((string) $row->{$end}) ?: 0;
            $total += ($right - $left) / $divisor;
        }

        return (string) (int) round($total / $rows->count());
    }

    private function avg(Builder $query, string $column): string
    {
        $value = $query->avg($column);

        return $value === null ? '0.00' : number_format((float) $value, 2, '.', '');
    }

    private function avgInt(Builder $query, string $column): string
    {
        $value = $query->avg($column);

        return (string) (int) round((float) ($value ?? 0));
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function repeats(string $from, string $to, ?array $ids): string
    {
        $query = DB::table('tickets')->select('customer_id')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $query = $this->own($query, $ids, 'owner_id');

        return (string) DB::query()->fromSub($query->groupBy('customer_id')->havingRaw('count(*) >= 2'), 'repeats')->count();
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function services(string $from, string $to, ?array $ids): Builder
    {
        $query = $this->period(DB::table('customer_services'), 'created_at', $from, $to);

        if ($ids === null) {
            return $query;
        }

        $customers = DB::table('customers')->whereIn('owner_id', $ids === [] ? [-1] : $ids)->pluck('id');

        return $query->whereIn('customer_id', $customers === [] ? [-1] : $customers);
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function invoices(string $from, string $to, ?array $ids): Builder
    {
        $query = $this->period(DB::table('invoices'), 'created_at', $from, $to);

        if ($ids === null) {
            return $query;
        }

        $customers = DB::table('customers')->whereIn('owner_id', $ids === [] ? [-1] : $ids)->pluck('id');

        return $query->whereIn('customer_id', $customers === [] ? [-1] : $customers);
    }

    private function dso(User $user, string $from, string $to): string
    {
        $invoiced = (float) $this->value('invoiced', $user, $from, $to);
        $collected = (float) $this->value('collected', $user, $from, $to);

        if ($invoiced <= 0) {
            return '0';
        }

        return (string) (int) round((($invoiced - $collected) / $invoiced) * 30);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ticketOwners(array $ids): array
    {
        return DB::table('tickets')->whereIn('owner_id', $ids)->pluck('id')->all();
    }
}
