<?php

namespace App\Services\Integrations;

class StubGrandstreamClient implements GrandstreamClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function testConnection(): array
    {
        if (! $this->settings->runtime('grandstream')['live']) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        return ['ok' => false, 'message' => 'scaffold'];
    }
}
