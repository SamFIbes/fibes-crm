<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

class SettingsStore
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function get(string $key, array $default = []): array
    {
        $row = Setting::query()->where('key', $key)->first();

        if (! $row) {
            return $default;
        }

        return array_replace_recursive($default, $row->value ?? []);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value, ?User $actor, ?Request $request = null): void
    {
        $existing = Setting::query()->where('key', $key)->first();

        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => $value,
            'updated_by' => $actor?->id,
        ]);

        $this->audit->record('settings.updated', $actor, [
            'key' => $key,
            'before' => $existing?->value,
            'after' => $value,
        ], $request);
    }

    public function ensureDefaults(): void
    {
        Setting::query()->firstOrCreate(['key' => 'company'], [
            'value' => [
                'name' => 'FIBES',
                'name_ar' => 'فايبس',
                'email' => '',
                'phone_e164' => '',
                'address_en' => '',
                'address_ar' => '',
            ],
            'updated_by' => null,
        ]);

        Setting::query()->firstOrCreate(['key' => 'sla'], [
            'value' => config('fibes.sla'),
            'updated_by' => null,
        ]);

        Setting::query()->firstOrCreate(['key' => 'escalation'], [
            'value' => config('fibes.escalation'),
            'updated_by' => null,
        ]);
    }
}
