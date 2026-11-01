<?php

namespace App\Console\Commands;

use App\Services\Collections\CollectionsDesk;
use Illuminate\Console\Command;

class CollectionsRun extends Command
{
    protected $signature = 'collections:run';

    protected $description = 'Send dunning steps and mark broken promises';

    public function handle(CollectionsDesk $collections): int
    {
        $count = $collections->run(null);
        $this->info('Collections run '.$count);

        return self::SUCCESS;
    }
}
