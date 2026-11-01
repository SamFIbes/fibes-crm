<?php

namespace App\Services\Automations;

use App\Models\WebhookAttempt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Settings\CommercialSettings;
use Illuminate\Support\Facades\Http;

class WebhookDesk
{
    public function __construct(
        private CommercialSettings $commercial,
        private DeliveryMachine $machine,
    ) {}

    public function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function fresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $window = max(30, (int) $this->commercial->get('webhook_replay_seconds'));

        return abs(time() - (int) $timestamp) <= $window;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(string $event, array $payload): void
    {
        WebhookEndpoint::query()->where('enabled', true)->orderBy('id')->each(function (WebhookEndpoint $webhook) use ($event, $payload): void {
            if (! in_array($event, $webhook->events ?? [], true)) {
                return;
            }

            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $payload,
                'status' => 'pending',
            ]);
            $this->attempt($delivery);
        });
    }

    public function attempt(WebhookDelivery $delivery): void
    {
        $delivery->loadMissing('webhook');
        $webhook = $delivery->webhook;

        if (! $webhook instanceof WebhookEndpoint) {
            return;
        }

        $body = json_encode(['event' => $delivery->event, 'data' => $delivery->payload], JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = (string) time();
        $signature = $this->sign((string) $webhook->secret, $timestamp, $body);
        $attempt = (int) $delivery->attempts + 1;
        $code = null;
        $ok = false;

        try {
            $response = Http::timeout(5)->withHeaders([
                'X-Fibes-Timestamp' => $timestamp,
                'X-Fibes-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post($webhook->url);
            $code = $response->status();
            $ok = $response->successful();
        } catch (\Throwable) {
            $code = 0;
        }

        WebhookAttempt::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'attempt' => $attempt,
            'status_code' => $code,
            'result' => $ok ? 'delivered' : 'failed',
        ]);

        $max = max(1, (int) $this->commercial->get('webhook_max_attempts'));
        $from = (string) $delivery->status;
        $delivery->attempts = $attempt;

        if ($ok) {
            $this->machine->assert($from, 'delivered');
            $delivery->status = 'delivered';
            $delivery->delivered_at = now();
            $delivery->last_error = null;
            $delivery->save();

            return;
        }

        $next = $attempt >= $max ? 'dead' : 'retrying';
        $this->machine->assert($from === 'pending' || $from === 'retrying' ? $from : 'pending', $next);
        $delivery->status = $next;
        $delivery->last_error = 'HTTP '.$code;
        $backoff = max(1, (int) $this->commercial->get('webhook_backoff_seconds'));
        $delivery->next_attempt_at = $next === 'retrying' ? now()->addSeconds($backoff * $attempt) : null;
        $delivery->save();
    }

    public function retryDue(): void
    {
        WebhookDelivery::query()->where('status', 'retrying')->where('next_attempt_at', '<=', now())->orderBy('id')->each(function (WebhookDelivery $delivery): void {
            $this->attempt($delivery);
        });
    }
}
