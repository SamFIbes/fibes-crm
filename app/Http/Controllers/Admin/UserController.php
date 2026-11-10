<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeEmail;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->with(['roles', 'teams'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->summary($user));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'can_manage' => app(AccessGate::class)->allows($request->user(), 'users.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', $this->formProps());
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guardSuperAdminGrant($request, $data);
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => $data['locale'],
            'is_active' => $data['is_active'],
            'force_two_factor' => $data['force_two_factor'],
            'must_change_password' => true,
            'email_verified_at' => now(),
            'role' => 'staff',
            'extension' => $data['extension'] ?? null,
        ]);

        $this->syncAccess($request, $user, $data);
        $audit->record('users.created', $request->user(), ['user_id' => $user->id], $request);

        return redirect()->route('admin.users.index')->with('status', __('ui.admin.user_saved'));
    }

    public function edit(User $user): Response
    {
        $user->load(['roles', 'teams']);

        return Inertia::render('Admin/Users/Form', [
            ...$this->formProps(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'is_active' => $user->is_active,
                'force_two_factor' => (bool) $user->force_two_factor,
                'extension' => $user->extension,
                'role_ids' => $this->roleIds($user),
                'team_ids' => $user->teams->pluck('id'),
                'last_seen_at' => $user->last_seen_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit, SessionRegistry $sessions): RedirectResponse
    {
        $this->guardSuperAdminTarget($request, $user);
        $data = $this->validated($request, $user);
        $this->guardSuperAdminGrant($request, $data);

        if ($request->user()->is($user) && $this->rolesChanged($user, $data['role_ids'])) {
            return back()->withErrors(['role_ids' => __('ui.admin.own_role')]);
        }

        if ($this->removesLastSuperAdmin($user, $data)) {
            return back()->withErrors(['role_ids' => __('ui.admin.last_admin')]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'locale' => $data['locale'],
            'is_active' => $data['is_active'],
            'force_two_factor' => $data['force_two_factor'],
            'extension' => $data['extension'] ?? null,
        ]);

        $deactivating = $user->isDirty('is_active') && ! $user->is_active;

        if ($deactivating) {
            $user->setRememberToken(Str::random(60));
        }

        if (! empty($data['password'])) {
            $user->must_change_password = true;
            $user->password = $data['password'];
        }

        $user->save();

        if (! $request->user()->is($user)) {
            $this->syncAccess($request, $user, $data);
        }

        if ($deactivating) {
            $sessions->forUser($user->id)->each(fn (object $row) => $sessions->delete((string) $row->id));
            $audit->record('auth.session.revoked', $request->user(), ['user_id' => $user->id, 'scope' => 'deactivated'], $request);
        }

        if ($request->boolean('revoke_sessions')) {
            $sessions->deleteOthers($user->id, '');
            $sessions->forUser($user->id)->each(fn (object $row) => $sessions->delete((string) $row->id));
            $audit->record('auth.session.revoked', $request->user(), ['user_id' => $user->id, 'scope' => 'admin'], $request);
        }

        $audit->record('users.updated', $request->user(), ['user_id' => $user->id], $request);

        return redirect()->route('admin.users.index')->with('status', __('ui.admin.user_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:255', new SafeEmail, Rule::unique('users', 'email')->ignore($user?->id)],
            'locale' => ['required', Rule::in(config('fibes.locales', ['en', 'ar']))],
            'is_active' => ['required', 'boolean'],
            'force_two_factor' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'team_ids' => ['array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
            'revoke_sessions' => ['sometimes', 'boolean'],
            'extension' => ['nullable', 'string', 'max:20', Rule::unique('users', 'extension')->ignore($user?->id)],
        ]);
    }

    /**
     * Only a Super Admin may change a Super Admin's account (email, password,
     * activation, roles). Otherwise users.manage would let someone take over
     * the top account and with it every permission.
     */
    private function guardSuperAdminTarget(Request $request, User $target): void
    {
        abort_if($target->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403);
    }

    /**
     * Only a Super Admin may hand out the Super Admin role.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSuperAdminGrant(Request $request, array $data): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }

        $grants = Role::query()->where('key', 'super_admin')->whereIn('id', $data['role_ids'] ?? [])->exists();

        if ($grants) {
            throw ValidationException::withMessages(['role_ids' => __('ui.admin.super_admin_only')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAccess(Request $request, User $user, array $data): void
    {
        if ($request->user()->is($user)) {
            return;
        }

        $user->roles()->sync($data['role_ids'] ?? []);
        $user->teams()->sync($data['team_ids'] ?? []);
        $user->unsetRelation('roles');

        $primary = $user->roles()->orderBy('key')->first();
        $user->forceFill([
            'role' => $user->roles()->where('key', 'super_admin')->exists()
                ? 'super_admin'
                : ($primary?->key ?? 'staff'),
        ])->save();
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function rolesChanged(User $user, array $roleIds): bool
    {
        $current = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $next = collect($roleIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $current !== $next;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function removesLastSuperAdmin(User $user, array $data): bool
    {
        if (! $user->hasRole('super_admin') || ! $user->is_active) {
            return false;
        }

        $keepsRole = Role::query()->where('key', 'super_admin')->whereIn('id', $data['role_ids'] ?? [])->exists();
        $staysActive = (bool) $data['is_active'];

        if ($keepsRole && $staysActive) {
            return false;
        }

        return $this->otherActiveSuperAdmins($user) < 1;
    }

    private function otherActiveSuperAdmins(User $user): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->where(function ($query) {
                $query->where('role', 'super_admin')
                    ->orWhereHas('roles', fn ($roles) => $roles->where('key', 'super_admin'));
            })
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'force_two_factor' => (bool) $user->force_two_factor,
            'last_seen_at' => $user->last_seen_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            'roles' => $this->roleLabels($user),
            'teams' => $user->teams->map(fn (Team $team) => $team->label())->values(),
        ];
    }

    /**
     * @return list<string>
     */
    private function roleLabels(User $user): array
    {
        $labels = $user->roles->map(fn (Role $role) => $role->label())->values();

        if ($labels->isEmpty() && is_string($user->role) && $user->role !== '') {
            $role = Role::query()->where('key', $user->role)->first();

            if ($role) {
                return [$role->label()];
            }
        }

        return $labels->all();
    }

    /**
     * @return list<int>
     */
    private function roleIds(User $user): array
    {
        $ids = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === [] && is_string($user->role) && $user->role !== '') {
            $id = Role::query()->where('key', $user->role)->value('id');

            return $id ? [(int) $id] : [];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        return [
            'account' => null,
            'roles' => Role::query()->orderBy('name_en')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'key' => $role->key,
                'label' => $role->label(),
                'locked' => $role->is_locked,
            ]),
            'teams' => Team::query()->orderBy('name_en')->get()->map(fn (Team $team) => [
                'id' => $team->id,
                'label' => $team->label(),
            ]),
        ];
    }
}
