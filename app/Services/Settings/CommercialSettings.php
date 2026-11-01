<?php

namespace App\Services\Settings;

use App\Models\ApprovalPolicy;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogDesk;
use App\Services\Collections\CollectionsDesk;
use App\Services\Performance\CommissionDesk;
use App\Services\Retention\RetentionDesk;
use Illuminate\Http\Request;

class CommercialSettings
{
    public function __construct(
        private SettingsStore $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'vat_percent' => '11.00',
            'fallback_rate' => '89500.0000',
            'quote_valid_days' => 14,
            'term_months' => 12,
            'early_termination_fee' => '0.00',
            'install_business_days' => 5,
            'deposit_required' => false,
            'return_charge' => '0.00',
            'agent_line_percent' => '5.00',
            'agent_line_amount' => '5.00',
            'agent_total_percent' => '5.00',
            'agent_total_amount' => '10.00',
            'manager_line_percent' => '15.00',
            'manager_line_amount' => '25.00',
            'manager_total_percent' => '15.00',
            'manager_total_amount' => '50.00',
            'purchase_auto_amount' => '200.00',
            'credit_note_auto_amount' => '20.00',
            'refund_finance_limit' => '50.00',
            'refund_management_limit' => '500.00',
            'quote_discount_timeout_hours' => 4,
            'quote_comment_above' => '15.00',
            'plan_home_price' => '35.00',
            'plan_business_price' => '40.00',
            'install_fee' => '50.00',
            'prepaid' => true,
            'expiry_time' => '14:00',
            'grace_days' => 3,
            'proration' => 'daily',
            'hold_max_days' => 30,
            'cash_variance' => '5.00',
            'field_cash' => true,
            'desk_opening' => '0.00',
            'cash_unlock_minutes' => 15,
            'dunning_reminder_days' => -3,
            'dunning_expiry_days' => 0,
            'dunning_warning_days' => 3,
            'dunning_suspend_days' => 7,
            'dunning_terminate_days' => 30,
            'renewal_soon_days' => 7,
            'renewal_window_days' => 30,
            'reconnect_fee' => '0.00',
            'retention_free_months' => 1,
            'winback_days' => 75,
            'at_risk_below' => 50,
            'churn_reasons' => 'price,coverage,moved,competitor,financial,other',
            'retention_allow_free_month' => true,
            'retention_allow_discount' => true,
            'retention_allow_upgrade' => true,
            'qa_weekly_sample' => 5,
            'qa_critical_total' => '0.00',
            'qa_pass_percent' => 80,
            'commission_mrc_percent' => '10.00',
            'commission_install_amount' => '5.00',
            'commission_payment_percent' => '0.00',
            'commission_accelerator_above' => 100,
            'commission_accelerator_percent' => '15.00',
            'commission_clawback_days' => 90,
            'big_deal_amount' => '100.00',
            'tv_trigger' => 'won',
            'report_refresh_seconds' => 30,
            'report_link_hours' => 24,
            'report_async_rows' => 500,
            'report_export_per_hour' => 10,
            'digest_hour' => 8,
            'digest_weekday' => 1,
            'dashboard_default_days' => 30,
            'digest_roles' => 'cc_supervisor,sales_manager',
            'automations_paused' => false,
            'automations_loop_depth' => 3,
            'automations_dry_days' => 7,
            'automations_rate_per_hour' => 30,
            'p1_unassigned_minutes' => 10,
            'followup_hours' => 24,
            'webhook_max_attempts' => 5,
            'webhook_backoff_seconds' => 30,
            'webhook_replay_seconds' => 300,
            'api_key_days' => 90,
            'import_undo_hours' => 24,
            'backup_daily_keep' => 30,
            'backup_monthly_keep' => 12,
            'krypton_import' => false,
            'tech_idle_minutes' => 720,
            'sensitive_reauth_minutes' => 15,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings->get('commercial', $this->defaults());
    }

