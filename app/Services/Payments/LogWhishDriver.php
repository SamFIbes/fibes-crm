<?php

namespace App\Services\Payments;

use App\Services\Integrations\IntegrationSettings;
use App\Services\Integrations\WhishClient;
use Illuminate\Support\Facades\Log;

class LogWhishDriver implements WhishClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): array
    {
        if (! $this->settings->runtime('whish')['live']) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        return ['ok' => false, 'message' => 'scaffold'];
    }

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
