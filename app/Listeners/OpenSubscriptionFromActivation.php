<?php

namespace App\Listeners;

use App\Events\OrderActivated;
use App\Services\Billing\BillingDesk;

class OpenSubscriptionFromActivation
{
    public function handle(OrderActivated $event): void
    {
        app(BillingDesk::class)->openFromOrder($event->order);
    }
}
