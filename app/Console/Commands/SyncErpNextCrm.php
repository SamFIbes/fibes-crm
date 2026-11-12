<?php

namespace App\Console\Commands;

use App\Models\ErpSyncRun;
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
            $run = ErpSyncRun::query()->create([
                'mode' => 'live_smoke',
                'status' => 'running',
                'started_at' => now(),
            ]);

            try {
                $smoke = (new ErpNextCrmSync)->liveSmoke();
            } catch (Throwable $exception) {
                $run->update([
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                'HTTP OK | customer_count=%d | page_rows=%d',
                $smoke['customer_count'],
                $smoke['page_rows'],
            ));

            $run->update([
                'status' => 'succeeded',
                'scanned' => $smoke['customer_count'],
                'finished_at' => now(),
            ]);

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
                $run = ErpSyncRun::query()->create([
                    'mode' => 'dry_run',
                    'status' => 'running',
                    'started_at' => now(),
                ]);

                if ($this->option('apply')) {
                    $this->warn('Apply mode is guarded for fixtures; fixture path remains dry-run only. No records were written.');
                } else {
                    $this->info('ERPNext CRM synchronization dry run. No records were written.');
                }

                try {
                    $summary = $sync->dryRun();
                } catch (Throwable $exception) {
                    $run->update([
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                        'finished_at' => now(),
                    ]);

                    throw $exception;
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
                    ['Type', 'Would create', 'Would update', 'Skip', 'Conflicts', 'Errors', 'Samples'],
                    $rows,
                );

                $run->update([
                    'status' => 'succeeded',
                    'scanned' => $summary['customers']['would_create'] + $summary['customers']['would_update'] + $summary['customers']['unchanged'],
                    'would_create' => $summary['customers']['would_create'],
                    'would_update' => $summary['customers']['would_update'],
                    'unchanged' => $summary['customers']['unchanged'],
                    'ambiguous' => 0,
                    'errors' => 0,
                    'contacts' => 0,
                    'addresses' => 0,
                    'finished_at' => now(),
                ]);

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
            ['Type', 'Would create', 'Would update', 'Skip', 'Conflicts', 'Errors', 'Contacts written', 'Addresses written', 'Hub contacts', 'Hub addresses', 'Scanned'],
            [[
                'customers',
                $summary['customers']['would_create'],
                $summary['customers']['would_update'],
                $summary['customers']['unchanged'],
                $summary['customers']['ambiguous'],
                $summary['customers']['errors'],
                $summary['customers']['contacts'],
                $summary['customers']['addresses'],
                $summary['customers']['hub_contact_count'] ?? 0,
                $summary['customers']['hub_address_count'] ?? 0,
                $summary['scanned'],
            ]],
        );

        return self::SUCCESS;
    }
}
