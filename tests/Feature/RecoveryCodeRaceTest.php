<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Support\Facades\Cache;

function userWithRecoveryCodes(array $codes): User
{
    $service = app(TwoFactorAuthentication::class);
    $user = User::factory()->create();
    $service->storeRecoveryCodes($user, $codes);
    $user->save();

    return $user;
}

$codes = ['AAAA-1111', 'BBBB-2222', 'CCCC-3333'];

it('accepts a recovery code once', function () use ($codes) {
    $user = userWithRecoveryCodes($codes);
    $service = app(TwoFactorAuthentication::class);

    expect($service->consumeRecoveryCode($user, 'aaaa-1111'))->toBeTrue()
        ->and($service->consumeRecoveryCode($user, 'AAAA-1111'))->toBeFalse()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(2);
});

it('refuses a code already used by a request that loaded the user at the same time', function () use ($codes) {
    $user = userWithRecoveryCodes($codes);
    $service = app(TwoFactorAuthentication::class);
    // Two requests that both loaded the user before either one used the code.
    $first = User::find($user->id);
    $second = User::find($user->id);

    expect($service->consumeRecoveryCode($first, 'AAAA-1111'))->toBeTrue()
        ->and($service->consumeRecoveryCode($second, 'AAAA-1111'))->toBeFalse();
});

it('does not bring a used code back when another stale request uses a different code', function () use ($codes) {
    $user = userWithRecoveryCodes($codes);
    $service = app(TwoFactorAuthentication::class);
    $first = User::find($user->id);
    $second = User::find($user->id);

    $service->consumeRecoveryCode($first, 'AAAA-1111');
    $service->consumeRecoveryCode($second, 'BBBB-2222');

    $left = $user->fresh()->two_factor_recovery_codes;
    expect($left)->toHaveCount(1)
        ->and($left)->toBe([hash('sha256', 'CCCC-3333')])
        ->and($second->two_factor_recovery_codes)->toBe($left);
});

it('fails closed while another request holds the lock', function () use ($codes) {
    $user = userWithRecoveryCodes($codes);
    $lock = Cache::lock('two-factor:recovery:'.$user->id.':lock', 10);
    $lock->get();

    try {
        expect(app(TwoFactorAuthentication::class)->consumeRecoveryCode($user, 'AAAA-1111'))->toBeFalse()
            ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(3);
    } finally {
        $lock->release();
    }
});

it('rejects an unknown code and leaves the stored codes alone', function () use ($codes) {
    $user = userWithRecoveryCodes($codes);

    expect(app(TwoFactorAuthentication::class)->consumeRecoveryCode($user, 'ZZZZ-9999'))->toBeFalse()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(3);
});
