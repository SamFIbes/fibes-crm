<?php

namespace App\Services\Integrations;

interface WhatsAppBusinessClient
{
    public function ready(): bool;

    /**
     * Reads the phone number. It does not send a message.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    /**
     * @param  list<string>  $parameters
     * @return array{ok: bool, id: string|null}
     */
    public function sendTemplate(string $to, string $template, string $language, array $parameters): array;
}
