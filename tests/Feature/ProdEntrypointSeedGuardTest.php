<?php

use Illuminate\Support\Facades\Process;

/**
 * Boots docker/entrypoint.prod.sh in the sandbox from tests/shell/run-boot-prod.sh.
 *
 * @return array{exit: int, stdout: string, cmds: string}
 */
function bootProdEntrypoint(string $seed = 'unset', string $migrations = 'unset'): array
{
    $result = Process::timeout(25)->run(['sh', base_path('tests/shell/run-boot-prod.sh'), 'prod', $seed, $migrations]);

    expect($result->exitCode())->toBe(0, $result->errorOutput()."\n".$result->output());

    $output = $result->output();
    $part = function (string $start, string $end) use ($output): string {
        $from = strpos($output, $start);
        expect($from)->not->toBeFalse();
        $from += strlen($start);

        return $end === '' ? substr($output, $from) : substr($output, $from, strpos($output, $end, $from) - $from);
    };

    return [
        'exit' => (int) trim($part('---ENTRY-EXIT---', '---ENTRY-STDOUT---')),
        'stdout' => $part('---ENTRY-STDOUT---', '---ENTRY-STDERR---'),
        'cmds' => $part('---CMDS---', ''),
    ];
}

it('skips database seeding by default on the prod entrypoint', function () {
    $boot = bootProdEntrypoint();

    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->toContain('Database seeding skipped (FIBES_RUN_SEED is not true).')
        ->and($boot['cmds'])->not->toContain('db:seed')
        ->and(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('skips database seeding when FIBES_RUN_SEED=false on the prod entrypoint', function () {
    $boot = bootProdEntrypoint('false');

    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->toContain('Database seeding skipped (FIBES_RUN_SEED is not true).')
        ->and($boot['cmds'])->not->toContain('db:seed')
        ->and(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('seeds after migrating when FIBES_RUN_SEED=true on the prod entrypoint', function () {
    $boot = bootProdEntrypoint('true');

    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->not->toContain('Database seeding skipped')
        ->and(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1)
        ->and(strpos($boot['cmds'], 'artisan migrate'))->toBeLessThan(strpos($boot['cmds'], 'db:seed'));
});

it('still skips migrations and seeding on the prod entrypoint when FIBES_RUN_MIGRATIONS=false', function () {
    $boot = bootProdEntrypoint('true', 'false');

    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->toContain('Skipping migrations in this container.')
        ->and($boot['cmds'])->not->toContain('artisan migrate')
        ->and($boot['cmds'])->not->toContain('db:seed');
});
