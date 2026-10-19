import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * PO-SWF-PROMO-1 isolierte E2E-Suite: Promo/Moderation × Average (20 s / +50 %).
 * Eigene SQLite-DB, Port 8070 (nicht 8000 / Dev-DB). retries immer 0.
 */
const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'database/e2e-po-swf-promo-1.sqlite',
);

const e2ePort = process.env.E2E_PO_SWF_PROMO_1_PORT ?? '8070';
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
    testMatch: '**/po-swf-promo-1-*.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    outputDir: 'test-results/po-swf-promo-1',
    use: {
        baseURL: e2eBaseUrl,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium-po-swf-promo-1',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `npm run build && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2ESwfPromoAverageSeeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
