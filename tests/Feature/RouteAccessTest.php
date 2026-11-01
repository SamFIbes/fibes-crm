<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessGate;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('answers every page for every role with success or a refusal', function () {
    $routes = collect(Route::getRoutes())->filter(function ($route) {
        return in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{');
    })->map(fn ($route) => [
        'uri' => '/'.ltrim($route->uri(), '/'),
        'middleware' => $route->gatherMiddleware(),
    ])->values();

    expect($routes->count())->toBeGreaterThan(10);

    foreach (Role::query()->orderBy('key')->pluck('key') as $role) {
        $user = User::factory()->create(['role' => $role]);
        $gate = app(AccessGate::class);

        foreach ($routes as $route) {
            $needed = [];

            foreach ($route['middleware'] as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $needed = array_merge($needed, explode(',', substr($middleware, strlen('permission:'))));
                }
            }

            $response = $this->actingAs($user)->get($route['uri']);
            $code = $response->getStatusCode();
            $allowed = $needed === [] || collect($needed)->contains(fn (string $permission) => $gate->allows($user, $permission));

            if (! $allowed) {
                expect($code)->toBeIn([403, 404], $role.' '.$route['uri']);
            } else {
                expect($code)->toBeLessThan(500, $role.' '.$route['uri']);
            }
        }
    }
});
