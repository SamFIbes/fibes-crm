<?php

namespace App\Http\Middleware;

use App\Services\Performance\CommissionDesk;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TvTokenGuard
{
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

        if (! is_string($token) || $token === '' || ! $this->desk->tokenMatches($token)) {
            return $next($request);
        }

        if (! $request->is('tv/sales', 'api/v1/tv/sales')) {
            abort(403);
        }

        $request->attributes->set('tv_token', true);

        return $next($request);
    }
}
