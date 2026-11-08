<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Meta WhatsApp Cloud API. A safe test reads the phone number id.
 * Sends are approved templates only, and only after the caller has checked consent.
 */
class HttpWhatsAppBusinessClient implements WhatsAppBusinessClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function ready(): bool
    {
        return $this->settings->runtime('whatsapp')['live'] && $this->phoneId() !== null;
    }

    public function testConnection(): array
    {
        if (! $this->ready()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $body = $this->send('GET', '');
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->settings->redact($exception->getMessage())];
        }

        $ok = (string) ($body['id'] ?? '') === $this->phoneId();

        return ['ok' => $ok, 'message' => $ok ? 'ok' : 'unexpected'];
    }

    /**
     * @param  list<string>  $parameters
     * @return array{ok: bool, id: string|null}
     */
    public function sendTemplate(string $to, string $template, string $language, array $parameters): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('WhatsApp Business is not configured.');
        }

        $template = $this->template($template);
        $digits = preg_replace('/\D+/', '', $to) ?? '';

        if ($digits === '' || strlen($digits) > 20) {
            throw new RuntimeException('WhatsApp number is missing.');
        }

        $language = $language === 'ar' ? 'ar' : 'en';
        $params = [];

        foreach (array_slice($parameters, 0, 8) as $parameter) {
            $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $parameter) ?? '');
            $params[] = ['type' => 'text', 'text' => mb_substr($text, 0, 200)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $digits,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($params !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => $params,
            ]];
        }

        $body = $this->send('POST', '/messages', $payload);
        $id = data_get($body, 'messages.0.id');

        return ['ok' => is_string($id) && $id !== '', 'id' => is_string($id) ? $id : null];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $suffix, ?array $body = null): array
    {
        $url = $this->graph().$suffix;
        $pending = Http::timeout(15)
            ->acceptJson()
            ->withToken((string) config('fibes.whatsapp.token'));
        $response = $method === 'POST'
            ? $pending->asJson()->post($url, $body ?? [])
            : $pending->get($url, ['fields' => 'id']);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function graph(): string
    {
        $version = (string) config('fibes.whatsapp.graph_version');

        if (! preg_match('/^v\d+\.\d+$/', $version)) {
            throw new RuntimeException('Invalid WhatsApp Graph version.');
        }

        return 'https://graph.facebook.com/'.$version.'/'.$this->phoneId();
    }

    private function phoneId(): ?string
    {
        $id = $this->settings->phoneNumberId();

        return preg_match('/^\d{6,32}$/', $id) ? $id : null;
    }

    private function template(string $template): string
    {
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $template)) {
            throw new RuntimeException('WhatsApp template name is not valid.');
        }

        return $template;
    }
}
