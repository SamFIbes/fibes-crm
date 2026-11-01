<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\Orders\BillingGate;

class LedgerBillingGate implements BillingGate
{
    public function confirmed(Order $order): bool
    {
        if ($order->billing_confirmed) {
            return true;
        }

        return Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', 'invoice')
            ->whereNotIn('status', ['draft', 'voided'])
            ->exists();
    }
}
