import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * ADV-001c3b2 isolierte E2E-Suite (eigene SQLite-DB, Port 8010).
 */
const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e-adv001c3b2.sqlite',
);

const e2ePort = process.env.E2E_ADV001C3B2_PORT ?? '8010';
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

const e2eEnv = {
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
};

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/adv001c3b2-*.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    use: {
        baseURL: e2eBaseUrl,
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium-adv001c3b2',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `npm run build && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2ECalculationSeeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 120_000,
        env: e2eEnv,
    },
});
