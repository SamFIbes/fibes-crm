<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskBoard;
use Illuminate\Console\Command;

class GenerateTasks extends Command
{
    protected $signature = 'tasks:generate';

    protected $description = 'Create the next copy of each recurring task that is due.';

    public function handle(TaskBoard $tasks): int
    {
        $made = $tasks->generate();
        $this->info("Created {$made} tasks.");

        return self::SUCCESS;
    }
}
