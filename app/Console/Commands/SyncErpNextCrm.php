<?php

namespace App\Console\Commands;

use App\Services\Integrations\ErpNext\ErpNextCrmSync;
use Illuminate\Console\Command;
use Throwable;

class SyncErpNextCrm extends Command
{
    protected $signature = 'erpnext:crm-sync
        {--dry-run : Report only (default)}
        {--fixture= : Directory of synthetic JSON fixtures}
        {--apply : Write (requires FIBES_ERPNEXT_CRM_APPLY=true)}
        {--since= : Override Customer modified cursor (YYYY-mm-dd HH:ii:ss)}
        {--live-smoke : Read-only Hub smoke check (counts only)}';

    protected $description = 'Preview or apply ERPNext CRM Customer synchronization';

    public function handle(): int
    {
        if ($this->option('live-smoke')) {
            try {
                $smoke = (new ErpNextCrmSync)->liveSmoke();
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                'HTTP OK | customer_count=%d | page_rows=%d',
                $smoke['customer_count'],
                $smoke['page_rows'],
            ));

            return self::SUCCESS;
        }

        if ($this->option('apply') && ! (bool) config('fibes.erpnext.crm_apply', false)) {
            $this->error('Apply refused: set FIBES_ERPNEXT_CRM_APPLY=true to enable writes.');

            return self::FAILURE;
        }

        $fixture = $this->option('fixture') ?: null;
        $since = $this->option('since') ?: null;
        $sync = new ErpNextCrmSync(is_string($fixture) ? $fixture : null, is_string($since) ? $since : null);

        try {
            if (is_string($fixture) && $fixture !== '') {
                if ($this->option('apply')) {
                    $this->warn('Apply mode is guarded for fixtures; fixture path remains dry-run only. No records were written.');
                } else {
                    $this->info('ERPNext CRM synchronization dry run. No records were written.');
                }

                $summary = $sync->dryRun();
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

            $summary = $this->option('apply') ? $sync->liveApply() : $sync->liveDryRun();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $this->info('ERPNext CRM live apply finished.');
        } else {
            $this->info('ERPNext CRM live dry run. No records were written.');
        }

        $this->table(
            ['Type', 'Would create', 'Would update', 'Unchanged', 'Ambiguous', 'Errors', 'Contacts', 'Addresses', 'Scanned'],
            [[
                'customers',
                $summary['customers']['would_create'],
                $summary['customers']['would_update'],
                $summary['customers']['unchanged'],
                $summary['customers']['ambiguous'],
                $summary['customers']['errors'],
                $summary['customers']['contacts'],
                $summary['customers']['addresses'],
                $summary['scanned'],
            ]],
        );

        return self::SUCCESS;
    }
}
