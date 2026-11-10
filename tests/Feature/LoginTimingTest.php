<?php

use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Cache;

function forgetFactoryPassword(): void
{
    // UserFactory caches one hash; these tests change the bcrypt cost, so the
    // cached hash must not leak into (or in from) other tests.
    (new ReflectionProperty(UserFactory::class, 'password'))->setValue(null, null);
}

beforeEach(function () {
    forgetFactoryPassword();
    $this->seed(RoleAndPermissionSeeder::class);
});

afterEach(fn () => forgetFactoryPassword());

function signInTime(string $email, int $times = 5): float
{
    $samples = [];

    foreach (range(1, $times) as $ignored) {
        $start = hrtime(true);
        test()->post('/login', ['email' => $email, 'password' => 'synthetic-wrong']);
        $samples[] = hrtime(true) - $start;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)] / 1e6;
}

it('takes about as long for an unknown email as for a wrong password', function () {
    // A realistic bcrypt cost, so the hash dominates the request time.
    config(['hashing.bcrypt.rounds' => 11, 'fibes.rate_limits.login_per_minute' => 1000, 'fibes.rate_limits.login_lockout_attempts' => 1000]);
    $user = User::factory()->create(['password' => 'password']);
    signInTime('warm-up@example.test', 1);

    $wrongPassword = signInTime($user->email);
    $unknownEmail = signInTime('nobody@example.test');

    expect($unknownEmail)->toBeGreaterThan($wrongPassword * 0.5);
});

it('makes the stand-in hash once, with the same algorithm and cost as real passwords', function () {
    config(['hashing.bcrypt.rounds' => 6]);
    $user = User::factory()->create(['password' => 'password']);

    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'synthetic-wrong']);
    $driver = 'bcrypt';
    $dummy = Cache::get('auth:dummy-hash:'.sha1($driver.json_encode(config('hashing.bcrypt'))));

    expect($dummy)->toBeString()
        ->and(password_get_info($dummy)['algoName'])->toBe(password_get_info($user->password)['algoName'])
        ->and(password_get_info($dummy)['options'])->toBe(password_get_info($user->password)['options']);

    $this->post('/login', ['email' => 'other@example.test', 'password' => 'synthetic-wrong']);
    expect(Cache::get('auth:dummy-hash:'.sha1($driver.json_encode(config('hashing.bcrypt')))))->toBe($dummy);
});

it('gives the same answer for an unknown email and a wrong password', function () {
    $user = User::factory()->create();

    $unknown = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'synthetic-wrong'])->assertSessionHasErrors('email');
    $unknownError = session('errors')->first('email');
    $this->flushSession();

    $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-wrong'])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe($unknownError)
        ->and($unknown->getStatusCode())->toBe(302);
});

it('still signs in with the right password', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});
