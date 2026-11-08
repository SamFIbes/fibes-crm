<?php

namespace App\Services\Integrations;

use App\Models\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Assumed Whish checkout API. Public merchant docs are thin, so the paths live in
 * config('fibes.whish.paths'). Auth is Authorization: Bearer {FIBES_WHISH_API_KEY}.
 * The key is never returned or written to an attempt.
 *
 * POST {checkout} { amount, currency, reference, invoice_number, description, callback_url }
 * expects { id, url }. GET {health} expects { ok: true }.
 */
class HttpWhishClient implements WhishClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function ready(): bool
    {
        $runtime = $this->settings->runtime('whish');

        return $runtime['live'] && $runtime['url'] !== '';
    }

    public function testConnection(): array
    {
        if (! $this->ready()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $body = $this->send('GET', $this->path('health'));
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->settings->redact($exception->getMessage())];
        }

        $ok = ($body['ok'] ?? false) === true || ($body['status'] ?? null) === 'ok';

        return ['ok' => $ok, 'message' => $ok ? 'ok' : 'unexpected'];
    }

    public function charge(string $reference, string $amount, string $currency): array
    {
        Log::info('whish.charge', [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return ['status' => 'pending'];
    }

    /**
     * @return array{id: string, url: string, reference: string}
     */
    public function createCheckout(Invoice $invoice): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Whish is not configured.');
        }

        $number = (string) $invoice->number;
        $body = $this->send('POST', $this->path('checkout'), [
            'amount' => bcadd((string) $invoice->total, '0', 2),
            'currency' => 'USD',
            'reference' => $number,
            'invoice_number' => $number,
            'description' => 'FIBES '.$number,
            'callback_url' => rtrim((string) config('app.url'), '/').'/api/v1/hooks/whish',
        ]);
        $id = trim((string) ($body['id'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));

        if ($id === '' || $url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Whish did not return a checkout.');
        }

        return [
            'id' => mb_substr($id, 0, 120),
            'url' => mb_substr($url, 0, 500),
            'reference' => trim((string) ($body['reference'] ?? $id)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $pending = $this->http();
        $response = $method === 'POST'
            ? $pending->asJson()->post($path, $body ?? [])
            : $pending->get($path);

        if ($response->failed()) {
            throw new RuntimeException('Whish HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout(15)
            ->acceptJson()
            ->withToken((string) config('fibes.whish.api_key'));
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings->runtime('whish')['url'], '/');
    }

    private function path(string $name): string
    {
        $path = (string) config('fibes.whish.paths.'.$name);

        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException('Invalid Whish path.');
        }

        return $path;
    }
}
