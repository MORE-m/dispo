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
        '**/adv001c3b1-*.spec.ts',
        '**/adv001c3b2-*.spec.ts',
        '**/adv001c3c-*.spec.ts',
        '**/adv001c4b-*.spec.ts',
        '**/df3restb-*.spec.ts',
        '**/df3restc-*.spec.ts',
        '**/df3rulec-*.spec.ts',
        '**/bl-p2-01a-*.spec.ts',
        '**/bl-p4-01a-*.spec.ts',
        '**/bl-p4-01b-*.spec.ts',
        '**/bl-p4-01c-*.spec.ts',
        '**/bl-p4-02b-*.spec.ts',
        '**/bl-p4-02c-*.spec.ts',
        '**/bl-p4-02d-*.spec.ts',
        '**/bl-p4-02e-*.spec.ts',
        '**/spt-008-*.spec.ts',
        '**/dsp-dcp-001-*.spec.ts',
        '**/bl-p8-02a-*.spec.ts',
        '**/bl-p8-02b-*.spec.ts',
        '**/bl-p8-02c-*.spec.ts',
        '**/bl-p8-02d-*.spec.ts',
        '**/bl-p8-02e-*.spec.ts',
    ],
    // Hauptsuite: seriell (u. a. DF-3.2a mutiert Feldsets). DF-3.2b / DF-3.3-fs /
    // DF-3.3a2α / DF-3.3a2β / DF-3.3b / ADV-001b / ADV-001c3a / ADV-001c3b1 /
    // ADV-001c3b2 / ADV-001c3c / ADV-001c4b / DF-3-REST-B / DF-3-REST-C /
    // DF-3-RULE-C / BL-P2-01a / BL-P4-01a / BL-P4-01b / BL-P4-01c / BL-P4-02b /
    // BL-P4-02c / BL-P4-02d / BL-P4-02e / SPT-008 / DSP-DCP-001 / BL-P8-02a /
    // BL-P8-02b / BL-P8-02c / BL-P8-02d / BL-P8-02e
    // laufen separat mit eigener DB/Port.
    // BL-P2-01a darf nicht in der Hauptsuite laufen: der Freeze-Test benennt
    // das Seed-Inventar „Radio Hamburg“ um und würde sonst CAL-001/BUD-00*
    // (Label-Select) zerstören.
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
