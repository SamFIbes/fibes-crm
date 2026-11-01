<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('fibes.admin.email');
        $password = config('fibes.admin.password');
        $name = (string) config('fibes.admin.name', 'FIBES Admin');
        $locale = (string) config('fibes.admin.locale', 'en');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command?->warn('ADMIN_EMAIL and ADMIN_PASSWORD are not set. Skipping the admin user.');

            return;
        }

        $email = strtolower(trim($email));

        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('ADMIN_EMAIL is not a valid email address.');
        }

        if (
            strlen($password) < 12
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
        ) {
            throw new RuntimeException('ADMIN_PASSWORD must be at least 12 characters and include upper case, lower case, and a number.');
        }

        if (! in_array($locale, config('fibes.locales', ['en', 'ar']), true)) {
            throw new RuntimeException('ADMIN_LOCALE must be en or ar.');
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'locale' => $locale,
                'is_active' => true,
                'email_verified_at' => now(),
                'role' => 'super_admin',
                'must_change_password' => true,
            ],
        );

        if ($admin->wasRecentlyCreated) {
            app(AuditLogger::class)->record('auth.admin.seeded', $admin, ['email' => $email]);
            $this->attachSuperAdmin($admin);
            $this->command?->info("Admin user {$email} created. They must choose a new password at first sign-in.");

            return;
        }

        $updates = [];

        if ($admin->role !== 'super_admin') {
            $updates['role'] = 'super_admin';
        }

        if (Hash::check($password, $admin->password)) {
            $updates['must_change_password'] = true;
        }

        if ($updates !== []) {
            $admin->forceFill($updates)->save();
        }

        $this->attachSuperAdmin($admin);
        $this->command?->info("Admin user {$email} already exists. Password was left unchanged.");
    }

    private function attachSuperAdmin(User $admin): void
    {
        $role = Role::query()->where('key', 'super_admin')->first();

        if ($role) {
            $admin->roles()->syncWithoutDetaching([$role->id]);
        }
    }
}
