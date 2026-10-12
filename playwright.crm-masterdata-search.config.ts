import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * CRM Stammdaten-Textsuche + Prüflisten-Labels.
 * Eigene SQLite-DB, Port 8066 — niemals Dev-DB `dispo` / Port 8000.
 */
const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e-crm-masterdata-search.sqlite',
);

const e2ePort = process.env.E2E_CRM_MASTERDATA_SEARCH_PORT ?? '8066';
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

// Für PDO-Hilfen in der Spec (nicht nur webServer.env).
process.env.CRM_MD_E2E_DB = e2eDb;

const e2eEnv = {
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    APP_KEY:
        process.env.APP_KEY && process.env.APP_KEY !== ''
            ? process.env.APP_KEY
            : 'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
    CRM_MD_E2E_DB: e2eDb,
};

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/crm-masterdata-search.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    use: {
        baseURL: e2eBaseUrl,
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium-crm-masterdata-search',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `npm run build && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2ECalculationSeeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
