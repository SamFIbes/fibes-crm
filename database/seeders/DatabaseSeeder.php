<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles, places, settings, and the first super admin only.
        // LoadSeeder is the 30,000-customer set and refuses production.
        $this->call([
            RoleAndPermissionSeeder::class,
            FoundationSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
