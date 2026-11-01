<?php

namespace App\Support\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AccessGate
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $cache = [];

    public function effective(Request $request): ?User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $viewAs = $request->hasSession() ? $request->session()->get('view_as_user_id') : null;

        if (! is_numeric($viewAs) || ! $user->isSuperAdmin()) {
            return $user;
        }

        return User::query()->find((int) $viewAs) ?? $user;
    }

    public function scopeFor(User $user, string $permission): ?string
    {
        return $this->grants($user)[$permission] ?? null;
    }

    public function allows(User $user, string $permission): bool
    {
        if ($this->scopeFor($user, $permission) !== null) {
            return true;
        }

        if (str_ends_with($permission, '.view')) {
            $manage = substr($permission, 0, -strlen('.view')).'.manage';

            return $this->scopeFor($user, $manage) !== null;
        }

        return false;
    }

    public function restrict(Builder $query, User $user, string $permission, string $ownerColumn = 'owner_id', string $teamColumn = 'team_id'): Builder
    {
        $scope = $this->scopeFor($user, $permission);

        if ($scope === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'own') {
            return $query->where($ownerColumn, $user->id);
        }

        $teamIds = $user->teams()->pluck('teams.id');
        $memberIds = $teamIds->isEmpty()
            ? collect([$user->id])
            : User::query()
                ->whereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $teamIds))
                ->pluck('id');

        return $query->where(function (Builder $inner) use ($ownerColumn, $teamColumn, $user, $teamIds, $memberIds) {
            $inner->where($ownerColumn, $user->id)
                ->orWhereIn($ownerColumn, $memberIds);

            if ($teamIds->isNotEmpty()) {
                $inner->orWhereIn($teamColumn, $teamIds);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function grants(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $rank = ['own' => 1, 'team' => 2, 'all' => 3];
        $grants = [];

        $roles = $user->relationLoaded('roles')
            ? $user->roles
            : $user->roles()->with('permissions')->get();

        if ($roles->isEmpty() && is_string($user->role) && $user->role !== '') {
            $fallback = Role::query()->where('key', $user->role)->with('permissions')->first();
            $roles = $fallback ? collect([$fallback]) : collect();
        }

        foreach ($roles as $role) {
            $permissions = $role->relationLoaded('permissions')
                ? $role->permissions
                : $role->permissions()->get();

            foreach ($permissions as $permission) {
                $scope = (string) $permission->pivot->scope;
                $current = $grants[$permission->key] ?? null;

                if ($current === null || ($rank[$scope] ?? 0) > ($rank[$current] ?? 0)) {
                    $grants[$permission->key] = $scope;
                }
            }
        }

        return $this->cache[$user->id] = $grants;
    }

    public function forget(User $user): void
    {
        unset($this->cache[$user->id]);
    }
}
