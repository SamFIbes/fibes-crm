<?php

use Illuminate\Support\Facades\Process;

function bootEntrypoint(string $which, string $seed = 'unset', string $migrations = 'unset'): array
{
    $result = Process::timeout(25)->run([
        'sh',
        base_path('tests/shell/run-boot.sh'),
        $which,
        $seed,
        $migrations,
    ]);

    expect($result->exitCode())->toBe(0, $result->errorOutput()."\n".$result->output());

    $output = $result->output();
    $exit = bootSection($output, '---ENTRY-EXIT---', '---ENTRY-STDOUT---');
    $stdout = bootSection($output, '---ENTRY-STDOUT---', '---ENTRY-STDERR---');
    $stderr = bootSection($output, '---ENTRY-STDERR---', '---CMDS---');
    $cmds = bootSection($output, '---CMDS---', '');

    return [
        'exit' => (int) trim($exit),
        'stdout' => $stdout,
        'stderr' => $stderr,
        'cmds' => $cmds,
    ];
}

function bootSection(string $output, string $start, string $end): string
{
    $from = strpos($output, $start);
    expect($from)->not->toBeFalse();
    $from += strlen($start);
    if ($end === '') {
        return substr($output, $from);
    }

    $to = strpos($output, $end, $from);
    expect($to)->not->toBeFalse();

    return substr($output, $from, $to - $from);
}

function assertSeedSkipped(array $boot): void
{
    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->toContain('Database seeding skipped (FIBES_RUN_SEED is not true).')
        ->and($boot['cmds'])->not->toContain('db:seed');
}

function assertSeeded(array $boot): void
{
    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->not->toContain('Database seeding skipped')
        ->and($boot['cmds'])->toContain('db:seed');
}

it('skips database seeding by default on the render entrypoint', function () {
    $boot = bootEntrypoint('render');

    assertSeedSkipped($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('skips database seeding when FIBES_RUN_SEED=false on the render entrypoint', function () {
    $boot = bootEntrypoint('render', 'false');

    assertSeedSkipped($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('seeds when FIBES_RUN_SEED=true on the render entrypoint', function () {
    $boot = bootEntrypoint('render', 'true');

    assertSeeded($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1)
        ->and(strpos($boot['cmds'], 'artisan migrate'))->toBeLessThan(strpos($boot['cmds'], 'db:seed'));
});

it('keeps render migrations running when the seed flag changes', function () {
    foreach (['unset', 'false', 'true'] as $seed) {
        $boot = bootEntrypoint('render', $seed, 'true');

        expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
    }
});

it('skips database seeding by default on the railway entrypoint', function () {
    $boot = bootEntrypoint('railway');

    assertSeedSkipped($boot);
    expect($boot['cmds'])->not->toContain('artisan migrate');
});

it('skips database seeding when FIBES_RUN_SEED=false on the railway entrypoint', function () {
    $boot = bootEntrypoint('railway', 'false', 'true');

    assertSeedSkipped($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('seeds when FIBES_RUN_SEED=true on the railway entrypoint', function () {
    $boot = bootEntrypoint('railway', 'true', 'false');

    assertSeeded($boot);
    expect($boot['cmds'])->not->toContain('artisan migrate')
        ->and($boot['stdout'])->toContain('Waiting for PostgreSQL');
});

it('keeps railway migrations tied to FIBES_RUN_MIGRATIONS', function () {
    $seedOff = bootEntrypoint('railway', 'false', 'true');
    $seedOn = bootEntrypoint('railway', 'true', 'true');
    $migrationsOff = bootEntrypoint('railway', 'true', 'false');
    $bothOff = bootEntrypoint('railway', 'unset', 'unset');

    expect(substr_count($seedOff['cmds'], 'artisan migrate'))->toBe(1)
        ->and($seedOff['cmds'])->not->toContain('db:seed')
        ->and(substr_count($seedOn['cmds'], 'artisan migrate'))->toBe(1)
        ->and($seedOn['cmds'])->toContain('db:seed')
        ->and(substr_count($seedOn['stdout'], 'Waiting for PostgreSQL'))->toBe(1)
        ->and($migrationsOff['cmds'])->not->toContain('artisan migrate')
        ->and($migrationsOff['cmds'])->toContain('db:seed')
        ->and($bothOff['cmds'])->not->toContain('artisan migrate')
        ->and($bothOff['cmds'])->not->toContain('db:seed');
});

it('skips database seeding by default on the prod entrypoint', function () {
    $boot = bootEntrypoint('prod');

    assertSeedSkipped($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('skips database seeding when FIBES_RUN_SEED=false on the prod entrypoint', function () {
    $boot = bootEntrypoint('prod', 'false');

    assertSeedSkipped($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1);
});

it('seeds when FIBES_RUN_SEED=true on the prod entrypoint', function () {
    $boot = bootEntrypoint('prod', 'true');

    assertSeeded($boot);
    expect(substr_count($boot['cmds'], 'artisan migrate'))->toBe(1)
        ->and(strpos($boot['cmds'], 'artisan migrate'))->toBeLessThan(strpos($boot['cmds'], 'db:seed'));
});

it('keeps the prod entrypoint skipping migrations and seeding when FIBES_RUN_MIGRATIONS=false', function () {
    $boot = bootEntrypoint('prod', 'true', 'false');

    expect($boot['exit'])->toBe(0)
        ->and($boot['stdout'])->toContain('Skipping migrations in this container.')
        ->and($boot['cmds'])->not->toContain('artisan migrate')
        ->and($boot['cmds'])->not->toContain('db:seed');
});
