<?php

namespace App\Http\Middleware;

use App\Services\Performance\CommissionDesk;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class TvTokenGuard
{
    private const MISSES_PER_MINUTE = 20;

    public function __construct(private CommissionDesk $desk) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        if (! is_string($token) || $token === '') {
            $token = $request->bearerToken();
        }

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        if (! $this->desk->tokenMatches($token)) {
            // This guard runs on every URL, so cap wrong guesses everywhere, not just on the TV routes.
            $key = 'tv-token-miss:'.$request->ip();

            if (RateLimiter::tooManyAttempts($key, self::MISSES_PER_MINUTE)) {
                abort(429);
            }

            RateLimiter::hit($key, 60);

            return $next($request);
        }

        if (! $request->is('tv/sales', 'api/v1/tv/sales')) {
            abort(403);
        }

        $request->attributes->set('tv_token', true);

        return $next($request);
    }
}
