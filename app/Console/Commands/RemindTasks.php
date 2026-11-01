<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskBoard;
use Illuminate\Console\Command;

class RemindTasks extends Command
{
    protected $signature = 'tasks:remind';

    protected $description = 'Send due reminders and flag overdue tasks.';

    public function handle(TaskBoard $tasks): int
    {
        $sent = $tasks->remind();
        $this->info("Sent {$sent} notices.");

        return self::SUCCESS;
    }
}
