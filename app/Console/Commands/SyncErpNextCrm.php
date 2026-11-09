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
        {--apply : Write (requires FIBES_ERPNEXT_CRM_APPLY=true)}';

    protected $description = 'Preview ERPNext CRM synchronization changes';

    public function handle(): int
    {
        if ($this->option('apply') && ! (bool) config('fibes.erpnext.crm_apply', false)) {
            $this->error('Apply refused: set FIBES_ERPNEXT_CRM_APPLY=true to enable writes.');

            return self::FAILURE;
        }

        try {
            $sync = new ErpNextCrmSync($this->option('fixture') ?: null);
            $summary = $sync->dryRun();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $this->warn('Apply mode is guarded, but this synchronization service is configured for dry-run reporting only. No records were written.');
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
                count($summary[$type]['samples']),
            ];
        }

        $this->table(
            ['Type', 'Would create', 'Would update', 'Unchanged', 'Samples'],
            $rows,
        );

        return self::SUCCESS;
    }
}
