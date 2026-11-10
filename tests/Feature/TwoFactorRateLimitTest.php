<?php

use App\Models\User;
use App\Services\Auth\LoginGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @return array{0: User, 1: string}
 */
function challengeUser(): array
{
    $secret = (new Google2FA)->generateSecretKey();
    $user = User::factory()->create(['password' => 'CorrectHorse12x', 'must_change_password' => false]);
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    return [$user, $secret];
}

it('locks the account after too many wrong codes, even when they come from different addresses', function () {
    Notification::fake();
    config([
        'fibes.rate_limits.login_lockout_attempts' => 3,
        'fibes.rate_limits.two_factor_per_minute' => 100,
    ]);
    [$user] = challengeUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x'])
        ->assertRedirect(route('two-factor.login'));

    foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/two-factor-challenge', ['code' => '000000', 'mode' => 'code'])
            ->assertSessionHasErrors('code');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
        ->post('/two-factor-challenge', ['code' => '000000', 'mode' => 'code'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('login.id');

    expect(app(LoginGuard::class)->locked(Str::lower($user->email)))->toBeTrue();

    // While locked, even the right password and code don't get in.
    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('forgets a correct password that waits more than five minutes for its code', function () {
    [$user, $secret] = challengeUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x'])
        ->assertRedirect(route('two-factor.login'));

    $this->travel(6)->minutes();

    $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret), 'mode' => 'code'])
        ->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

it('keeps earlier failed sign-ins on record until the code step succeeds', function () {
    [$user, $secret] = challengeUser();
    $attemptsKey = 'login-attempts:'.sha1(Str::lower($user->email));
    Cache::put($attemptsKey, 4, now()->addMinutes(15));

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x'])
        ->assertRedirect(route('two-factor.login'));

    expect(Cache::get($attemptsKey))->toBe(4);

    $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret), 'mode' => 'code'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(Cache::has($attemptsKey))->toBeFalse();
});
