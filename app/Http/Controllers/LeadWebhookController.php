<?php

namespace App\Http\Controllers;

use App\Services\Leads\LeadDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class LeadWebhookController extends Controller
{
    public function webForm(Request $request, LeadDesk $leads): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->validWebFormSignature($request, $body)) {
            return $this->problem(401, 'The signature does not match.');
        }

        $payload = $request->json()->all();

        if (filled($payload['company_website'] ?? null)) {
            return response()->json(['ok' => true]);
        }

        // Answer retries and replays before Turnstile: a captcha token works
        // only once, so a retry would otherwise fail even though the lead exists.
        // Without an Idempotency-Key the signature itself identifies the
        // submission, so replaying the same signed request cannot add a lead.
        $key = $request->header('Idempotency-Key');
        $done = is_string($key) && $key !== ''
            ? 'lead-web:'.$key
            : 'lead-web-sig:'.hash('sha256', (string) $request->header('X-Fibes-Signature'));
        $claim = $done.':claim';
        $cached = Cache::get($done);

        if ($cached !== null) {
            return response()->json($cached);
        }

        if (! Cache::add($claim, 1, 60)) {
            $cached = Cache::get($done);

            return $cached !== null
                ? response()->json($cached)
                : $this->problem(409, 'This submission is still being processed.')->header('Retry-After', '2');
        }

        try {
            $turnstile = (string) config('fibes.webhooks.turnstile_secret');

            if ($turnstile !== '' && ! $this->captchaPasses($turnstile, $payload, $request)) {
                return $this->problem(422, 'The captcha check failed.');
            }

            try {
                $lead = $leads->capture([
                    'name' => $payload['name'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'email' => $payload['email'] ?? null,
                    'service_type_interest' => $payload['service'] ?? null,
                    'channel' => 'web',
                    'utm' => is_array($payload['utm'] ?? null) ? $payload['utm'] : null,
                    'language' => $payload['language'] ?? null,
                    'force' => true,
                    'force_reason' => 'web-form',
                ], null, $request, true);
            } catch (InvalidArgumentException) {
                return $this->problem(422, 'The phone number is not valid.');
            }

            $json = ['id' => $lead->id, 'number' => $lead->number];
            Cache::put($done, $json, now()->addDay());

            return response()->json($json, 201);
        } finally {
            Cache::forget($claim);
        }
    }

    public function metaVerify(Request $request): Response|JsonResponse
    {
        $mode = $this->param($request, 'hub_mode', 'hub.mode');
        $token = $this->param($request, 'hub_verify_token', 'hub.verify_token');
        $challenge = $this->param($request, 'hub_challenge', 'hub.challenge');
        $expected = (string) config('fibes.webhooks.meta_verify_token');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return $this->problem(401, 'The verify token does not match.');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function meta(Request $request, LeadDesk $leads): JsonResponse
    {
        $secret = (string) config('fibes.webhooks.meta_app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');
        $given = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        if ($secret === '' || $given === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)) {
            return $this->problem(401, 'The signature does not match.');
        }

        try {
            $lead = $leads->metaLead($request->json()->all());
        } catch (InvalidArgumentException) {
            return $this->problem(422, 'The phone number is not valid.');
        }

        return response()->json(['id' => $lead?->id, 'number' => $lead?->number]);
    }

    private function validWebFormSignature(Request $request, string $body): bool
    {
        $secret = (string) config('fibes.webhooks.web_form_secret');
        $given = $request->header('X-Fibes-Signature');

        if ($secret === '' || ! is_string($given) || $given === '') {
            return false;
        }

        $timestamp = $request->header('X-Fibes-Timestamp');

        if ($timestamp === null) {
            return ! config('fibes.webhooks.web_form_require_timestamp')
                && hash_equals(hash_hmac('sha256', $body, $secret), $given);
        }

        // A timestamp that is present but stale or malformed never falls back
        // to the body-only signature.
        if (! is_string($timestamp) || ! ctype_digit($timestamp)
            || abs(now()->getTimestamp() - (int) $timestamp) > (int) config('fibes.webhooks.web_form_tolerance_seconds', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $given);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function captchaPasses(string $secret, array $payload, Request $request): bool
    {
        try {
            $check = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => (string) ($payload['turnstile_token'] ?? ''),
                'remoteip' => $request->ip(),
            ]);

            return $check->ok() && $check->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => match ($status) {
                401 => 'Unauthorized',
                409 => 'Conflict',
                default => 'Unprocessable',
            },
            'status' => $status,
            'detail' => $detail,
        ], $status);
    }

    private function param(Request $request, string $underscored, string $dotted): string
    {
        $value = $request->query($underscored) ?? $request->query($dotted);

        return is_string($value) ? $value : '';
    }
}
