<?php

use Illuminate\Support\Facades\Process;

it('refuses to deploy when this is not the server', function () {
    $result = Process::run(['sh', base_path('scripts/deploy.sh'), 'v9']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('Nothing was contacted');
});

it('puts the previous tag back when the health check fails', function () {
    $dir = sys_get_temp_dir().'/fibes-deploy-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/current-tag', 'v1');

    $result = Process::env([
        'FIBES_DEPLOY_SIMULATE' => '1',
        'FIBES_DEPLOY_STATE_DIR' => $dir,
        'FIBES_DEPLOY_HEALTH_URL' => 'http://127.0.0.1:9/health',
    ])->run(['sh', base_path('scripts/deploy.sh'), 'v2']);

    expect($result->exitCode())->toBe(1)
        ->and(trim((string) file_get_contents($dir.'/current-tag')))->toBe('v1')
        ->and(trim((string) file_get_contents($dir.'/previous-tag')))->toBe('v1');
});

it('keeps a simulated tag when health is not asked for', function () {
    $dir = sys_get_temp_dir().'/fibes-deploy-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/current-tag', 'v1');

    $result = Process::env([
        'FIBES_DEPLOY_SIMULATE' => '1',
        'FIBES_DEPLOY_STATE_DIR' => $dir,
        'FIBES_DEPLOY_HEALTH_URL' => '',
    ])->run(['sh', base_path('scripts/deploy.sh'), 'v2']);

    expect($result->exitCode())->toBe(0)
        ->and(trim((string) file_get_contents($dir.'/current-tag')))->toBe('v2');

    $back = Process::env([
        'FIBES_DEPLOY_SIMULATE' => '1',
        'FIBES_DEPLOY_STATE_DIR' => $dir,
    ])->run(['sh', base_path('scripts/rollback.sh')]);

    expect($back->exitCode())->toBe(0)
        ->and(trim((string) file_get_contents($dir.'/current-tag')))->toBe('v1');
});

it('prints disk and skips health when no url is set', function () {
    $result = Process::env([
        'FIBES_MONITOR_DISK' => sys_get_temp_dir(),
        'FIBES_HEALTH_URL' => '',
        'FIBES_TLS_HOST' => '',
    ])->run(['sh', base_path('scripts/monitor.sh')]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('disk')
        ->and($result->output())->toContain('health skipped');
});
