<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

function runSmokeAgainstStub(string $mode): ProcessResult
{
    $port = 18000 + random_int(0, 999);
    $server = Process::env(['SMOKE_STUB' => $mode])
        ->start(['php', '-S', '127.0.0.1:'.$port, base_path('tests/fixtures/smoke-router.php')]);

    try {
        foreach (range(1, 50) as $ignored) {
            if (@fsockopen('127.0.0.1', $port)) {
                break;
            }
            usleep(100_000);
        }

        return Process::env([
            'SMOKE_BASE' => 'http://127.0.0.1:'.$port,
            'SMOKE_HTTP_BASE' => 'http://127.0.0.1:'.$port.'/_http',
            'SMOKE_TIMEOUT' => '5',
        ])->timeout(60)->run(['bash', base_path('scripts/smoke-check.sh'), 'smoke.test']);
    } finally {
        $server->stop();
    }
}

it('passes against a healthy host and treats a switched-off integration as a warning', function () {
    $result = runSmokeAgainstStub('good');

    expect($result->exitCode())->toBe(0, $result->output())
        ->and($result->output())->toContain('Failed: 0')
        ->and($result->output())->toContain('WARN  ERPNext webhook')
        ->and($result->output())->not->toContain('FAIL');
});

it('fails on a 5xx webhook, missing HSTS, a bad health check and a port in a redirect', function () {
    $result = runSmokeAgainstStub('bad');

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('FAIL  HSTS header missing')
        ->and($result->output())->toContain('FAIL  /health')
        ->and($result->output())->toContain('FAIL  /build redirects to http://smoke.test:8080/build/')
        ->and($result->output())->toContain('FAIL  web-form webhook (bad signature) should reject bad input with a 4xx, got 500');
});

it('is read-only and carries no credentials', function () {
    $script = (string) file_get_contents(base_path('scripts/smoke-check.sh'));

    expect($script)->not->toMatch('/-X\s+(PUT|PATCH|DELETE)/')
        ->and($script)->not->toMatch('/Authorization:|Bearer |--user|\\s-u\\s|password=/i')
        ->and($script)->not->toContain('--cookie-jar');
});
