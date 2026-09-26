import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * BL-P9-01c isolierte E2E-Suite: dynamische Datei-Felder.
 * Eigene SQLite-DB, Port 8042.
 */
const rootDir = path.dirname(fileURLToPath(import.meta.url));
const e2eDb = path.resolve(rootDir, 'database/e2e-bl-p9-01c.sqlite');
const serverHelper = path.resolve(
    rootDir,
    'tests/e2e/helpers/run-blp901c-server.sh',
);

const e2ePort = process.env.E2E_BLP901C_PORT ?? '8042';
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
    E2E_BLP901C_PORT: e2ePort,
    E2E_BLP901C_HOST: '127.0.0.1',
    E2E_BLP901C_UPLOAD_MAX_FILESIZE: '50M',
    E2E_BLP901C_POST_MAX_SIZE: '55M',
};

const prepareAssets = process.env.CI
    ? 'test -d public/build/assets || npm run build'
    : 'npm run build';

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/bl-p9-01c-*.spec.ts',
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
            name: 'chromium-blp901c',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `${prepareAssets} && mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && php artisan migrate --force && php artisan db:seed --class=E2EDynamicFieldUploadSeeder --force && bash "${serverHelper}"`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
