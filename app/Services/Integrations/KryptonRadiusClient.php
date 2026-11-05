<?php

namespace App\Services\Integrations;

use App\Models\CustomerService;
use App\Services\Collections\RadiusDriver;

interface KryptonRadiusClient extends RadiusDriver
{
    public function ready(): bool;

    /**
     * A safe health read when live. No call when the flag, key, URL, or desk switch is off.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    public function queueUpsert(CustomerService $service): void;

    /**
     * Read-only session lookup. Writes stay on the queue.
     *
     * @return array{username: string, online: bool, status: string, framed_ip: string|null, session_started_at: string|null}
     */
    public function session(string $username): array;
}
