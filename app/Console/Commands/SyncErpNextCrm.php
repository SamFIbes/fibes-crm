<?php

namespace App\Console\Commands;

use App\Services\Integrations\ErpNext\ErpNextCrmSync;
use App\Services\Integrations\ErpNext\ErpNextMoneySync;
use Illuminate\Console\Command;
use Throwable;

class SyncErpNextCrm extends Command
{
    protected $signature = 'erpnext:crm-sync
        {--dry-run : Report only (default)}
        {--fixture= : Directory of synthetic JSON fixtures}
        {--apply : Write (requires FIBES_ERPNEXT_CRM_APPLY=true)}
        {--since= : Override modified cursor (YYYY-mm-dd HH:ii:ss)}
        {--live-smoke : Read-only Hub smoke check (counts only)}
        {--money-only : Run only Sales Invoice / Payment Entry sync}
        {--customers-only : Run only Customer sync}';

    protected $description = 'Preview or apply ERPNext CRM Customer and money synchronization';

    public function handle(): int
    {
        if ($this->option('live-smoke')) {
            try {
                $crm = (new ErpNextCrmSync)->liveSmoke();
                $money = (new ErpNextMoneySync)->liveSmoke();
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                'HTTP OK | customer_count=%d | page_rows=%d | invoice_count=%d | invoice_page_rows=%d | payment_count=%d | payment_page_rows=%d',
                $crm['customer_count'],
                $crm['page_rows'],
                $money['invoice_count'],
                $money['invoice_page_rows'],
                $money['payment_count'],
                $money['payment_page_rows'],
            ));

            return self::SUCCESS;
        }

        if ($this->option('apply') && ! (bool) config('fibes.erpnext.crm_apply', false)) {
            $this->error('Apply refused: set FIBES_ERPNEXT_CRM_APPLY=true to enable writes.');

            return self::FAILURE;
        }

        $fixture = $this->option('fixture') ?: null;
        $since = $this->option('since') ?: null;
        $runCustomers = ! $this->option('money-only');
        $runMoney = ! $this->option('customers-only');

        if (is_string($fixture) && $fixture !== '') {
            try {
                $summary = (new ErpNextCrmSync($fixture, is_string($since) ? $since : null))->dryRun();
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($this->option('apply')) {
                $this->warn('Apply mode is guarded for fixtures; fixture path remains dry-run only. No records were written.');
            } else {
                $this->info('ERPNext CRM synchronization dry run. No records were written.');
            }

            $rows = [];

            foreach (['customers', 'leads', 'quotations', 'sales_invoices'] as $type) {
                $rows[] = [
                    $type,
                    $summary[$type]['would_create'],
                    $summary[$type]['would_update'],
                    $summary[$type]['unchanged'],
                    0,
                    0,
                    count($summary[$type]['samples']),
                ];
            }

            $this->table(
                ['Type', 'Would create', 'Would update', 'Unchanged', 'Ambiguous', 'Errors', 'Samples'],
                $rows,
            );

            return self::SUCCESS;
        }

        try {
            if ($runCustomers) {
                $crm = new ErpNextCrmSync(null, is_string($since) ? $since : null);
                $crmSummary = $this->option('apply') ? $crm->liveApply() : $crm->liveDryRun();
                $this->table(
                    ['Type', 'Would create', 'Would update', 'Unchanged', 'Ambiguous', 'Errors', 'Contacts', 'Addresses', 'Scanned'],
                    [[
                        'customers',
                        $crmSummary['customers']['would_create'],
                        $crmSummary['customers']['would_update'],
                        $crmSummary['customers']['unchanged'],
                        $crmSummary['customers']['ambiguous'],
                        $crmSummary['customers']['errors'],
                        $crmSummary['customers']['contacts'],
                        $crmSummary['customers']['addresses'],
                        $crmSummary['scanned'],
                    ]],
                );
            }

            if ($runMoney) {
                $money = new ErpNextMoneySync(is_string($since) ? $since : null);
                $moneySummary = $this->option('apply') ? $money->liveApply() : $money->liveDryRun();
                $this->table(
                    ['Type', 'Would create', 'Would update', 'Unchanged', 'Unlinked', 'Errors', 'Scanned', 'Aggregate sum'],
                    [
                        [
                            'sales_invoices',
                            $moneySummary['sales_invoices']['would_create'],
                            $moneySummary['sales_invoices']['would_update'],
                            $moneySummary['sales_invoices']['unchanged'],
                            $moneySummary['sales_invoices']['unlinked'],
                            $moneySummary['sales_invoices']['errors'],
                            $moneySummary['sales_invoices']['scanned'],
                            $moneySummary['sales_invoices']['grand_total_sum'],
                        ],
                        [
                            'payment_entries',
                            $moneySummary['payment_entries']['would_create'],
                            $moneySummary['payment_entries']['would_update'],
                            $moneySummary['payment_entries']['unchanged'],
                            $moneySummary['payment_entries']['unlinked'],
                            $moneySummary['payment_entries']['errors'],
                            $moneySummary['payment_entries']['scanned'],
                            $moneySummary['payment_entries']['paid_amount_sum'],
                        ],
                    ],
                );
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $this->info('ERPNext CRM live apply finished.');
        } else {
            $this->info('ERPNext CRM live dry run. No records were written.');
        }

        return self::SUCCESS;
    }
}
