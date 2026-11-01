<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, ?User $actor = null, array $metadata = [], ?Request $request = null): AuditLog
    {
        $metadata = $this->scrub($metadata);
        $agent = $request?->userAgent();

        return AuditLog::query()->create([
            'ulid' => (string) Str::ulid(),
            'occurred_at' => now(),
            'actor_id' => $actor?->id,
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => is_string($agent) ? Str::limit($agent, 500, '') : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'customer_id' => isset($metadata['customer_id']) && is_numeric($metadata['customer_id']) ? (int) $metadata['customer_id'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function scrub(array $metadata): array
    {
        $blocked = [
            'password',
            'password_confirmation',
            'current_password',
            'code',
            'secret',
            'token',
            'recovery_codes',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ];

        $clean = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = Str::limit($value, 500, '');

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
