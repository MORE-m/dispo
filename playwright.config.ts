import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e.sqlite',
);

const e2eEnv = {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
};

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
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
        command: `npm run build && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2ECalculationSeeder --force && php artisan serve --host=127.0.0.1 --port=8000`,
        url: 'http://127.0.0.1:8000/health',
        reuseExistingServer: false,
        timeout: 120_000,
        env: e2eEnv,
    },
});
