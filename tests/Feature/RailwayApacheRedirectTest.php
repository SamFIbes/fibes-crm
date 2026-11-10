<?php

use Illuminate\Support\Facades\Process;

it('configures Apache to build redirects from the request, not its internal port', function () {
    $conf = (string) file_get_contents(base_path('docker/railway/apache.conf'));

    expect($conf)->toContain('UseCanonicalName Off')
        ->toContain('UseCanonicalPhysicalPort Off')
        ->toContain('SetEnvIf X-Forwarded-Proto "^https$" FIBES_FORWARDED_HTTPS')
        ->toContain('Header always edit Location')
        ->and((string) file_get_contents(base_path('Dockerfile.railway')))->toContain('headers')
        ->and(json_decode((string) file_get_contents(base_path('railway.json')), true)['build']['dockerfilePath'])->toBe('Dockerfile.railway');
});

it('keeps Apache redirects on https without the container port', function () {
    if (! Process::run(['docker', 'info'])->successful()) {
        $this->markTestSkipped('docker is not available here; CI runs this check.');
    }

    $result = Process::timeout(240)->run(['sh', base_path('tests/shell/apache-redirects.sh')]);
    expect($result->exitCode())->toBe(0, $result->errorOutput());

    $rows = collect(explode("\n", trim($result->output())))
        ->mapWithKeys(function (string $line) {
            [$case, $status, $location] = array_pad(explode('|', $line, 3), 3, '');

            return [$case => ['status' => $status, 'location' => $location]];
        });

    expect($rows['dir-https'])->toBe(['status' => '301', 'location' => 'https://www.fibes.io/build/'])
        ->and($rows['slash-https'])->toBe(['status' => '301', 'location' => 'https://www.fibes.io/storage'])
        ->and($rows['host-port-https'])->toBe(['status' => '301', 'location' => 'https://www.fibes.io/build/'])
        ->and($rows['dir-http']['location'])->toBe('http://www.fibes.io/build/')
        ->and($rows['app-https']['status'])->toBe('200');
});
