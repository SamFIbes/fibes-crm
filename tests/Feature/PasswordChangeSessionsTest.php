<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

function storedSession(string $id, int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => '',
        'last_activity' => time(),
    ]);
}

it('rotates the remember-me token and keeps only the named session', function () {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $other = User::factory()->create();
    storedSession('keep-me', $user->id);
    storedSession('drop-me', $user->id);
    storedSession('someone-else', $other->id);

    $user->replacePassword('NewHorse12345', 'keep-me');

    expect($user->fresh()->remember_token)->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe(['keep-me'])
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and(Hash::check('NewHorse12345', $user->fresh()->password))->toBeTrue();
});

it('signs out every device when the password is reset by email link', function () {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    storedSession('laptop', $user->id);
    storedSession('phone', $user->id);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewHorse12345',
        'password_confirmation' => 'NewHorse12345',
    ])->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe('old-remember-token');
});

it('signs out other devices but keeps the current one when a user changes their password', function () {
    $user = User::factory()->create(['password' => 'OldHorse12345', 'must_change_password' => false]);
    storedSession('other-device', $user->id);

    $this->actingAs($user)->put('/account/password', [
        'current_password' => 'OldHorse12345',
        'password' => 'NewHorse12345',
        'password_confirmation' => 'NewHorse12345',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);
});
