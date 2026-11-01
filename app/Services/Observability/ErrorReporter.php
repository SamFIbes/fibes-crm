<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Http;
use Throwable;

class ErrorReporter
{
    public function report(Throwable $error, ?string $requestId): void
    {
        $dsn = (string) config('fibes.observability.dsn');
        $parts = parse_url($dsn);

        if (! is_array($parts) || ! isset($parts['host'], $parts['user'], $parts['path'])) {
            return;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $project = trim((string) $parts['path'], '/');
        Http::timeout(2)
            ->withHeaders([
                'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=fibes, sentry_key='.$parts['user'],
            ])
            ->post($scheme.'://'.$parts['host'].$port.'/api/'.$project.'/store/', [
                'message' => RedactMessage::scrub($error),
                'level' => 'error',
                'tags' => ['request_id' => $requestId],
                'exception' => ['values' => [['type' => $error::class]]],
            ]);
    }
}
