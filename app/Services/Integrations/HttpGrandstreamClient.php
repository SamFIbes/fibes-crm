<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Grandstream UCM HTTP API (JSON), not AMI and not a CDR poll.
 *
 * A safe test is the challenge action: it proves the PBX answers and does not place a call.
 * Click-to-dial logs in with md5(challenge + password), posts the configured dial action,
 * then logs out. The password and cookie are not written to the attempt log.
 *
 * Abandoned calls are not polled. The UCM may push a signed event to /api/telephony/abandoned.
 */
class HttpGrandstreamClient implements GrandstreamClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function dialReady(): bool
    {
        return $this->settings->dialReady();
    }

    public function testConnection(): array
    {
        if (! $this->dialReady()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $body = $this->post([
                'action' => 'challenge',
                'user' => (string) config('fibes.telephony.user'),
                'version' => '1.0',
            ]);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->settings->redact($exception->getMessage())];
        }

        $challenge = data_get($body, 'response.challenge');
        $ok = (int) ($body['status'] ?? -1) === 0 && is_string($challenge) && $challenge !== '';

        return ['ok' => $ok, 'message' => $ok ? 'ok' : 'unexpected'];
    }

    public function dial(string $extension, string $destination): void
    {
        if (! $this->dialReady()) {
            throw new RuntimeException('Grandstream click-to-dial is not configured.');
        }

        $extension = $this->extension($extension);
        $destination = $this->number($destination);
        $challenge = $this->post([
            'action' => 'challenge',
            'user' => (string) config('fibes.telephony.user'),
            'version' => '1.0',
        ]);
        $nonce = (string) data_get($challenge, 'response.challenge');

        if ($nonce === '' || (int) ($challenge['status'] ?? -1) !== 0) {
            throw new RuntimeException('Grandstream challenge failed.');
        }

        $login = $this->post([
            'action' => 'login',
            'user' => (string) config('fibes.telephony.user'),
            'token' => md5($nonce.(string) config('fibes.telephony.password')),
        ]);
        $cookie = (string) data_get($login, 'response.cookie');

        if ($cookie === '' || (int) ($login['status'] ?? -1) !== 0) {
            throw new RuntimeException('Grandstream login failed.');
        }

        try {
            $dial = $this->post([
                'action' => $this->dialAction(),
                'cookie' => $cookie,
                'extension' => $extension,
                'outbound' => $destination,
            ]);

            if ((int) ($dial['status'] ?? -1) !== 0) {
                throw new RuntimeException('Grandstream did not place the call.');
            }
        } finally {
            try {
                $this->post(['action' => 'logout', 'cookie' => $cookie]);
            } catch (Throwable) {
                // The call was already requested. Logout is best effort.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function post(array $request): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint(), ['request' => $request]);

        if ($response->failed()) {
            throw new RuntimeException('Grandstream HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function endpoint(): string
    {
        $base = rtrim($this->settings->runtime('grandstream')['url'], '/');

        if ($base === '') {
            throw new RuntimeException('Grandstream click-to-dial is not configured.');
        }

        if (str_ends_with($base, '/api')) {
            return $base;
        }

        $path = '/'.ltrim((string) config('fibes.telephony.api_path'), '/');

        if ($path === '/' || str_contains($path, '..')) {
            throw new RuntimeException('Invalid Grandstream API path.');
        }

        return $base.$path;
    }

    private function dialAction(): string
    {
        $action = (string) config('fibes.telephony.dial_action');

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{1,40}$/', $action)) {
            throw new RuntimeException('Invalid Grandstream dial action.');
        }

        return $action;
    }

    private function extension(string $extension): string
    {
        $extension = trim($extension);

        if (! preg_match('/^\d{2,8}$/', $extension)) {
            throw new RuntimeException('The extension cannot be dialled.');
        }

        return $extension;
    }

    private function number(string $number): string
    {
        $clean = preg_replace('/[^\d+]/', '', $number) ?? '';

        if ($clean === '' || strlen($clean) > 20) {
            throw new RuntimeException('The phone number cannot be dialled.');
        }

        return $clean;
    }
}
