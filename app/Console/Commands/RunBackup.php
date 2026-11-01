<?php

namespace App\Console\Commands;

use App\Services\Imports\BackupDesk;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backups:run';

    protected $description = 'Write an encrypted backup and drop copies that are past the retention in Settings.';

    public function handle(BackupDesk $backups): int
    {
        $file = $backups->run();
        $this->info('Backup '.$file);

        return self::SUCCESS;
    }
}
