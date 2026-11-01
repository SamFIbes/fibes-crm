<?php

namespace App\Listeners;

use App\Events\OpportunityWon;
use App\Services\Orders\OrderDesk;

class OpenOrderFromWin
{
    public function handle(OpportunityWon $event): void
    {
        app(OrderDesk::class)->openFromWon($event->opportunity);
    }
}
