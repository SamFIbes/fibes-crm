<?php

namespace App\Services\Orders;

use App\Models\Order;

class ManualBillingGate implements BillingGate
{
    public function confirmed(Order $order): bool
    {
        return (bool) $order->billing_confirmed;
    }
}
