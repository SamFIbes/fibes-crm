<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('shares a super admin menu that includes people, customers, and billing', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('auth.nav', function ($nav) {
            $keys = collect($nav)->all();

            return in_array('users', $keys, true)
                && in_array('roles', $keys, true)
                && in_array('customers', $keys, true)
                && in_array('tickets', $keys, true)
                && in_array('billing', $keys, true)
                && in_array('settings', $keys, true);
        }));
});

it('hides admin and billing from a field technician', function () {
    $tech = User::factory()->create(['role' => 'field_technician']);

    $this->actingAs($tech)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.nav', function ($nav) {
            $keys = collect($nav)->all();

            return in_array('tech', $keys, true)
                && in_array('customers', $keys, true)
                && in_array('tickets', $keys, true)
                && ! in_array('users', $keys, true)
                && ! in_array('billing', $keys, true)
                && ! in_array('settings', $keys, true);
        }));
});

it('gives warehouse settings and nothing from the customer workspace', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.nav', function ($nav) {
            $keys = collect($nav)->all();

            return in_array('settings', $keys, true)
                && in_array('dashboard', $keys, true)
                && ! in_array('customers', $keys, true)
                && ! in_array('users', $keys, true)
                && ! in_array('tickets', $keys, true);
        }));
});

it('serves the official logo files and not the old drawn mark', function () {
    expect(is_file(public_path('brand/logo-full-on-dark.png')))->toBeTrue()
        ->and(is_file(public_path('brand/logo-compact-on-dark.png')))->toBeTrue()
        ->and(is_file(public_path('brand/favicon.ico')))->toBeTrue()
        ->and(is_file(public_path('brand/mark.svg')))->toBeFalse();

    expect(file_get_contents(resource_path('views/app.blade.php')))->not->toContain('mark.svg')
        ->toContain('/brand/favicon.ico');
});
