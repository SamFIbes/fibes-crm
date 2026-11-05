<?php

use App\Http\Middleware\EnforceAccountSecurity;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TvTokenGuard;
use App\Http\Middleware\VerifyErpNextSignature;
use App\Http\Middleware\VerifyTelephonySignature;
use App\Services\Observability\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(TvTokenGuard::class);
        $middleware->prepend(RequestId::class);
        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'account.security' => EnforceAccountSecurity::class,
            'permission' => EnsurePermission::class,
            'telephony.signature' => VerifyTelephonySignature::class,
            'erpnext.signature' => VerifyErpNextSignature::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (Throwable $exception) {
            if (app()->runningUnitTests() || ! app()->bound(ErrorReporter::class)) {
                return;
            }

            app(ErrorReporter::class)->report(
                $exception,
                request()->attributes->get('request_id'),
            );
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            $friendly = [403, 404, 419];

            if (! config('app.debug')) {
                $friendly[] = 500;
                $friendly[] = 503;
            }

            if (! in_array($status, $friendly, true)) {
                return $response;
            }

            if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
                if ($status === 403) {
                    return response()->json(['message' => __('ui.errors.403_title')], 403);
                }

                return $response;
            }

            // A missing route never enters the web middleware, so share the
            // shell props here or the error screen renders without them.
            $inertia = app(HandleInertiaRequests::class);

            if ($request->hasSession()) {
                $locale = $request->session()->get('locale');

                if (is_string($locale) && in_array($locale, config('fibes.locales', []), true)) {
                    app()->setLocale($locale);
                }
            }

            Inertia::version(fn () => $inertia->version($request));
            Inertia::share($inertia->share($request));
            Inertia::setRootView($inertia->rootView($request));

            return Inertia::render('Errors/Error', [
                'status' => $status,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
