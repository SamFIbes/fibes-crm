<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

it('refuses to start on Render without the secrets', function () {
    $result = Process::env(['FIBES_APP_DIR' => base_path(), 'APP_KEY' => '', 'DB_HOST' => '', 'DATABASE_URL' => '', 'ADMIN_PASSWORD' => '', 'DB_PASSWORD' => ''])
        ->run(['sh', base_path('docker/render/start.sh')]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('must be set');
});

it('ships a single-container Render image that listens on PORT', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile.render'));
    $nginx = file_get_contents(base_path('docker/render/nginx.conf.template'));
    $supervisor = file_get_contents(base_path('docker/render/supervisord.conf'));

    expect($dockerfile)->toContain('AS render')->toContain('SESSION_SECURE_COOKIE=true')
        ->and($nginx)->toContain('listen ${PORT}')
        ->and($supervisor)->toContain('queue:work "${QUEUE_CONNECTION:-database}"')->toContain('schedule:work');
});

// Runs only when a throwaway PostgreSQL is given, for example:
// FIBES_TEST_PGSQL="host=127.0.0.1;port=54331;dbname=fibes_test;user=fibes;password=" php artisan test
it('migrates on PostgreSQL with or without PostGIS', function () {
    $dsn = (string) getenv('FIBES_TEST_PGSQL');
    if ($dsn === '') {
        $this->markTestSkipped('Set FIBES_TEST_PGSQL to a throwaway PostgreSQL to run this.');
    }
    parse_str(str_replace(';', '&', $dsn), $p);
    config(['database.connections.pgtest' => array_merge(config('database.connections.pgsql'), [
        'host' => $p['host'] ?? '127.0.0.1', 'port' => $p['port'] ?? 5432, 'database' => $p['dbname'] ?? 'fibes_test',
        'username' => $p['user'] ?? 'postgres', 'password' => $p['password'] ?? '',
    ])]);

    expect(Artisan::call('migrate:fresh', ['--database' => 'pgtest', '--force' => true]))->toBe(0)
        ->and(Schema::connection('pgtest')->hasTable('coverage_items'))->toBeTrue();

    $postgis = DB::connection('pgtest')->selectOne("select 1 as ok from pg_available_extensions where name = 'postgis'") !== null;
    expect(Schema::connection('pgtest')->hasColumn('coverage_items', 'geom'))->toBe($postgis);

    Artisan::call('db:wipe', ['--database' => 'pgtest', '--force' => true]);
});

it('runs the queue worker on the same connection the app sends jobs to', function () {
    $supervisor = (string) file_get_contents(base_path('docker/render/supervisord.conf'));
    preg_match('/^\[program:queue\]\n(?:.*\n)*?command=(.+)$/m', $supervisor, $match);
    $command = $match[1] ?? '';

    expect($command)->toContain('exec php /var/www/html/artisan queue:work "${QUEUE_CONNECTION:-database}"')
        ->and($command)->not->toContain('queue:work database')
        ->and(config('queue.default'))->toBe(env('QUEUE_CONNECTION', 'database'));

    $dockerfile = (string) file_get_contents(base_path('Dockerfile.render'));
    expect($dockerfile)->toContain('QUEUE_CONNECTION=database');

    $shell = trim((string) shell_exec('QUEUE_CONNECTION= sh -c \'echo "${QUEUE_CONNECTION:-database}"\''));
    expect($shell)->toBe('database');
});
