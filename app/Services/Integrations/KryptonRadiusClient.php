<?php

namespace App\Services\Integrations;

use App\Services\Collections\RadiusDriver;

interface KryptonRadiusClient extends RadiusDriver
{
    /**
     * No live call. CSV import stays the working path.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
