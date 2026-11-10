<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginGuard;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    /**
     * How long a correct password stays waiting for its second step.
     */
    private const PENDING_SECONDS = 300;

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit, SessionRegistry $sessions, LoginGuard $guard): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'in:code,recovery'],
        ]);

        $email = Str::lower($user->email);

        if ($guard->locked($email)) {
            return $this->lockedOut($request, $guard, $email);
        }

        // Count the attempt before checking the code, so parallel guesses can't slip past.
        $ipKey = 'two-factor:'.sha1($user->id.'|'.$request->ip());
        $perMinute = (int) config('fibes.rate_limits.two_factor_per_minute', 5);

        if (RateLimiter::hit($ipKey, 60) > $perMinute) {
            throw ValidationException::withMessages([
                'code' => __('ui.auth.throttle', ['seconds' => RateLimiter::availableIn($ipKey)]),
            ]);
        }

        // A per-person counter, whatever address the guesses come from.
        $userKey = 'two-factor-user:'.$user->id;
        $attempts = RateLimiter::hit($userKey, $guard->lockoutSeconds());

        if ($attempts > $guard->lockoutAttemptLimit()) {
            $guard->lockAccount($request, $email, $user);

            return $this->lockedOut($request, $guard, $email);
        }

        $code = $request->string('code')->toString();
        $valid = $request->string('mode')->toString() === 'recovery'
            ? $twoFactor->consumeRecoveryCode($user, $code)
            : $twoFactor->verifyCode($user, $code);

        if (! $valid) {
            $audit->record('auth.two_factor.failed', $user, ['mode' => $request->string('mode')->toString()], $request);

            if ($attempts >= $guard->lockoutAttemptLimit()) {
                $guard->lockAccount($request, $email, $user);

                return $this->lockedOut($request, $guard, $email);
            }

            throw ValidationException::withMessages([
                'code' => __('ui.auth.invalid_code'),
            ]);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($userKey);
        $guard->clear($request, $email);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.at']);

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

    private function lockedOut(Request $request, LoginGuard $guard, string $email): RedirectResponse
    {
        $this->forgetPending($request);
        $seconds = $guard->lockSeconds($email);

        return redirect()->route('login')->withErrors([
            'email' => __('ui.auth.lockout', ['seconds' => $seconds, 'minutes' => max(1, (int) ceil($seconds / 60))]),
        ]);
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = $request->session()->get('login.at');

        if (! is_numeric($id)) {
            return null;
        }

        if (! is_numeric($at) || now()->getTimestamp() - (int) $at > self::PENDING_SECONDS) {
            $this->forgetPending($request);

            return null;
        }

        $user = User::query()->find($id);

        if (! $user || ! $user->hasTwoFactorEnabled() || ! $user->is_active) {
            $this->forgetPending($request);

            return null;
        }

        return $user;
    }

    private function forgetPending(Request $request): void
    {
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);
    }
}
