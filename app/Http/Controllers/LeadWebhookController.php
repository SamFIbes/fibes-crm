<?php

namespace App\Http\Controllers;

use App\Services\Leads\LeadDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class LeadWebhookController extends Controller
{
    public function webForm(Request $request, LeadDesk $leads): JsonResponse
    {
        $secret = (string) config('fibes.webhooks.web_form_secret');
        $body = $request->getContent();
        $given = $request->header('X-Fibes-Signature');

        if ($secret === '' || ! is_string($given) || ! hash_equals(hash_hmac('sha256', $body, $secret), $given)) {
            return $this->problem(401, 'The signature does not match.');
        }

        $payload = $request->json()->all();

        if (filled($payload['company_website'] ?? null)) {
            return response()->json(['ok' => true]);
        }

        $turnstile = (string) config('fibes.webhooks.turnstile_secret');

        if ($turnstile !== '') {
            $check = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $turnstile,
                'response' => (string) ($payload['turnstile_token'] ?? ''),
                'remoteip' => $request->ip(),
            ]);

            if (! $check->ok() || $check->json('success') !== true) {
                return $this->problem(422, 'The captcha check failed.');
            }
        }

        $key = $request->header('Idempotency-Key');

        if (is_string($key) && $key !== '' && Cache::has('lead-web:'.$key)) {
            return response()->json(Cache::get('lead-web:'.$key));
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

        if (is_string($key) && $key !== '') {
            Cache::put('lead-web:'.$key, $json, now()->addDay());
        }

        return response()->json($json, 201);
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

    private function problem(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => $status === 401 ? 'Unauthorized' : 'Unprocessable',
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
