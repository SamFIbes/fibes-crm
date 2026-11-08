<?php

namespace App\Services\Integrations;

interface GrandstreamClient
{
    public function dialReady(): bool;

    /**
     * Challenge only. It does not place a call.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    public function dial(string $extension, string $destination): void;
}
