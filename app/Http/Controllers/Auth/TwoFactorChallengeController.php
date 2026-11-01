<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit, SessionRegistry $sessions): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'in:code,recovery'],
        ]);

        $key = 'two-factor:'.sha1($user->id.'|'.$request->ip());
        $limit = (int) config('fibes.rate_limits.two_factor_per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages([
                'code' => __('ui.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $code = $request->string('code')->toString();
        $valid = $request->string('mode')->toString() === 'recovery'
            ? $twoFactor->consumeRecoveryCode($user, $code)
            : $twoFactor->verifyCode($user, $code);

        if (! $valid) {
            RateLimiter::hit($key, 60);
            $audit->record('auth.two_factor.failed', $user, ['mode' => $request->string('mode')->toString()], $request);

            throw ValidationException::withMessages([
                'code' => __('ui.auth.invalid_code'),
            ]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $sessions->rememberLogin($request);
        $user->markSeen();
        $audit->record('auth.login.succeeded', $user, ['two_factor' => true], $request);

        if ($user->must_change_password) {
            return redirect()->route('password.required');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');

        if (! is_numeric($id)) {
            return null;
        }

        $user = User::query()->find($id);

        if (! $user || ! $user->hasTwoFactorEnabled() || ! $user->is_active) {
            $request->session()->forget(['login.id', 'login.remember']);

            return null;
        }

        return $user;
    }
}
