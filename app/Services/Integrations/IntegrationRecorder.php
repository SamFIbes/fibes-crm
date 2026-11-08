<?php

namespace App\Services\Integrations;

use App\Models\IntegrationAttempt;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationRecorder
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function attempt(
        string $integration,
        string $action,
        string $status,
        string $message,
        ?User $actor = null,
        array $context = [],
        ?Request $request = null,
        int $attempt = 1,
    ): IntegrationAttempt {
        $message = $this->redact($message);
        $context = $this->scrub($context);

        $row = IntegrationAttempt::query()->create([
            'integration' => $integration,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'attempt' => max(1, $attempt),
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);

        $this->audit->record('integration.sync', $actor, [
            'integration' => $integration,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'attempt' => max(1, $attempt),
            'context' => $context,
        ], $request);

        return $row;
    }

    public function redact(string $message): string
    {
        foreach ([
            (string) config('fibes.erpnext.api_key'),
            (string) config('fibes.erpnext.api_secret'),
            (string) config('fibes.erpnext.webhook_secret'),
            (string) config('fibes.radius.api_key'),
            (string) config('fibes.whish.api_key'),
            (string) config('fibes.whish.webhook_secret'),
            (string) config('fibes.whatsapp.token'),
            (string) config('fibes.whatsapp.app_secret'),
            (string) config('fibes.whatsapp.verify_token'),
            (string) config('fibes.telephony.secret'),
            (string) config('fibes.telephony.password'),
        ] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        $message = preg_replace('/token\s+\S+/i', 'token [redacted]', $message) ?? $message;

        return Str::limit($message, 500, '');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);

            if (str_contains($name, 'secret') || str_contains($name, 'token') || str_contains($name, 'api_key') || str_contains($name, 'password') || str_contains($name, 'cookie') || $name === 'authorization') {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->redact($value);

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
