<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

class LogWhishDriver implements WhishDriver
{
    public function charge(string $reference, string $amount, string $currency): array
    {
        Log::info('whish.charge', [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return ['status' => 'pending'];
    }
}
