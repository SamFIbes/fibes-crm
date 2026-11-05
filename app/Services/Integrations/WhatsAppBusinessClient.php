<?php

namespace App\Services\Integrations;

interface WhatsAppBusinessClient
{
    /**
     * Meta is not called. Customer links stay on wa.me until a later phase.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
