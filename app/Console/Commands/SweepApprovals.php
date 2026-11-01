<?php

namespace App\Console\Commands;

use App\Services\Approvals\ApprovalDesk;
use Illuminate\Console\Command;

class SweepApprovals extends Command
{
    protected $signature = 'approvals:sla';

    protected $description = 'Remind approvers halfway to the deadline and escalate once when time runs out.';

    public function handle(ApprovalDesk $approvals): int
    {
        $fired = $approvals->sweep();
        $this->info("escalated={$fired}");

        return self::SUCCESS;
    }
}
