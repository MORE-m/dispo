import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * BL-P9-01a isolierte E2E-Suite: Kundenbestätigungs-Upload.
 * Eigene SQLite-DB, Port 8040.
 */
const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e-bl-p9-01a.sqlite',
);

const e2ePort = process.env.E2E_BLP901A_PORT ?? '8040';
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

const e2eEnv = {
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    APP_KEY:
        process.env.APP_KEY ??
        'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
};

const prepareAssets = process.env.CI
    ? 'test -d public/build/assets || npm run build'
    : 'npm run build';

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/bl-p9-01a-*.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 90_000,
    use: {
        baseURL: e2eBaseUrl,
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium-blp901a',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `${prepareAssets} && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2ECustomerConfirmationUploadSeeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
