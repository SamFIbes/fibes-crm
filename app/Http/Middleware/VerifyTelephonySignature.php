<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelephonySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('fibes.telephony.secret');

        if ($secret === '') {
            abort(503, __('ui.calls.telephony_off'));
        }

        $timestamp = (string) $request->header('X-Fibes-Timestamp');
        $signature = (string) $request->header('X-Fibes-Signature');

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300) {
            abort(401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            abort(401);
        }

        return $next($request);
    }
}
