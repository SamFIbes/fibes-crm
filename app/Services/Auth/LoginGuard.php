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
    /**
     * Failures across all IPs, as a multiple of the per-IP limit, that pause the account everywhere.
     */
    public const ACCOUNT_LOCK_FACTOR = 5;

    public function __construct(private AuditLogger $audit) {}

    public function ensureCanAttempt(Request $request, string $email): void
    {
        $seconds = max($this->lockSeconds($email), $this->untilSeconds($this->sourceLockKey($email, $request->ip())));

        if ($seconds > 0) {
            $this->reject($request, 'ui.auth.lockout', $seconds);
        }

        if (RateLimiter::tooManyAttempts($this->minuteKey($email, $request->ip()), $this->perMinute())) {
            $this->reject($request, 'ui.auth.throttle', RateLimiter::availableIn($this->minuteKey($email, $request->ip())));
        }
    }

    public function recordFailure(Request $request, string $email, ?User $user): void
    {
        RateLimiter::hit($this->minuteKey($email, $request->ip()), 60);

        $fromHere = $this->bump($this->sourceAttemptsKey($email, $request->ip()));
        $everywhere = $this->bump($this->attemptsKey($email));

        $this->audit->record('auth.login.failed', $user, ['email' => $email], $request);

        // Someone who only knows an address can no longer lock its owner out:
        // failures from one IP pause sign-in for that IP only. A much higher
        // count across all IPs (a spread-out guessing attack) still pauses
        // the account everywhere.
        if ($everywhere >= $this->lockoutAttempts() * self::ACCOUNT_LOCK_FACTOR) {
            $this->lockAccount($request, $email, $user);

            return;
        }

        if ($fromHere >= $this->lockoutAttempts()) {
            $this->lock($request, $this->sourceLockKey($email, $request->ip()), $email, $user, 'source');
        }
    }

    /**
     * Pause sign-in for this email for the lockout period and tell the account owner.
     */
    public function lockAccount(Request $request, string $email, ?User $user): void
    {
        $this->lock($request, $this->lockKey($email), $email, $user, 'account');
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
        Cache::forget($this->sourceAttemptsKey($email, $request->ip()));
        Cache::forget($this->sourceLockKey($email, $request->ip()));
        Cache::forget($this->attemptsKey($email));
        Cache::forget($this->lockKey($email));
    }

    public function locked(string $email): bool
    {
        return $this->lockSeconds($email) > 0;
    }

    public function lockSeconds(string $email): int
    {
        return $this->untilSeconds($this->lockKey($email));
    }

    private function lock(Request $request, string $key, string $email, ?User $user, string $scope): void
    {
        $minutes = $this->lockoutMinutes();
        Cache::put($key, now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes));

        $this->audit->record('auth.login.locked', $user, [
            'email' => $email,
            'minutes' => $minutes,
            'scope' => $scope,
        ], $request);

        // One email per lockout window, however many IPs trip it.
        if ($user && $user->is_active && Cache::add($this->notifiedKey($email), true, now()->addMinutes($minutes))) {
            $user->notify(new AccountLockedNotification($minutes));
        }
    }

    private function bump(string $key): int
    {
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinutes($this->lockoutMinutes()));

        return $count;
    }

    private function untilSeconds(string $key): int
    {
        $until = Cache::get($key);

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

    private function sourceAttemptsKey(string $email, ?string $ip): string
    {
        return 'login-attempts-ip:'.sha1($email.'|'.(string) $ip);
    }

    private function sourceLockKey(string $email, ?string $ip): string
    {
        return 'login-lock-ip:'.sha1($email.'|'.(string) $ip);
    }

    private function notifiedKey(string $email): string
    {
        return 'login-lock-notified:'.sha1($email);
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
