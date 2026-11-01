<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Access\AccessGate;
use App\Support\Access\Catalog;
use App\Support\Access\FieldPolicy;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function accessUser(string $role): User
{
    return User::factory()->create(['role' => $role]);
}

it('seeds every role from the access list and locks super admin', function () {
    expect(Role::query()->pluck('key')->sort()->values()->all())
        ->toEqual(collect(array_keys(Catalog::roles()))->sort()->values()->all());

    expect(Role::query()->where('key', 'super_admin')->value('is_locked'))->toBeTrue();
    expect(Role::query()->where('key', 'reseller')->exists())->toBeFalse();
    expect(Team::query()->count())->toBe(count(Catalog::teams()));
});

it('matches the default grant matrix', function () {
    $gate = app(AccessGate::class);

    $expect = [
        'super_admin' => [
            'leads.view' => 'all',
            'users.manage' => 'all',
            'jobs.complete' => null,
            'billing.view_cost' => 'all',
            'catalog.view' => 'all',
            'catalog.prices.edit' => 'all',
            'approvals.decide' => 'all',
            'coverage.check' => 'all',
            'coverage.edit' => 'all',
        ],
        'management' => [
            'leads.view' => 'all',
            'leads.create' => null,
            'quotes.approve' => 'all',
            'users.manage' => null,
            'coverage.view' => 'all',
            'coverage.edit' => null,
            'catalog.view' => 'all',
            'catalog.edit' => null,
        ],
        'sales_manager' => [
            'leads.assign' => 'all',
            'opportunities.close' => 'all',
            'jobs.complete' => null,
        ],
        'sales_agent' => [
            'leads.view' => 'own',
            'leads.claim' => 'own',
            'leads.assign' => null,
            'quotes.create' => 'own',
            'coverage.check' => 'all',
            'coverage.edit' => null,
            'catalog.view' => 'all',
            'catalog.prices.edit' => null,
            'approvals.decide' => null,
        ],
        'cc_supervisor' => [
            'leads.assign' => 'all',
            'tickets.assign' => 'all',
            'audit.view' => 'team',
            'audit.export' => null,
        ],
        'cc_agent' => [
            'leads.view' => 'own',
            'tickets.claim' => 'team',
            'payments.record' => null,
            'payments.request' => 'all',
            'payments.collect' => null,
        ],
        'ops_manager' => [
            'leads.view' => null,
            'jobs.schedule' => 'all',
            'opportunities.view_won' => 'all',
            'inventory.adjust' => 'all',
            'coverage.edit' => 'all',
        ],
        'noc_engineer' => [
            'services.radius_fields.edit' => 'all',
            'leads.view' => null,
            'services.suspend' => 'all',
            'coverage.edit' => 'all',
        ],
        'field_technician' => [
            'jobs.complete' => 'own',
            'leads.view' => null,
            'customers.view' => 'own',
            'coverage.view' => 'all',
            'coverage.edit' => null,
            'payments.collect' => 'own',
            'payments.record' => null,
        ],
        'installer' => [
            'jobs.complete' => 'own',
            'quotes.create' => null,
            'payments.collect' => 'own',
        ],
        'warehouse' => [
            'inventory.move' => 'all',
            'leads.view' => null,
            'inventory.view' => 'all',
            'coverage.view' => null,
            'coverage.check' => null,
            'catalog.view' => null,
        ],
        'finance' => [
            'payments.record' => 'all',
            'payments.collect' => null,
            'leads.view' => null,
            'billing.view_amounts' => 'all',
            'invoices.issue' => 'all',
            'catalog.view' => 'all',
            'catalog.prices.request' => 'all',
            'catalog.prices.edit' => null,
        ],
        'support_agent' => [
            'leads.view' => 'all',
            'leads.create' => 'all',
            'leads.edit' => null,
            'tickets.view' => 'team',
        ],
        'auditor' => [
            'leads.view' => 'all',
            'leads.create' => null,
            'customers.view_pii' => null,
            'audit.view' => 'all',
            'audit.export' => 'all',
            'users.view' => 'all',
            'users.manage' => null,
            'coverage.view' => 'all',
            'coverage.edit' => null,
        ],
    ];

    foreach ($expect as $role => $permissions) {
        $user = accessUser($role);

        foreach ($permissions as $permission => $scope) {
            expect($gate->scopeFor($user, $permission))->toBe($scope, "{$role} {$permission}");
        }
    }
});

it('uses the widest scope when a person has two roles', function () {
    $user = accessUser('sales_agent');
    $user->roles()->attach([
        Role::query()->where('key', 'sales_agent')->value('id'),
        Role::query()->where('key', 'sales_manager')->value('id'),
    ]);

    expect(app(AccessGate::class)->scopeFor($user->fresh(), 'leads.view'))->toBe('all');
});

