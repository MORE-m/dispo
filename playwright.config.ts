import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e.sqlite',
);

const e2ePort = process.env.E2E_PORT ?? '8001';
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
    testIgnore: [
        '**/df32b-*.spec.ts',
        '**/df33fs-*.spec.ts',
        '**/df33a2a-*.spec.ts',
        '**/df33a2b-*.spec.ts',
        '**/df33b-*.spec.ts',
        '**/adv001b-*.spec.ts',
        '**/adv001c3a-*.spec.ts',
    ],
    // Hauptsuite: seriell (u. a. DF-3.2a mutiert Feldsets). DF-3.2b / DF-3.3-fs /
    // DF-3.3a2α / DF-3.3a2β / DF-3.3b / ADV-001b / ADV-001c3a laufen separat
    // mit eigener DB/Port.
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
            name: 'chromium',
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
