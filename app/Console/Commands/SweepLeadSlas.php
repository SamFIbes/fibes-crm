<?php

namespace App\Console\Commands;

use App\Services\Leads\LeadDesk;
use Illuminate\Console\Command;

class SweepLeadSlas extends Command
{
    protected $signature = 'leads:sla';

    protected $description = 'Warn once at 80% and breach a lead clock once.';

    public function handle(LeadDesk $leads): int
    {
        $sent = $leads->sweep();
        $this->info("Sent {$sent} lead notices.");

        return self::SUCCESS;
    }
}
