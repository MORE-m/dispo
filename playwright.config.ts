import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    use: {
        baseURL: 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command:
            "cp .env.example .env 2>/dev/null; php artisan key:generate --force; mkdir -p database; touch database/database.sqlite; php -r \"file_put_contents('.env', preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', preg_replace('/^DB_DATABASE=.*/m', 'DB_DATABASE=database/database.sqlite', file_get_contents('.env'))));\"; php artisan migrate --force; php artisan db:seed --class=E2ECalculationSeeder --force; php artisan serve --host=127.0.0.1 --port=8000",
        url: 'http://127.0.0.1:8000/health',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