    public function get(string $key): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $this->defaults()[$key] ?? null;
    }

    public function money(string $key): string
    {
        return bcadd((string) $this->get($key), '0', 2);
    }

    public function rate(): string
    {
        return bcadd((string) $this->get('fallback_rate'), '0', 4);
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public function save(array $incoming, User $actor, Request $request): void
    {
        $before = $this->all();
        $next = $this->defaults();

        foreach ($next as $key => $default) {
            if (! array_key_exists($key, $incoming)) {
                $next[$key] = $before[$key] ?? $default;

                continue;
            }

            $next[$key] = is_bool($default)
                ? filter_var($incoming[$key], FILTER_VALIDATE_BOOLEAN)
                : $incoming[$key];
        }

        $next['tv_trigger'] = ($next['tv_trigger'] ?? '') === 'activated' ? 'activated' : 'won';
        $next['report_refresh_seconds'] = max(5, (int) $next['report_refresh_seconds']);
        $next['report_link_hours'] = max(1, (int) $next['report_link_hours']);
        $next['report_async_rows'] = max(1, (int) $next['report_async_rows']);
        $next['report_export_per_hour'] = max(1, (int) $next['report_export_per_hour']);
        $next['digest_hour'] = min(23, max(0, (int) $next['digest_hour']));
        $next['digest_weekday'] = min(7, max(1, (int) $next['digest_weekday']));
        $next['dashboard_default_days'] = max(1, (int) $next['dashboard_default_days']);
        $next['automations_loop_depth'] = max(1, (int) $next['automations_loop_depth']);
        $next['automations_dry_days'] = max(1, (int) $next['automations_dry_days']);
        $next['automations_rate_per_hour'] = max(1, (int) $next['automations_rate_per_hour']);
        $next['p1_unassigned_minutes'] = max(1, (int) $next['p1_unassigned_minutes']);
        $next['followup_hours'] = max(1, (int) $next['followup_hours']);
        $next['webhook_max_attempts'] = max(1, (int) $next['webhook_max_attempts']);
        $next['webhook_backoff_seconds'] = max(1, (int) $next['webhook_backoff_seconds']);
        $next['webhook_replay_seconds'] = max(30, (int) $next['webhook_replay_seconds']);
        $next['api_key_days'] = max(1, (int) $next['api_key_days']);
        $next['import_undo_hours'] = max(1, (int) $next['import_undo_hours']);
        $next['backup_daily_keep'] = max(1, (int) $next['backup_daily_keep']);
        $next['backup_monthly_keep'] = max(1, (int) $next['backup_monthly_keep']);
        $next['tech_idle_minutes'] = max(1, (int) $next['tech_idle_minutes']);
        $next['sensitive_reauth_minutes'] = max(1, (int) $next['sensitive_reauth_minutes']);
        $next['commission_clawback_days'] = (int) $next['commission_clawback_days'];
        $next['commission_accelerator_above'] = (int) $next['commission_accelerator_above'];
        $next['vat_percent'] = bcadd((string) $next['vat_percent'], '0', 2);
        $next['fallback_rate'] = bcadd((string) $next['fallback_rate'], '0', 4);
        foreach (['early_termination_fee', 'return_charge', 'agent_line_percent', 'agent_line_amount', 'agent_total_percent', 'agent_total_amount', 'manager_line_percent', 'manager_line_amount', 'manager_total_percent', 'manager_total_amount', 'purchase_auto_amount', 'credit_note_auto_amount', 'refund_finance_limit', 'refund_management_limit', 'quote_comment_above', 'plan_home_price', 'plan_business_price', 'install_fee', 'cash_variance', 'desk_opening', 'reconnect_fee', 'commission_mrc_percent', 'commission_install_amount', 'commission_payment_percent', 'commission_accelerator_percent', 'big_deal_amount'] as $money) {
            $next[$money] = bcadd((string) $next[$money], '0', 2);
        }

        $this->settings->put('commercial', $next, $actor, $request);
        $this->sync($before, $next, $actor, $request);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $next
     */
    private function sync(array $before, array $next, User $actor, Request $request): void
    {
        $catalog = app(CatalogDesk::class);

        if ($this->changed($before, $next, ['agent_line_percent', 'agent_line_amount', 'agent_total_percent', 'agent_total_amount'])) {
            $catalog->saveLimit([
                'role' => 'sales_agent',
                'line_percent' => $next['agent_line_percent'],
                'line_amount' => $next['agent_line_amount'],
                'total_percent' => $next['agent_total_percent'],
                'total_amount' => $next['agent_total_amount'],
            ], $actor, $request);
        }

        if ($this->changed($before, $next, ['manager_line_percent', 'manager_line_amount', 'manager_total_percent', 'manager_total_amount'])) {
            $catalog->saveLimit([
                'role' => 'sales_manager',
                'line_percent' => $next['manager_line_percent'],
                'line_amount' => $next['manager_line_amount'],
                'total_percent' => $next['manager_total_percent'],
                'total_amount' => $next['manager_total_amount'],
            ], $actor, $request);
        }

        $this->policyAmount('purchase_order', 'auto_amount', (string) $next['purchase_auto_amount'], $before, $next, 'purchase_auto_amount', $actor, $request);
        $this->policyAmount('credit_note', 'auto_amount', (string) $next['credit_note_auto_amount'], $before, $next, 'credit_note_auto_amount', $actor, $request);

        if ($this->changed($before, $next, ['quote_discount_timeout_hours', 'quote_comment_above'])) {
            $policy = ApprovalPolicy::query()->where('key', 'quote_discount')->first();

            if ($policy instanceof ApprovalPolicy) {
                $policy->timeout_hours = (int) $next['quote_discount_timeout_hours'];
                $policy->comment_above = $next['quote_comment_above'];
                $policy->save();
                $this->audit->record('commercial.policy', $actor, [
                    'key' => 'quote_discount',
                    'timeout_hours' => $policy->timeout_hours,
                    'comment_above' => (string) $policy->comment_above,
                ], $request);
            }
        }

        if ($this->changed($before, $next, ['refund_finance_limit', 'refund_management_limit'])) {
            foreach (['refund', 'payment_reversal'] as $key) {
                $this->syncRefundLadder($key, $next, $actor, $request);
            }
        }

        $this->publishPrice('SMN-8-16', (string) $next['plan_home_price'], null, $before, $next, 'plan_home_price', $actor, $request);
        $this->publishPrice('SMN-8-16', (string) $next['plan_business_price'], 'business', $before, $next, 'plan_business_price', $actor, $request);
        $this->publishPrice('INSTALL', (string) $next['install_fee'], null, $before, $next, 'install_fee', $actor, $request);
        app(CollectionsDesk::class)->syncPolicy();
        app(RetentionDesk::class)->syncPolicy();

        if ($this->changed($before, $next, ['commission_mrc_percent', 'commission_install_amount', 'commission_payment_percent', 'commission_accelerator_above', 'commission_accelerator_percent', 'commission_clawback_days'])) {
            app(CommissionDesk::class)->revisePlan($next, $actor);
        }
    }

    /**
     * @param  array<string, mixed>  $next
     */
    private function syncRefundLadder(string $key, array $next, User $actor, Request $request): void
    {
        $policy = ApprovalPolicy::query()->where('key', $key)->first();

        if (! $policy instanceof ApprovalPolicy) {
            return;
        }

        $steps = $policy->steps ?? [];

        foreach ($steps as $index => $step) {
            $roles = $step['roles'] ?? [];

            if (in_array('finance', $roles, true) && isset($step['when']['amount_up_to'])) {
                $steps[$index]['when']['amount_up_to'] = $next['refund_finance_limit'];
            }

            if (in_array('management', $roles, true)) {
                $steps[$index]['when']['amount_above'] = $next['refund_finance_limit'];
                $steps[$index]['when']['amount_up_to'] = $next['refund_management_limit'];
            }
        }

        $policy->steps = $steps;
        $policy->save();
        $this->audit->record('commercial.policy', $actor, ['key' => $key, 'steps' => $steps], $request);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $next
     * @param  list<string>  $keys
     */
    private function changed(array $before, array $next, array $keys): bool
    {
        foreach ($keys as $key) {
            if ((string) ($before[$key] ?? '') !== (string) ($next[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $next
     */
    private function policyAmount(string $policyKey, string $condition, string $amount, array $before, array $next, string $field, User $actor, Request $request): void
    {
        if (! $this->changed($before, $next, [$field])) {
            return;
        }

        $policy = ApprovalPolicy::query()->where('key', $policyKey)->first();

        if (! $policy instanceof ApprovalPolicy) {
            return;
        }

        $conditions = $policy->conditions ?? [];
        $conditions[$condition] = $amount;
        $policy->conditions = $conditions;
        $policy->save();
        $this->audit->record('commercial.policy', $actor, ['key' => $policyKey, $condition => $amount], $request);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $next
     */
    private function publishPrice(string $sku, string $price, ?string $customerType, array $before, array $next, string $field, User $actor, Request $request): void
    {
        if (! $this->changed($before, $next, [$field])) {
            return;
        }

        $product = Product::query()->where('sku', $sku)->first();

        if (! $product instanceof Product) {
            return;
        }

        app(CatalogDesk::class)->addPrice([
            'product_id' => $product->id,
            'price' => $price,
            'currency' => 'USD',
            'valid_from' => now()->toDateString(),
            'customer_type' => $customerType,
            'note' => 'Set in Settings. To confirm until Sam says otherwise.',
        ], $actor, $request);
    }
}
