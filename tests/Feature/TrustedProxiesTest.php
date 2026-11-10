<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_proxy-probe', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
        'url' => url('/dashboard'),
    ]));
});

function probe(string $peer, array $headers = [])
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders($headers)->getJson('/_proxy-probe');
}

it('ignores forwarded headers from a peer that is not a trusted proxy', function () {
    probe('203.0.113.50', [
        'X-Forwarded-For' => '198.51.100.99',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'evil.example.test',
    ])->assertJson([
        'ip' => '203.0.113.50',
        'secure' => false,
    ])->assertJsonMissing(['host' => 'evil.example.test']);
});

it('ignores a spoofed X-Forwarded-For sent straight to the app over IPv6', function () {
    probe('2001:db8::7', ['X-Forwarded-For' => '198.51.100.99'])->assertJson(['ip' => '2001:db8::7']);
});

it('believes Railway\'s edge for the client IP and https', function () {
    $response = probe('100.64.12.34', [
        'X-Forwarded-For' => '198.51.100.7',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'www.fibes.io',
        'X-Forwarded-Port' => '443',
    ]);

    $response->assertJson([
        'ip' => '198.51.100.7',
        'secure' => true,
        'host' => 'www.fibes.io',
        'url' => 'https://www.fibes.io/dashboard',
    ]);
});

it('finds the client behind more than one Railway hop', function () {
    probe('100.64.12.34', ['X-Forwarded-For' => '198.51.100.7, 100.88.1.2'])->assertJson(['ip' => '198.51.100.7']);
});

it('sends HSTS behind Railway\'s edge', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '100.64.12.34'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/login')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security');
});

it('does not send HSTS to an untrusted peer claiming https', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/login')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('uses TRUSTED_PROXIES when it is set', function () {
    config(['trustedproxy.proxies' => '203.0.113.50']);
    probe('203.0.113.50', ['X-Forwarded-For' => '198.51.100.7'])->assertJson(['ip' => '198.51.100.7']);
    probe('100.64.12.34', ['X-Forwarded-For' => '198.51.100.7'])->assertJson(['ip' => '100.64.12.34']);

    config(['trustedproxy.proxies' => '*']);
    probe('203.0.113.50', ['X-Forwarded-For' => '198.51.100.7'])->assertJson(['ip' => '198.51.100.7']);
});

it('defaults to the Railway edge, loopback and private ranges only', function () {
    expect(explode(',', (string) config('trustedproxy.proxies')))
        ->toContain('100.0.0.0/8', '127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7')
        ->not->toContain('*', '**', '0.0.0.0/0');
});

it('keeps the per-IP login lockout on the real address when X-Forwarded-For is spoofed', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config(['fibes.rate_limits.login_lockout_attempts' => 3, 'fibes.rate_limits.login_per_minute' => 100]);
    $user = User::factory()->create(['must_change_password' => false]);

    foreach (range(1, 3) as $n) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeader('X-Forwarded-For', '198.51.100.'.$n)
            ->post('/login', ['email' => $user->email, 'password' => 'synthetic-wrong'])
            ->assertSessionHasErrors('email');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->withHeader('X-Forwarded-For', '198.51.100.200')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});
