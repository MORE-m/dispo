import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

/**
 * NATIVE-4 Free-Position-Dispo-Smoke: Freie Preisposition in Dispo-Create/-Show.
 * Isolierte SQLite, Port 8080 (Seeder wie NATIVE-3). Kein DB_DATABASE=dispo_mat_core. retries=0.
 *
 * Das Pair free_position×free_position bleibt im Registry-CATALOG planned/unreleased.
 * Der Release wird nur im E2E-Server per Test-Fake simuliert
 * (E2E_FREE_POSITION_PAIR_RELEASED=1, nur mit APP_ENV=testing + E2E_SERVER=1).
 */
const root = path.dirname(fileURLToPath(import.meta.url));
const e2eDb = path.resolve(
    root,
    'database/e2e-po-free-position-native-4.sqlite',
);
const e2ePort = process.env.E2E_PO_FREE_POSITION_NATIVE_4_PORT ?? '8080';
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

if (
    e2eDb.includes('dispo_mat_core') ||
    (process.env.DB_DATABASE ?? '').includes('dispo_mat_core') ||
    (process.env.DB_URL ?? '').includes('dispo_mat_core')
) {
    throw new Error(
        'E2E Free-Position-Smoke Abbruch: DB zeigt auf dispo_mat_core – isolierte SQLite erforderlich.',
    );
}

const e2eEnv = {
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    APP_KEY:
        process.env.APP_KEY ??
        'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
    OA_TKP_WIZARD_CAPABILITY: 'false',
    // Test-only Registry-Fake (nicht CATALOG): free_position×free_position released/v1.
    E2E_FREE_POSITION_PAIR_RELEASED: '1',
};

export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/po-free-position-native-4.spec.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 180_000,
    expect: {
        timeout: 30_000,
    },
    outputDir: 'test-results/po-free-position-native-4',
    use: {
        baseURL: e2eBaseUrl,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 60_000,
        navigationTimeout: 60_000,
    },
    projects: [
        {
            name: 'chromium-po-free-position-native-4',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: `test -d public/build/assets || npm run build; mkdir -p database && rm -f "${e2eDb}" && touch "${e2eDb}" && echo "E2E_DB_ISOLATION APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=${e2eDb} PORT=${e2ePort}" && php artisan migrate --force && php artisan db:seed --class=E2EFreePositionNative3Seeder --force && php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
        url: `${e2eBaseUrl}/health`,
        reuseExistingServer: false,
        timeout: 300_000,
        env: e2eEnv,
    },
});
