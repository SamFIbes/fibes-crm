<?php

namespace App\Console\Commands;

use App\Services\Retention\RetentionDesk;
use Illuminate\Console\Command;

class RetentionRun extends Command
{
    protected $signature = 'retention:run';

    protected $description = 'Flag at-risk customers and create win-back tasks';

    public function handle(RetentionDesk $retention): int
    {
        $retention->sweep(null);
        $this->info('Retention sweep finished');

        return self::SUCCESS;
    }
}
