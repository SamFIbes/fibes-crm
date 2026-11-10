<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;

function authenticatorUser(string $secret, array $extra = []): User
{
    $user = User::factory()->create(['must_change_password' => false, ...$extra]);
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    return $user;
}

it('accepts an authenticator code only once', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();
    $user = authenticatorUser($secret);
    $code = $engine->getCurrentOtp($secret);
    $service = app(TwoFactorAuthentication::class);

    expect($service->verifyCode($user, $code))->toBeTrue()
        ->and($service->verifyCode($user, $code))->toBeFalse();
});

it('accepts a newer code after an older one, but not the older one again', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();
    $user = authenticatorUser($secret);
    $step = $engine->getTimestamp();
    $service = app(TwoFactorAuthentication::class);

    expect($service->verifyCode($user, $engine->oathTotp($secret, $step - 1)))->toBeTrue()
        ->and($service->verifyCode($user, $engine->oathTotp($secret, $step)))->toBeTrue()
        ->and($service->verifyCode($user, $engine->oathTotp($secret, $step - 1)))->toBeFalse();
});

it('keeps track of used codes per person', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();
    $first = authenticatorUser($secret);
    $second = authenticatorUser($secret);
    $code = $engine->getCurrentOtp($secret);
    $service = app(TwoFactorAuthentication::class);

    expect($service->verifyCode($first, $code))->toBeTrue()
        ->and($service->verifyCode($second, $code))->toBeTrue();
});

it('refuses a code that was already used to sign in on another attempt', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();
    $user = authenticatorUser($secret, ['password' => 'CorrectHorse12x']);
    $code = $engine->getCurrentOtp($secret);

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x']);
    $this->post('/two-factor-challenge', ['code' => $code, 'mode' => 'code']);
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => 'CorrectHorse12x']);
    $this->post('/two-factor-challenge', ['code' => $code, 'mode' => 'code'])->assertSessionHasErrors('code');
    $this->assertGuest();
});
