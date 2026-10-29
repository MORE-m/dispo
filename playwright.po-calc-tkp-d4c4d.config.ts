import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * PO-CALC-TKP-1D4c-4d isolierte Admin-UI Browser-Smoke.
 * Eigene SQLite-DB, Port 8077 — niemals Dev-DB `dispo` / `dispo_mat_core`.
 */
const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e-po-calc-tkp-d4c4d.sqlite',
);

const e2ePort = process.env.E2E_PO_CALC_TKP_D4C4D_PORT ?? '8077';
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

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/po-calc-tkp-d4c4d-*.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 240_000,
    expect: {
        timeout: 30_000,
    },
    outputDir: 'test-results/po-calc-tkp-d4c4d',
    use: {
        baseURL: e2eBaseUrl,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 60_000,
        navigationTimeout: 60_000,
    },
    projects: [
        {
            name: 'chromium-po-calc-tkp-d4c4d',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `test -d public/build/assets || npm run build; mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2EOaTkpAdminUiSeeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 360_000,
        env: e2eEnv,
    },
});
