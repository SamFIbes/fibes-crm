<?php

namespace App\Services\Integrations;

class StubWhatsAppBusinessClient implements WhatsAppBusinessClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): array
    {
        if (! $this->settings->runtime('whatsapp')['live']) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        return ['ok' => false, 'message' => 'scaffold'];
    }
}
