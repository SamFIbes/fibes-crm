<?php

namespace App\Services\Collections;

use Illuminate\Support\Facades\Log;

class MockRadiusDriver implements RadiusDriver
{
    public function suspend(string $username): void
    {
        Log::info('radius.suspend', ['username' => $username, 'live' => (bool) config('fibes.radius.enabled')]);
    }

    public function reactivate(string $username): void
    {
        Log::info('radius.reactivate', ['username' => $username, 'live' => (bool) config('fibes.radius.enabled')]);
    }
}
