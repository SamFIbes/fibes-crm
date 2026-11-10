<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config(['fibes.session.idle_minutes' => 10000, 'fibes.session.absolute_minutes' => 10000]);
});

function userManager(): User
{
    $role = Role::query()->where('key', 'management')->firstOrFail();
    foreach (['users.view', 'users.manage'] as $key) {
        DB::table('role_permission')->updateOrInsert(
            ['role_id' => $role->id, 'permission_id' => Permission::query()->where('key', $key)->value('id')],
            ['scope' => 'all'],
        );
    }
    $user = User::factory()->create(['role' => 'management', 'must_change_password' => false]);
    $user->roles()->sync([$role->id]);

    return $user;
}

function userPayload(User $user, array $roleIds, array $overrides = []): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'is_active' => true,
        'force_two_factor' => false,
        'role_ids' => $roleIds,
        'team_ids' => [],
        ...$overrides,
    ];
}

it('stops a user manager from giving someone the Super Admin role', function () {
    $manager = userManager();
    $target = User::factory()->create(['role' => 'sales_agent']);
    $super = Role::query()->where('key', 'super_admin')->value('id');

    $this->actingAs($manager)->put('/admin/users/'.$target->id, userPayload($target, [$super]))
        ->assertSessionHasErrors('role_ids');

    expect($target->refresh()->isSuperAdmin())->toBeFalse();
});

it('stops a user manager from making themselves Super Admin through a new account', function () {
    $manager = userManager();
    $super = Role::query()->where('key', 'super_admin')->value('id');

    $this->actingAs($manager)->post('/admin/users', [
        'name' => 'Synthetic Person',
        'email' => 'synthetic.person@example.test',
        'locale' => 'en',
        'is_active' => true,
        'force_two_factor' => false,
        'password' => 'Synthetic-Pass-2026!',
        'password_confirmation' => 'Synthetic-Pass-2026!',
        'role_ids' => [$super],
        'team_ids' => [],
    ])->assertSessionHasErrors('role_ids');

    expect(User::query()->where('email', 'synthetic.person@example.test')->exists())->toBeFalse();
});

it('stops a user manager from changing a Super Admin account', function () {
    $manager = userManager();
    $admin = User::factory()->superAdmin()->create(['email' => 'top@example.test']);

    $this->actingAs($manager)->put('/admin/users/'.$admin->id, userPayload($admin, [], [
        'email' => 'taken-over@example.test',
        'password' => 'Synthetic-Pass-2026!',
        'password_confirmation' => 'Synthetic-Pass-2026!',
    ]))->assertForbidden();

    expect($admin->refresh()->email)->toBe('top@example.test');
});

it('still lets a user manager edit ordinary accounts', function () {
    $manager = userManager();
    $target = User::factory()->create(['role' => 'sales_agent']);
    $agent = Role::query()->where('key', 'sales_agent')->value('id');

    $this->actingAs($manager)->put('/admin/users/'.$target->id, userPayload($target, [$agent], ['name' => 'Renamed']))
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->name)->toBe('Renamed');
});

it('still lets a Super Admin grant the Super Admin role', function () {
    $admin = User::factory()->superAdmin()->create(['must_change_password' => false]);
    $target = User::factory()->create(['role' => 'sales_agent']);
    $super = Role::query()->where('key', 'super_admin')->value('id');

    $this->actingAs($admin)->put('/admin/users/'.$target->id, userPayload($target, [$super]))
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->isSuperAdmin())->toBeTrue();
});
