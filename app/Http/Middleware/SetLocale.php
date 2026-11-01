<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = config('fibes.locales', ['en', 'ar']);
        $queryLocale = $request->is('tv/sales') ? $request->query('lang') : null;
        $sessionLocale = $request->session()->get('locale');
        $userLocale = $request->user()?->locale;
        $locale = is_string($queryLocale) ? $queryLocale : (is_string($sessionLocale) ? $sessionLocale : $userLocale);

        if (! is_string($locale) || ! in_array($locale, $available, true)) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
