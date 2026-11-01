<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'roles' => Role::query()->withCount('users')->orderBy('name_en')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'key' => $role->key,
                'label' => $role->label(),
                'locked' => $role->is_locked,
                'builtin' => $role->is_builtin,
                'users' => $role->users_count,
            ]),
            'can_manage' => app(AccessGate::class)->allows($request->user(), 'users.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Roles/Edit', [
            'role' => null,
            'permissions' => $this->grid(null),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'key')],
            'name_en' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
        ]);

        $role = Role::query()->create([
            ...$data,
            'is_builtin' => false,
            'is_locked' => false,
            'home_route' => '/dashboard',
        ]);

        $audit->record('roles.created', $request->user(), ['role' => $role->key], $request);

        return redirect()->route('admin.roles.edit', $role)->with('status', __('ui.admin.role_saved'));
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Admin/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'key' => $role->key,
                'name_en' => $role->name_en,
                'name_ar' => $role->name_ar,
                'locked' => $role->is_locked,
                'builtin' => $role->is_builtin,
            ],
            'permissions' => $this->grid($role),
        ]);
    }

    public function update(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        if ($role->is_locked) {
            abort(403);
        }

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
            'grants' => ['array'],
            'grants.*' => ['nullable', Rule::in(['own', 'team', 'all'])],
        ]);

        $role->fill([
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
        ])->save();

        $sync = [];
        $permissions = Permission::query()->pluck('id', 'key');

        foreach ($data['grants'] ?? [] as $key => $scope) {
            if (! is_string($scope) || ! isset($permissions[$key])) {
                continue;
            }

            $sync[$permissions[$key]] = ['scope' => $scope];
        }

        $role->permissions()->sync($sync);
        $audit->record('roles.updated', $request->user(), ['role' => $role->key], $request);

        return back()->with('status', __('ui.admin.role_saved'));
    }

    public function clone(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        $copy = Role::query()->create([
            'key' => $this->uniqueKey($role->key),
            'name_en' => $role->name_en.' copy',
            'name_ar' => $role->name_ar.' نسخة',
            'is_builtin' => false,
            'is_locked' => false,
            'home_route' => '/dashboard',
        ]);

        $sync = [];

        foreach ($role->permissions as $permission) {
            $sync[$permission->id] = ['scope' => $permission->pivot->scope];
        }

        $copy->permissions()->sync($sync);
        $audit->record('roles.cloned', $request->user(), ['from' => $role->key, 'role' => $copy->key], $request);

        return redirect()->route('admin.roles.edit', $copy);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grid(?Role $role): array
    {
        $granted = $role
            ? $role->permissions->mapWithKeys(fn (Permission $permission) => [$permission->key => $permission->pivot->scope])
            : collect();

        return Permission::query()->orderBy('module')->orderBy('key')->get()->map(fn (Permission $permission) => [
            'key' => $permission->key,
            'module' => $permission->module,
            'label' => $permission->label(),
            'scope' => $granted[$permission->key] ?? '',
        ])->all();
    }

    private function uniqueKey(string $source): string
    {
        $base = Str::limit($source.'_copy', 36, '');
        $key = $base;
        $n = 2;

        while (Role::query()->where('key', $key)->exists()) {
            $key = Str::limit($base, 32, '').'_'.$n;
            $n++;
        }

        return $key;
    }
}
