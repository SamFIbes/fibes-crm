<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\Auth\LoginGuard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config([
        'fibes.rate_limits.login_lockout_attempts' => 3,
        'fibes.rate_limits.login_per_minute' => 100,
    ]);
    Notification::fake();
});

function failFrom(User $user, string $ip, int $times): void
{
    foreach (range(1, $times) as $ignored) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => $user->email, 'password' => 'synthetic-wrong'])
            ->assertSessionHasErrors('email');
    }
}

function signInFrom(User $user, string $ip)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);
}

it('pauses only the guessing IP, so the owner can still sign in from elsewhere', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    failFrom($user, '203.0.113.9', 3);

    signInFrom($user, '203.0.113.9')->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(app(LoginGuard::class)->locked(Str::lower($user->email)))->toBeFalse();

    signInFrom($user, '198.51.100.20')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('still pauses the whole account when guesses come from many IPs', function () {
    $user = User::factory()->create(['must_change_password' => false]);
    $limit = 3 * LoginGuard::ACCOUNT_LOCK_FACTOR;

    foreach (range(1, $limit) as $n) {
        failFrom($user, '192.0.2.'.$n, 1);
    }

    expect(app(LoginGuard::class)->locked(Str::lower($user->email)))->toBeTrue();
    signInFrom($user, '198.51.100.20')->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(AuditLog::query()->where('event', 'auth.login.locked')->where('metadata->scope', 'account')->exists())->toBeTrue();
});

it('emails the owner once per lockout window, not once per IP', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    failFrom($user, '203.0.113.1', 3);
    failFrom($user, '203.0.113.2', 3);
    failFrom($user, '203.0.113.3', 3);

    Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);
    expect(AuditLog::query()->where('event', 'auth.login.locked')->where('metadata->scope', 'source')->count())->toBe(3);
});

it('keeps the per-minute limit and clears the IP pause after a good sign-in', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    failFrom($user, '203.0.113.7', 2);
    signInFrom($user, '203.0.113.7')->assertSessionHasNoErrors();
    auth()->logout();

    failFrom($user, '203.0.113.7', 2);
    signInFrom($user, '203.0.113.7')->assertSessionHasNoErrors();

    RateLimiter::clear('login:'.sha1(Str::lower($user->email).'|203.0.113.7'));
});
