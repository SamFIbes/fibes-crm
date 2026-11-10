<?php

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config(['fibes.session.idle_minutes' => 10000, 'fibes.session.absolute_minutes' => 10000]);
});

it('lets a super admin sign out while viewing as someone, and ends the view-as', function () {
    $admin = User::factory()->superAdmin()->create(['must_change_password' => false]);
    $agent = User::factory()->create(['role' => 'sales_agent']);

    $this->actingAs($admin)->post('/admin/view-as', ['user_id' => $agent->id])->assertRedirect();
    expect(session('view_as_user_id'))->toBe($agent->id);

    $this->post('/logout')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session('view_as_user_id'))->toBeNull()
        ->and(AuditLog::query()->where('event', 'auth.view_as.stopped')->where('metadata->reason', 'logout')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'auth.logout')->exists())->toBeTrue();
});

it('lets the Stop viewing button work and still blocks other changes while viewing as someone', function () {
    $admin = User::factory()->superAdmin()->create(['must_change_password' => false]);
    $agent = User::factory()->create(['role' => 'sales_agent']);

    $this->actingAs($admin)->post('/admin/view-as', ['user_id' => $agent->id]);

    $this->post('/admin/users', ['name' => 'x'])->assertForbidden();
    $this->delete('/admin/view-as')->assertRedirect();
    expect(session('view_as_user_id'))->toBeNull();
});

it('does nothing for someone who is not viewing as anyone', function () {
    $agent = User::factory()->create(['role' => 'sales_agent', 'must_change_password' => false]);

    $this->actingAs($agent)->delete('/admin/view-as')->assertRedirect();

    expect(AuditLog::query()->where('event', 'auth.view_as.stopped')->exists())->toBeFalse();
    $this->actingAs($agent)->post('/admin/view-as', ['user_id' => $agent->id])->assertForbidden();
});
