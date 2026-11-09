<?php

namespace App\Services\Users;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings staff email addresses exported from Hub ERP (ERPNext) into the CRM as
 * switched-off accounts with no permissions. An administrator then turns each
 * person on and gives them roles in Admin > Users.
 *
 * Existing accounts are never touched, and nothing is mailed to anyone.
 */
class ErpNextUserImporter
{
    /**
     * The users.role fallback the admin screen also uses when no role is picked.
     * It is not a seeded role, so AccessGate grants it nothing.
     */
    public const LOWEST_ROLE = 'staff';

    public const SOURCE = 'erpnext';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Pulls email addresses out of a JSON or CSV export.
     * JSON may be a list of strings, a list of objects with an "email" key,
     * or an object with a "data" list (the shape ERPNext's REST API returns).
     *
     * @return list<string>
     */
    public function parse(string $contents): array
    {
        $contents = trim($contents);

        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $rows = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
            $emails = [];

            foreach ($rows as $row) {
                if (is_string($row)) {
                    $emails[] = $row;
                } elseif (is_array($row)) {
                    $value = $row['email'] ?? $row['name'] ?? null;

                    if (is_string($value)) {
                        $emails[] = $value;
                    }
                }
            }

            return $emails;
        }

        $emails = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            foreach (str_getcsv($line, ',', '"', '') as $cell) {
                if (is_string($cell) && str_contains($cell, '@')) {
                    $emails[] = $cell;
                }
            }
        }

        return $emails;
    }

    /**
     * @param  list<string>  $emails
     * @return array{created: int, skipped_existing: int, skipped_invalid: int, skipped_duplicate: int, skipped_domain: int, total: int}
     */
    public function import(array $emails, ?string $domain, bool $dryRun): array
    {
        $this->assertSafeToRun();

        $counts = [
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_invalid' => 0,
            'skipped_duplicate' => 0,
            'skipped_domain' => 0,
            'total' => count($emails),
        ];

        $domain = $domain !== null && $domain !== '' ? Str::lower(ltrim($domain, '@')) : null;
        $seen = [];
        $toCreate = [];

        foreach ($emails as $raw) {
            $email = Str::lower(trim((string) $raw));

            if (! $this->isValidEmail($email)) {
                $counts['skipped_invalid']++;

                continue;
            }

            if ($domain !== null && ! str_ends_with($email, '@'.$domain)) {
                $counts['skipped_domain']++;

                continue;
            }

            if (isset($seen[$email])) {
                $counts['skipped_duplicate']++;

                continue;
            }

            $seen[$email] = true;

            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                $counts['skipped_existing']++;

                continue;
            }

            $toCreate[] = $email;
        }

        $counts['created'] = count($toCreate);

        if ($dryRun || $toCreate === []) {
            return $counts;
        }

        DB::transaction(function () use ($toCreate): void {
            foreach ($toCreate as $email) {
                $user = new User;
                $user->forceFill([
                    'name' => $this->displayName($email),
                    'email' => $email,
                    // Random secret nobody ever sees; the account is also switched off.
                    'password' => Hash::make(Str::random(64)),
                    'role' => self::LOWEST_ROLE,
                    'is_active' => false,
                    'must_change_password' => true,
                    'force_two_factor' => false,
                    'email_verified_at' => null,
                ])->save();

                $this->audit->record('users.imported', null, [
                    'user_id' => $user->id,
                    'source' => self::SOURCE,
                    'is_active' => false,
                    'role' => self::LOWEST_ROLE,
                ]);
            }
        });

        return $counts;
    }

    /**
     * "jane.doe-smith" becomes "Jane Doe Smith". Only used because users.name is required.
     */
    public function displayName(string $email): string
    {
        $local = Str::before($email, '@');
        $words = preg_split('/[._\-+]+/', $local) ?: [];
        $words = array_values(array_filter($words, fn (string $word) => $word !== ''));
        $name = $words === [] ? $local : implode(' ', array_map(fn (string $word) => Str::ucfirst($word), $words));

        return Str::limit($name, 120, '');
    }

    private function isValidEmail(string $email): bool
    {
        return $email !== ''
            && strlen($email) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Stops before writing if the switched-off, no-permission guarantee would not hold.
     */
    private function assertSafeToRun(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            throw new RuntimeException('users.is_active is missing, so imported accounts could not be switched off.');
        }

        $lowest = Role::query()->where('key', self::LOWEST_ROLE)->first();

        if ($lowest instanceof Role && $lowest->permissions()->exists()) {
            throw new RuntimeException('A "'.self::LOWEST_ROLE.'" role with permissions exists, so imported accounts would not start with no access.');
        }
    }
}
