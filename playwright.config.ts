import { defineConfig, devices } from '@playwright/test';

const database = '/tmp/fibes-shell.sqlite';

const env = {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: 'http://127.0.0.1:8099',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    LOG_CHANNEL: 'stderr',
};

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL: 'http://127.0.0.1:8099',
        trace: 'off',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8099',
        url: 'http://127.0.0.1:8099/login',
        reuseExistingServer: false,
        timeout: 120_000,
        env,
    },
});
