<?php

namespace App\Listeners;

use App\Events\OrderActivated;
use App\Services\Performance\CommissionDesk;

class RecordActivatedPerformance
{
    public function handle(OrderActivated $event): void
    {
        app(CommissionDesk::class)->onActivated($event->order);
    }
}
