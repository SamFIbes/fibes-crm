<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessGate;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Mail::fake();
    Notification::fake();
});

function erpImportFile(mixed $payload): string
{
    $path = tempnam(sys_get_temp_dir(), 'erp');
    file_put_contents($path, is_string($payload) ? $payload : json_encode($payload));

    return $path;
}

it('creates switched-off accounts with the lowest role and no permissions', function () {
    $file = erpImportFile(['jane.doe@fibes.test', 'sam_lee@fibes.test']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertSuccessful();

    $jane = User::query()->where('email', 'jane.doe@fibes.test')->firstOrFail();

    expect(User::query()->count())->toBe(2)
        ->and($jane->name)->toBe('Jane Doe')
        ->and($jane->is_active)->toBeFalse()
        ->and($jane->role)->toBe('staff')
        ->and($jane->roles()->count())->toBe(0)
        ->and($jane->teams()->count())->toBe(0)
        ->and($jane->must_change_password)->toBeTrue()
        ->and($jane->email_verified_at)->toBeNull()
        ->and(Hash::isHashed($jane->password))->toBeTrue();

    $gate = app(AccessGate::class);

    foreach (Permission::query()->pluck('key') as $permission) {
        expect($gate->allows($jane, $permission))->toBeFalse();
    }

    expect(AuditLog::query()->where('event', 'users.imported')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'users.imported')->first()->metadata['source'])->toBe('erpnext');
});

it('skips existing users case-insensitively and never changes them', function () {
    $existing = User::factory()->create([
        'name' => 'Existing Admin',
        'email' => 'boss@fibes.test',
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $before = $existing->fresh()->getAttributes();

    $file = erpImportFile(['BOSS@Fibes.Test', 'new.person@fibes.test', 'new.person@fibes.test']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertSuccessful();

    expect($existing->fresh()->getAttributes())->toEqual($before)
        ->and(User::query()->count())->toBe(2)
        ->and(User::query()->where('email', 'new.person@fibes.test')->count())->toBe(1);
});

it('does not duplicate accounts on a second run', function () {
    $file = erpImportFile(['one@fibes.test', 'two@fibes.test']);
    $args = ['--file' => $file, '--domain' => 'fibes.test', '--force' => true];

    $this->artisan('users:import-erpnext', $args)->assertSuccessful();
    $this->artisan('users:import-erpnext', $args)->assertSuccessful();

    expect(User::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'users.imported')->count())->toBe(2);
});

it('writes nothing on a dry run', function () {
    $file = erpImportFile(['a@fibes.test', 'b@fibes.test', 'c@elsewhere.test', 'not-an-email']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'users.imported')->count())->toBe(0);
});

it('keeps addresses outside the domain and invalid entries out', function () {
    $file = erpImportFile(['ok@fibes.test', 'outsider@elsewhere.test', "bad\r\n@fibes.test", 'nope']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertSuccessful();

    expect(User::query()->pluck('email')->all())->toBe(['ok@fibes.test']);
});

it('reads CSV and the ERPNext REST shape', function () {
    $csv = erpImportFile("email,full_name\nrami@fibes.test,Rami\nlina@fibes.test,Lina\n");
    $rest = erpImportFile(['data' => [['name' => 'omar@fibes.test'], ['email' => 'nour@fibes.test']]]);

    $this->artisan('users:import-erpnext', ['--file' => $csv, '--domain' => 'fibes.test', '--force' => true])->assertSuccessful();
    $this->artisan('users:import-erpnext', ['--file' => $rest, '--domain' => 'fibes.test', '--force' => true])->assertSuccessful();

    expect(User::query()->orderBy('email')->pluck('email')->all())
        ->toBe(['lina@fibes.test', 'nour@fibes.test', 'omar@fibes.test', 'rami@fibes.test']);
});

it('sends no mail or notifications, and a reset request for an imported user sends nothing', function () {
    $file = erpImportFile(['quiet@fibes.test']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertSuccessful();

    $this->post('/forgot-password', ['email' => 'quiet@fibes.test']);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('refuses sign-in for an imported user even with the right password', function () {
    $file = erpImportFile(['locked.out@fibes.test']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertSuccessful();

    $user = User::query()->where('email', 'locked.out@fibes.test')->firstOrFail();
    $user->forceFill(['password' => Hash::make('Known-Test-Passw0rd!')])->save();

    $this->post('/login', ['email' => 'locked.out@fibes.test', 'password' => 'Known-Test-Passw0rd!'])
        ->assertSessionHasErrors(['email' => __('ui.auth.inactive')]);

    $this->assertGuest();
});

it('stops before writing if a staff role with permissions exists', function () {
    $role = Role::query()->create([
        'key' => 'staff',
        'name_en' => 'Staff',
        'name_ar' => 'موظف',
        'is_builtin' => false,
        'is_locked' => false,
        'home_route' => '/dashboard',
    ]);
    $role->permissions()->sync([Permission::query()->where('key', 'leads.view')->value('id') => ['scope' => 'all']]);

    $file = erpImportFile(['risky@fibes.test']);

    $this->artisan('users:import-erpnext', ['--file' => $file, '--domain' => 'fibes.test', '--force' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('fails clearly without an input', function () {
    $this->artisan('users:import-erpnext')->assertFailed();
    $this->artisan('users:import-erpnext', ['--file' => '/nonexistent/path.json'])->assertFailed();
    $this->artisan('users:import-erpnext', ['--file' => erpImportFile('[]')])->assertFailed();
});
