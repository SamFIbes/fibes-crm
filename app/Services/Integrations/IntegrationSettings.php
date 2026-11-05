<?php

namespace App\Services\Integrations;

use App\Models\IntegrationAttempt;
use App\Models\User;
use App\Services\Settings\SettingsStore;
use Illuminate\Http\Request;

class IntegrationSettings
{
    /** @var list<string> */
    public const KEYS = ['erpnext', 'krypton', 'whish', 'grandstream', 'whatsapp'];

    public function __construct(private SettingsStore $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'erpnext' => [
                'enabled' => false,
                'url' => '',
                'company' => '',
                'warehouse' => '',
                'purpose' => 'Purchase',
                'warehouses' => [],
            ],
            'krypton' => ['enabled' => false, 'url' => ''],
            'whish' => ['enabled' => false, 'url' => ''],
            'grandstream' => ['enabled' => false, 'url' => ''],
            'whatsapp' => ['enabled' => false, 'phone_number_id' => ''],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        return $this->settings->get('integrations', $this->defaults());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(array $input, User $actor, Request $request): void
    {
        $clean = $this->defaults();

        foreach (self::KEYS as $key) {
            $row = is_array($input[$key] ?? null) ? $input[$key] : [];
            $clean[$key]['enabled'] = (bool) ($row['enabled'] ?? false);

            if (array_key_exists('url', $clean[$key])) {
                $clean[$key]['url'] = $this->plain($row['url'] ?? '', 200);
            }
        }

        $erp = is_array($input['erpnext'] ?? null) ? $input['erpnext'] : [];
        $clean['erpnext']['company'] = $this->plain($erp['company'] ?? '', 140);
        $clean['erpnext']['warehouse'] = $this->plain($erp['warehouse'] ?? '', 140);
        $purpose = $this->plain($erp['purpose'] ?? 'Purchase', 40);
        $clean['erpnext']['purpose'] = in_array($purpose, ['Purchase', 'Material Transfer', 'Material Issue'], true) ? $purpose : 'Purchase';
        $clean['erpnext']['warehouses'] = $this->warehouses(is_array($erp['warehouses'] ?? null) ? $erp['warehouses'] : []);
        $whatsapp = is_array($input['whatsapp'] ?? null) ? $input['whatsapp'] : [];
        $clean['whatsapp']['phone_number_id'] = $this->plain($whatsapp['phone_number_id'] ?? '', 64);

        $this->settings->put('integrations', $clean, $actor, $request);
    }

    public function company(): string
    {
        $stored = trim((string) ($this->stored()['erpnext']['company'] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        $env = trim((string) config('fibes.erpnext.company'));

        return $env !== '' ? $env : 'Smart Network';
    }

    public function purpose(): string
    {
        $purpose = (string) ($this->stored()['erpnext']['purpose'] ?? 'Purchase');

        return in_array($purpose, ['Purchase', 'Material Transfer', 'Material Issue'], true) ? $purpose : 'Purchase';
    }

    public function warehouseFor(?int $locationId): string
    {
        $default = trim((string) ($this->stored()['erpnext']['warehouse'] ?? ''));

        if ($locationId === null) {
            return $default;
        }

        foreach ($this->warehouseRows() as $row) {
            if ((int) $row['location_id'] === $locationId && $row['warehouse'] !== '') {
                return $row['warehouse'];
            }
        }

        return $default;
    }

    /**
     * @return array{live: bool, flag: bool, credentials: bool, desk: bool, reason: string, url: string, secrets: list<array{name: string, set: bool}>}
     */
    public function runtime(string $key): array
    {
        $stored = $this->stored()[$key] ?? [];
        $desk = (bool) ($stored['enabled'] ?? false);
        $secrets = $this->secrets($key);
        $credentials = $secrets !== [];

        foreach ($secrets as $secret) {
            if (! $secret['set']) {
                $credentials = false;
            }
        }
        $flag = $this->flag($key, $credentials);
        $live = $flag && $credentials && $desk;
        $reason = 'ready';

        if (! $credentials) {
            $reason = 'credentials';
        } elseif (! $flag) {
            $reason = 'flag';
        } elseif (! $desk) {
            $reason = 'desk';
        }

        return [
            'live' => $live,
            'flag' => $flag,
            'credentials' => $credentials,
            'desk' => $desk,
            'reason' => $reason,
            'url' => $this->url($key),
            'secrets' => $secrets,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        $stored = $this->stored();
        $cards = [];

        foreach (self::KEYS as $key) {
            $runtime = $this->runtime($key);
            $latest = IntegrationAttempt::query()->where('integration', $key)->latest('id')->first();
            $success = IntegrationAttempt::query()->where('integration', $key)->where('status', 'succeeded')->latest('id')->first();
            $status = 'never_connected';

            if ($latest instanceof IntegrationAttempt && $latest->status === 'failed') {
                $status = 'error';
            } elseif ($success instanceof IntegrationAttempt) {
                $status = 'synced';
            } elseif ($runtime['credentials']) {
                $status = 'configured';
            }

            $cards[] = [
                'key' => $key,
                'enabled' => (bool) ($stored[$key]['enabled'] ?? false),
                'url' => $runtime['url'],
                'company' => $key === 'erpnext' ? $this->company() : '',
                'warehouse' => (string) ($stored['erpnext']['warehouse'] ?? ''),
                'purpose' => $this->purpose(),
                'phone_number_id' => $this->phoneNumberId(),
                'warehouses' => $this->warehouseRows(),
                'live' => $runtime['live'],
                'flag' => $runtime['flag'],
                'credentials' => $runtime['credentials'],
                'secrets' => $runtime['secrets'],
                'reason' => $runtime['reason'],
                'status' => $status,
                'last_sync_at' => $success?->created_at?->toIso8601String(),
                'last_error' => $latest instanceof IntegrationAttempt && $latest->status === 'failed' ? $latest->message : null,
                'docs' => '#'.$key,
            ];
        }

        return $cards;
    }

    public function redact(string $message): string
    {
        return app(IntegrationRecorder::class)->redact($message);
    }

    public function phoneNumberId(): string
    {
        $stored = trim((string) ($this->stored()['whatsapp']['phone_number_id'] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('fibes.whatsapp.phone_number_id'));
    }

    /**
     * @return list<array{location_id: int, warehouse: string}>
     */
    private function warehouseRows(): array
    {
        $rows = $this->stored()['erpnext']['warehouses'] ?? [];

        return is_array($rows) ? $this->warehouses($rows) : [];
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{location_id: int, warehouse: string}>
     */
    private function warehouses(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['location_id'])) {
                continue;
            }

            $clean[] = [
                'location_id' => (int) $row['location_id'],
                'warehouse' => $this->plain($row['warehouse'] ?? '', 140),
            ];
        }

        return $clean;
    }

    private function url(string $key): string
    {
        $stored = trim((string) ($this->stored()[$key]['url'] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return match ($key) {
            'erpnext' => rtrim((string) config('fibes.erpnext.url'), '/'),
            'krypton' => (string) config('fibes.radius.url'),
            'whish' => (string) config('fibes.whish.url'),
            'grandstream' => (string) config('fibes.telephony.url'),
            default => '',
        };
    }

    /**
     * @return list<array{name: string, set: bool}>
     */
    private function secrets(string $key): array
    {
        return match ($key) {
            'erpnext' => [
                ['name' => 'FIBES_ERPNEXT_API_KEY', 'set' => trim((string) config('fibes.erpnext.api_key')) !== ''],
                ['name' => 'FIBES_ERPNEXT_API_SECRET', 'set' => trim((string) config('fibes.erpnext.api_secret')) !== ''],
            ],
            'krypton' => [
                ['name' => 'FIBES_RADIUS_API_KEY', 'set' => trim((string) config('fibes.radius.api_key')) !== ''],
            ],
            'whish' => [
                ['name' => 'FIBES_WHISH_API_KEY', 'set' => trim((string) config('fibes.whish.api_key')) !== ''],
            ],
            'grandstream' => [
                ['name' => 'FIBES_TELEPHONY_SECRET', 'set' => trim((string) config('fibes.telephony.secret')) !== ''],
            ],
            'whatsapp' => [
                ['name' => 'FIBES_WHATSAPP_TOKEN', 'set' => trim((string) config('fibes.whatsapp.token')) !== ''],
            ],
            default => [],
        };
    }

    private function flag(string $key, bool $credentials): bool
    {
        return match ($key) {
            'erpnext' => (bool) config('fibes.erpnext.enabled'),
            'krypton' => (bool) config('fibes.radius.enabled'),
            'whish' => (bool) config('fibes.whish.enabled'),
            'whatsapp' => (bool) config('fibes.whatsapp.enabled'),
            'grandstream' => $credentials,
            default => false,
        };
    }

    private function plain(mixed $value, int $limit): string
    {
        $text = trim((string) $value);

        return mb_substr($text, 0, $limit);
    }
}
