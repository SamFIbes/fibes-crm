<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginGuard
{
    public function __construct(private AuditLogger $audit) {}

    public function ensureCanAttempt(Request $request, string $email): void
    {
        if ($this->locked($email)) {
            $this->reject($request, 'ui.auth.lockout', $this->lockSeconds($email));
        }

        if (RateLimiter::tooManyAttempts($this->minuteKey($email, $request->ip()), $this->perMinute())) {
            $this->reject($request, 'ui.auth.throttle', RateLimiter::availableIn($this->minuteKey($email, $request->ip())));
        }
    }

    public function recordFailure(Request $request, string $email, ?User $user): void
    {
        RateLimiter::hit($this->minuteKey($email, $request->ip()), 60);

        $attempts = (int) Cache::get($this->attemptsKey($email), 0) + 1;
        Cache::put($this->attemptsKey($email), $attempts, now()->addMinutes($this->lockoutMinutes()));

        $this->audit->record('auth.login.failed', $user, ['email' => $email], $request);

        if ($attempts < $this->lockoutAttempts()) {
            return;
        }

        $this->lockAccount($request, $email, $user);
    }

    /**
     * Pause sign-in for this email for the lockout period and tell the account owner.
     */
    public function lockAccount(Request $request, string $email, ?User $user): void
    {
        Cache::put(
            $this->lockKey($email),
            now()->addMinutes($this->lockoutMinutes())->getTimestamp(),
            now()->addMinutes($this->lockoutMinutes()),
        );

        $this->audit->record('auth.login.locked', $user, [
            'email' => $email,
            'minutes' => $this->lockoutMinutes(),
        ], $request);

        if ($user && $user->is_active) {
            $user->notify(new AccountLockedNotification($this->lockoutMinutes()));
        }
    }

    public function lockoutAttemptLimit(): int
    {
        return $this->lockoutAttempts();
    }

    public function lockoutSeconds(): int
    {
        return $this->lockoutMinutes() * 60;
    }

    public function clear(Request $request, string $email): void
    {
        RateLimiter::clear($this->minuteKey($email, $request->ip()));
        Cache::forget($this->attemptsKey($email));
        Cache::forget($this->lockKey($email));
    }

    public function locked(string $email): bool
    {
        return $this->lockSeconds($email) > 0;
    }

    public function lockSeconds(string $email): int
    {
        $until = Cache::get($this->lockKey($email));

        if (! is_numeric($until)) {
            return 0;
        }

        return max(0, (int) $until - now()->getTimestamp());
    }

    private function reject(Request $request, string $key, int $seconds): void
    {
        $this->audit->record('auth.login.rejected', null, [
            'email' => Str::lower($request->string('email')->toString()),
            'seconds' => $seconds,
        ], $request);

        throw ValidationException::withMessages([
            'email' => __($key, ['seconds' => $seconds, 'minutes' => max(1, (int) ceil($seconds / 60))]),
        ]);
    }

    private function minuteKey(string $email, ?string $ip): string
    {
        return 'login:'.sha1($email.'|'.(string) $ip);
    }

    private function attemptsKey(string $email): string
    {
        return 'login-attempts:'.sha1($email);
    }

    private function lockKey(string $email): string
    {
        return 'login-lock:'.sha1($email);
    }

    private function perMinute(): int
    {
        return (int) config('fibes.rate_limits.login_per_minute', 5);
    }

    private function lockoutAttempts(): int
    {
        return (int) config('fibes.rate_limits.login_lockout_attempts', 10);
    }

    private function lockoutMinutes(): int
    {
        return (int) config('fibes.rate_limits.login_lockout_minutes', 15);
    }
}
