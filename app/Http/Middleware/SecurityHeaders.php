<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $response->headers->set('Content-Security-Policy', $this->policy($nonce, $request));
        if ($request->is('tv/sales')) {
            $response->headers->remove('X-Frame-Options');
        } else {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce, Request $request): string
    {
        $extra = '';

        if (app()->environment('local') && is_file(public_path('hot'))) {
            $hot = trim((string) file_get_contents(public_path('hot')));

            if ($hot !== '') {
                $extra = ' '.rtrim($hot, '/');
            }
        }

        $tiles = (string) config('fibes.coverage.tile_url');
        $tileHost = parse_url($tiles, PHP_URL_HOST);
        $tileScheme = parse_url($tiles, PHP_URL_SCHEME) ?: 'https';
        $tileOrigin = is_string($tileHost) && $tileHost !== '' ? " {$tileScheme}://{$tileHost}" : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$extra}",
            "style-src 'self' 'unsafe-inline'{$extra}",
            "img-src 'self' data: blob:{$tileOrigin}",
            "font-src 'self' data:",
            "connect-src 'self'{$extra}",
            "worker-src 'self'",
            $request->is('tv/sales') ? 'frame-ancestors *' : "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
