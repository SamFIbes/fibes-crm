<?php

namespace App\Services\Orders;

use App\Models\Order;

interface BillingGate
{
    public function confirmed(Order $order): bool;
}
