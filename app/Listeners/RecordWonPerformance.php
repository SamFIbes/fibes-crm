<?php

namespace App\Listeners;

use App\Events\OpportunityWon;
use App\Services\Performance\CommissionDesk;

class RecordWonPerformance
{
    public function handle(OpportunityWon $event): void
    {
        app(CommissionDesk::class)->onWon($event->opportunity);
    }
}
