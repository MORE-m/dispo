import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * SPT-008 isolierte E2E-Suite: Spotverteilungs-XLSX-Export.
 * Eigene SQLite-DB, Port 8033.
 */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)));
const e2eDb = path.resolve(root, 'database/e2e-spt-008.sqlite');
const e2ePort = process.env.E2E_SPT008_PORT ?? '8033';
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;
const serverWrapper = path.join(root, 'tests/e2e/helpers/run-spt008-server.sh');

const e2eEnv = {
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    APP_KEY:
        process.env.APP_KEY ??
        'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
    E2E_SPT008_PORT: e2ePort,
    E2E_SPT008_DB: e2eDb,
};

const prepareAssets = process.env.CI
    ? 'test -d public/build/assets || npm run build'
    : 'npm run build';

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/spt-008-*.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 120_000,
    expect: {
        timeout: 30_000,
    },
    use: {
        baseURL: e2eBaseUrl,
        trace: 'on-first-retry',
        actionTimeout: 60_000,
        navigationTimeout: 60_000,
        acceptDownloads: true,
    },
    projects: [
        {
            name: 'chromium-spt-008',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `${prepareAssets} && chmod +x "${serverWrapper}" && "${serverWrapper}"`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
