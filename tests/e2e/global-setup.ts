import { execSync } from 'node:child_process';

const database = '/tmp/fibes-shell.sqlite';

export default function globalSetup(): void {
    const env = {
        ...process.env,
        APP_ENV: 'local',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        SESSION_DRIVER: 'file',
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'sync',
    };

    execSync(`rm -f ${database} && php artisan migrate:fresh --force --seed && php tests/e2e/prepare.php`, {
        env,
        stdio: 'inherit',
    });
}
