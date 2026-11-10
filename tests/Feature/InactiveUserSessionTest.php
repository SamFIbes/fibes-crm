<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('signs out a switched-off user on their next request', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('ui.auth.inactive'));

    $this->assertGuest();
});

it('ends every session and remember-me token when an admin switches a user off', function () {
    $admin = User::factory()->superAdmin()->create(['must_change_password' => false]);
    $target = User::factory()->create(['is_active' => true, 'remember_token' => 'old-remember-token']);
    $other = User::factory()->create();

    foreach ([['target-a', $target->id], ['target-b', $target->id], ['other-a', $other->id]] as [$id, $userId]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    $this->actingAs($admin)->put('/admin/users/'.$target->id, [
        'name' => $target->name,
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => false,
        'force_two_factor' => false,
        'role_ids' => [],
        'team_ids' => [],
    ])->assertRedirect(route('admin.users.index'));

    $target->refresh();

    expect($target->is_active)->toBeFalse()
        ->and($target->remember_token)->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

it('leaves sessions alone when an admin edits a user who stays active', function () {
    $admin = User::factory()->superAdmin()->create(['must_change_password' => false]);
    $target = User::factory()->create(['is_active' => true, 'remember_token' => 'kept-token']);
    DB::table('sessions')->insert([
        'id' => 'target-a', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest', 'payload' => '', 'last_activity' => time(),
    ]);

    $this->actingAs($admin)->put('/admin/users/'.$target->id, [
        'name' => 'Renamed',
        'email' => $target->email,
        'locale' => 'en',
        'is_active' => true,
        'force_two_factor' => false,
        'role_ids' => [],
        'team_ids' => [],
    ])->assertRedirect(route('admin.users.index'));

    expect($target->fresh()->remember_token)->toBe('kept-token')
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});