it('returns only in-scope rows and refuses a forbidden page', function () {
    $agent = accessUser('sales_agent');
    accessUser('sales_agent');

    $visible = app(AccessGate::class)
        ->restrict(User::query(), $agent, 'leads.view', 'id')
        ->pluck('id')
        ->all();

    expect($visible)->toBe([$agent->id]);

    $this->actingAs($agent)->get('/leads')->assertOk();
    $this->actingAs(accessUser('warehouse'))->get('/leads')->assertForbidden();
    $this->actingAs(accessUser('warehouse'))->getJson('/leads')->assertForbidden()->assertJson(['message' => 'You do not have access']);
    $this->actingAs(accessUser('super_admin'))->get('/admin/users')->assertOk();
    $this->actingAs(accessUser('auditor'))->get('/admin/users')->assertOk();
    $this->actingAs(accessUser('auditor'))->get('/admin/users/create')->assertForbidden();
});

it('applies a permission change on the next request', function () {
    $role = Role::query()->create([
        'key' => 'temp_role',
        'name_en' => 'Temp',
        'name_ar' => 'مؤقت',
        'is_builtin' => false,
        'is_locked' => false,
        'home_route' => '/dashboard',
    ]);
    $user = accessUser('staff');
    $user->roles()->attach($role->id);

    $this->actingAs($user)->get('/leads')->assertForbidden();

    $permission = Permission::query()->where('key', 'leads.view')->firstOrFail();
    $role->permissions()->sync([$permission->id => ['scope' => 'all']]);
    app(AccessGate::class)->forget($user);

    $this->actingAs($user)->get('/leads')->assertOk();
});

it('hides customer details except inside a field job window and masks them for an auditor', function () {
    $policy = app(FieldPolicy::class);
    $tech = accessUser('field_technician');
    $auditor = accessUser('auditor');
    $finance = accessUser('finance');
    $record = [
        'phone' => '+96170123412',
        'email' => 'sam@fibes.test',
        'address' => 'Achrafieh',
        'national_id' => '123',
        'balance' => '35.00',
        'cost' => '10.00',
        'margin' => '25.00',
        'internal_note' => 'secret note',
        'radius_password' => 'do-not-show',
    ];

    $hidden = $policy->present($tech, $record, ['assigned_job' => false]);
    expect($hidden['phone'])->toBeNull();
    expect($hidden)->not->toHaveKey('radius_password');
    expect($hidden)->not->toHaveKey('balance');
    expect($hidden)->not->toHaveKey('cost');

    $shown = $policy->present($tech, $record, [
        'assigned_job' => true,
        'job_starts_at' => now()->subHour(),
        'job_ends_at' => now()->addHour(),
    ]);
    expect($shown['phone'])->toBe('+96170123412');

    $masked = $policy->present($auditor, $record);
    expect($masked['phone'])->toBe('+961 7• ••• •12');
    expect($masked['email'])->toBe('s•••@fibes.test');
    expect($masked)->not->toHaveKey('cost');

    $money = $policy->present($finance, $record);
    expect($money['balance'])->toBe('35.00');
    expect($money['cost'])->toBe('10.00');
});

it('refuses edits to the locked super admin role and to your own roles', function () {
    $admin = accessUser('super_admin');
    $role = Role::query()->where('key', 'super_admin')->firstOrFail();
    $admin->roles()->attach($role->id);

    $this->actingAs($admin)->put('/admin/roles/'.$role->id, [
        'name_en' => 'Changed',
        'name_ar' => 'تغير',
        'grants' => [],
    ])->assertForbidden();

    expect($role->fresh()->name_en)->toBe('Super Admin');

    $this->actingAs($admin)->put('/admin/users/'.$admin->id, [
        'name' => $admin->name,
        'email' => $admin->email,
        'locale' => 'en',
        'is_active' => true,
        'force_two_factor' => false,
        'role_ids' => [],
        'team_ids' => [],
    ])->assertSessionHasErrors('role_ids');
});

it('will not remove the last active super admin', function () {
    $admin = accessUser('super_admin');
    $role = Role::query()->where('key', 'super_admin')->firstOrFail();
    $admin->roles()->attach($role->id);

    $this->actingAs($admin)->put('/admin/users/'.$admin->id, [
        'name' => $admin->name,
        'email' => $admin->email,
        'locale' => 'en',
        'is_active' => false,
        'force_two_factor' => false,
        'role_ids' => [$role->id],
        'team_ids' => [],
    ])->assertSessionHasErrors('role_ids');

    expect($admin->fresh()->is_active)->toBeTrue();
});
