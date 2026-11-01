<?php

namespace App\Console\Commands;

use App\Services\Imports\BackupDesk;
use Illuminate\Console\Command;

class RestoreBackupTest extends Command
{
    protected $signature = 'backups:restore-test';

    protected $description = 'Restore the latest encrypted backup into the scratch database and report the counts.';

    public function handle(BackupDesk $backups): int
    {
        $counts = $backups->restoreTest();

        foreach ($counts as $name => $total) {
            $this->line($name.' '.$total);
        }

        return self::SUCCESS;
    }
}
