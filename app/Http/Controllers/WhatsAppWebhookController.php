<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $this->param($request, 'hub_mode', 'hub.mode');
        $token = $this->param($request, 'hub_verify_token', 'hub.verify_token');
        $challenge = $this->param($request, 'hub_challenge', 'hub.challenge');
        $expected = (string) config('fibes.whatsapp.verify_token');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token)) {
            abort(403);
        }

        return response(mb_substr($challenge, 0, 200), 200)->header('Content-Type', 'text/plain');
    }

    public function store(Request $request): JsonResponse
    {
        $secret = (string) config('fibes.whatsapp.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');
        $given = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        if ($secret === '' || $given === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)) {
            abort($secret === '' ? 503 : 401);
        }

        $payload = $request->json()->all();
        $this->statuses(is_array($payload) ? $payload : []);
        $this->inbound(is_array($payload) ? $payload : []);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statuses(array $payload): void
    {
        foreach ($this->list($payload, 'statuses') as $status) {
            if (! is_array($status)) {
                continue;
            }

            $id = trim((string) ($status['id'] ?? ''));
            $name = trim((string) ($status['status'] ?? ''));

            if ($id === '' || $name === '') {
                continue;
            }

            WhatsAppEvent::query()->firstOrCreate(
                ['provider_id' => mb_substr($id, 0, 191), 'status' => mb_substr($name, 0, 40)],
                [
                    'recipient' => mb_substr(preg_replace('/\D+/', '', (string) ($status['recipient_id'] ?? '')) ?? '', 0, 20) ?: null,
                    'payload' => [
                        'timestamp' => is_string($status['timestamp'] ?? null) ? $status['timestamp'] : null,
                    ],
                ],
            );
        }
    }

    /**
     * Stores an inbound notice and does not reply.
     *
     * @param  array<string, mixed>  $payload
     */
    private function inbound(array $payload): void
    {
        foreach ($this->list($payload, 'messages') as $message) {
            if (! is_array($message)) {
                continue;
            }

            $id = trim((string) ($message['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            WhatsAppEvent::query()->firstOrCreate(
                ['provider_id' => mb_substr($id, 0, 191), 'status' => 'inbound'],
                [
                    'recipient' => mb_substr(preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?? '', 0, 20) ?: null,
                    'payload' => ['type' => is_string($message['type'] ?? null) ? $message['type'] : null],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function list(array $payload, string $key): array
    {
        $rows = [];

        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                foreach (is_array($value[$key] ?? null) ? $value[$key] : [] as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function param(Request $request, string $underscored, string $dotted): string
    {
        $value = $request->query($underscored) ?? $request->query($dotted);

        return is_string($value) ? $value : '';
    }
}
