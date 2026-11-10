<?php

use App\Models\User;
use App\Services\Performance\CommissionDesk;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function hammer(string $method, string $uri, int $times, string $ip = '203.0.113.10'): array
{
    $codes = [];

    foreach (range(1, $times) as $ignored) {
        $codes[] = test()->withServerVariables(['REMOTE_ADDR' => $ip])->json($method, $uri)->status();
    }

    return $codes;
}

it('puts a rate limit on every public token and webhook route', function (string $name) {
    $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))->toBeTrue();
})->with([
    'tv.feed', 'tv.sales', 'automations.ping', 'reports.download', 'exports.download',
    'hooks.meta', 'hooks.meta.verify', 'hooks.whatsapp.verify', 'hooks.web-form',
    'exports.customers', 'performance.export', 'purchasing.export',
]);

it('stops guessing API keys on the automations ping', function () {
    $codes = hammer('GET', '/api/v1/automations/ping', 31);

    expect(array_unique(array_slice($codes, 0, 30)))->toBe([401])
        ->and(end($codes))->toBe(429);
});

it('stops guessing export download tokens', function () {
    $codes = hammer('GET', '/exports/not-a-real-token', 31);

    expect(end($codes))->toBe(429)
        ->and($codes[0])->not->toBe(429);
});

it('stops guessing the TV token on any URL', function () {
    $codes = [];
    foreach (range(1, 21) as $n) {
        $codes[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->getJson('/api/v1/hooks/web-form?token=guess-'.$n)->status();
    }

    expect(end($codes))->toBe(429)
        ->and($codes[0])->not->toBe(429);
});

it('keeps a real TV screen polling every 2 seconds working', function () {
    $finance = User::factory()->superAdmin()->create();
    $token = app(CommissionDesk::class)->issueToken($finance, Request::create('/'));

    $codes = hammer('GET', '/api/v1/tv/sales?token='.$token, 60, '198.51.100.30');

    expect(array_unique($codes))->toBe([200]);
});

it('limits anonymous calls to the TV feed', function () {
    $codes = hammer('GET', '/api/v1/tv/sales', 21, '203.0.113.12');

    expect($codes[0])->toBe(403)->and(end($codes))->toBe(429);
});

it('counts limits per address, so one noisy client does not block another', function () {
    hammer('GET', '/api/v1/automations/ping', 31, '203.0.113.13');

    expect(hammer('GET', '/api/v1/automations/ping', 1, '203.0.113.14'))->toBe([401]);
});
