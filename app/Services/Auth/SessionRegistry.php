<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Settings\CommercialSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionRegistry
{
    /**
     * @return Collection<int, object>
     */
    public function forUser(int $userId): Collection
    {
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function others(): Collection
    {
        return DB::table('sessions')
            ->whereNotNull('user_id')
            ->orderByDesc('last_activity')
            ->get();
    }

    public function find(string $id): ?object
    {
        return DB::table('sessions')->where('id', $id)->first();
    }

    public function delete(string $id): void
    {
        DB::table('sessions')->where('id', $id)->delete();
    }

    /**
     * @return list<string>
     */
    public function deleteOthers(int $userId, string $exceptId): array
    {
        $ids = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $exceptId)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('sessions')->whereIn('id', $ids)->delete();
        }

        return $ids;
    }

    public function deviceLabel(?string $agent): string
    {
        $agent = $agent ?? '';
        $browser = 'Browser';

        foreach (['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($agent, $needle)) {
                $browser = $name;
                break;
            }
        }

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac') => 'Mac',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };

        return trim($browser.' '.$platform);
    }

    /**
     * @return array{id: string, ip: mixed, device: string, last_active: string, current: bool, owner: array{name: string, email: string}|null}
     */
    public function present(object $row, string $currentId, bool $includeOwner = false): array
    {
        $owner = $includeOwner && isset($row->user_id)
            ? User::query()->find($row->user_id)
            : null;

        return [
            'id' => (string) $row->id,
            'ip' => $row->ip_address,
            'device' => $this->deviceLabel($row->user_agent),
            'last_active' => now()->createFromTimestamp((int) $row->last_activity)
                ->timezone(config('app.timezone'))
                ->format('d/m/Y H:i'),
            'current' => (string) $row->id === $currentId,
            'owner' => $owner ? [
                'name' => $owner->name,
                'email' => $owner->email,
            ] : null,
        ];
    }

    public function rememberLogin(Request $request): void
    {
        $request->session()->put('auth.started_at', now()->getTimestamp());
        $request->session()->put('auth.seen_at', now()->getTimestamp());
    }

    public function idleMinutes(?User $user = null): int
    {
        if ($user instanceof User && in_array($user->role, ['field_technician', 'installer'], true)) {
            return max(1, (int) app(CommercialSettings::class)->get('tech_idle_minutes'));
        }

        return (int) config('fibes.session.idle_minutes', 60);
    }

    public function recentlyConfirmed(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $window = max(1, (int) app(CommercialSettings::class)->get('sensitive_reauth_minutes')) * 60;
        $now = now()->getTimestamp();
        $at = $request->session()->get('auth.confirmed_at');

        if (is_numeric($at)) {
            return ($now - (int) $at) <= $window;
        }

        $started = $request->session()->get('auth.started_at');

        if (! is_numeric($started)) {
            return true;
        }

        return ($now - (int) $started) <= $window;
    }

    public function absoluteMinutes(): int
    {
        return (int) config('fibes.session.absolute_minutes', 720);
    }

    public function expired(Request $request): bool
    {
        $started = $request->session()->get('auth.started_at');
        $seen = $request->session()->get('auth.seen_at');
        $now = now()->getTimestamp();

        if (! is_numeric($started) || ! is_numeric($seen)) {
            return false;
        }

        if (($now - (int) $started) > ($this->absoluteMinutes() * 60)) {
            return true;
        }

        return ($now - (int) $seen) > ($this->idleMinutes($request->user()) * 60);
    }

    public function touch(Request $request): void
    {
        if (! $request->session()->has('auth.started_at')) {
            $request->session()->put('auth.started_at', now()->getTimestamp());
        }

        $request->session()->put('auth.seen_at', now()->getTimestamp());
    }

    public function shortId(string $id): string
    {
        return Str::substr($id, 0, 8);
    }
}
