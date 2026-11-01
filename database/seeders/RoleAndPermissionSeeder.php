<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Support\Access\Catalog;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];

        foreach (Catalog::permissions() as $key => $meta) {
            $permission = Permission::query()->updateOrCreate(['key' => $key], $meta);
            $permissionIds[$key] = $permission->id;
        }

        foreach (Catalog::roles() as $key => $meta) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name_en' => $meta['name_en'],
                    'name_ar' => $meta['name_ar'],
                    'is_builtin' => true,
                    'is_locked' => (bool) ($meta['locked'] ?? false),
                    'home_route' => '/dashboard',
                ],
            );

            $sync = [];

            foreach (Catalog::grants() as $permission => $roles) {
                if (! isset($roles[$key], $permissionIds[$permission])) {
                    continue;
                }

                $sync[$permissionIds[$permission]] = ['scope' => $roles[$key]];
            }

            $role->permissions()->sync($sync);
        }

        foreach (Catalog::teams() as $key => $meta) {
            Team::query()->updateOrCreate(['key' => $key], $meta);
        }
    }
}
