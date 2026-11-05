<?php

namespace App\Services\Collections;

use App\Services\Integrations\IntegrationSettings;
use App\Services\Integrations\KryptonRadiusClient;
use Illuminate\Support\Facades\Log;

class MockRadiusDriver implements KryptonRadiusClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): array
    {
        if (! $this->settings->runtime('krypton')['live']) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        return ['ok' => false, 'message' => 'scaffold'];
    }

    public function suspend(string $username): void
    {
        Log::info('radius.suspend', ['username' => $username, 'live' => (bool) config('fibes.radius.enabled')]);
    }

    public function reactivate(string $username): void
    {
        Log::info('radius.reactivate', ['username' => $username, 'live' => (bool) config('fibes.radius.enabled')]);
    }
}
