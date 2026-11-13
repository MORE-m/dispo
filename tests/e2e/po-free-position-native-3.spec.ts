import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * NATIVE-3: Freie Preisposition im Kalkulationswizard (isoliert, Port 8079).
 * Pair bleibt im Registry-CATALOG planned; der E2E-Server simuliert den Release nur per Test-Fake.
 * Fixture-JSON schreibt E2EFreePositionNative3Seeder.
 */
const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
);
const fixturePath = path.join(root, 'database/e2e-free-position-native-3.json');
const e2eDb = path.join(root, 'database/e2e-po-free-position-native-3.sqlite');

type Fixture = {
    inventory: { id: number; code: string; name: string };
    medium: { id: number; code: string; name: string };
    registry: { catalog_pair_status: string; e2e_fake_released: boolean };
};

function loadFixture(): Fixture {
    return JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
}

function sqliteScalar(
    sql: string,
    params: Array<string | number> = [],
): string {
    const php = [
        '$pdo = new PDO("sqlite:" . getenv("FP3_E2E_DB"));',
        '$st = $pdo->prepare(getenv("FP3_E2E_SQL"));',
        '$params = json_decode(getenv("FP3_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            FP3_E2E_DB: e2eDb,
            FP3_E2E_SQL: sql,
            FP3_E2E_PARAMS: JSON.stringify(params),
        },
    }).trim();
}

async function login(page: Page, email = 'sales@example.com') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

async function openNewCalculationStepTwo(page: Page, customer: string) {
    await page.goto('/kalkulationen/neu');
    await page.locator('[data-test="crm-customer-search"]').fill(customer);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function selectInventory(page: Page, inventoryCode: string) {
    const expand = page.locator('[data-test="position-inventory-expand-0"]');
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(`[data-test="position-inventory-tile-0-${inventoryCode}"]`)
        .click();
}

async function selectMedium(page: Page, mediumName: string) {
    await page
        .locator('[data-test="position-medium-0"]')
        .selectOption({ label: mediumName });
}

async function waitForPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 30_000,
    });
}

async function startFreePosition(page: Page, customer: string) {
    const fixture = loadFixture();
    await login(page);
    await openNewCalculationStepTwo(page, customer);
    await selectInventory(page, fixture.inventory.code);
    await selectMedium(page, fixture.medium.name);
    await expect(
        page.locator('[data-test="position-free-price-0"]'),
    ).toBeVisible();
}

async function saveCalculation(page: Page): Promise<number> {
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await waitForPreview(page);
    const saveResponse = page.waitForResponse(
        (response) => {
            const request = response.request();
            const pathname = new URL(request.url()).pathname;
            const isCreate =
                request.method() === 'POST' &&
                /\/kalkulationen\/?$/.test(pathname);
            const isUpdate =
                request.method() === 'PUT' &&
                /\/kalkulationen\/\d+$/.test(pathname);

            return (isCreate || isUpdate) && response.status() < 400;
        },
        { timeout: 60_000 },
    );
    await page.locator('[data-test="wizard-save"]').click();
    await saveResponse;
    await expect(page.getByText('Kalkulation gespeichert').first()).toBeVisible(
        {
            timeout: 30_000,
        },
    );
    await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 60_000 });
    const calcId = Number(
        page.url().match(/\/kalkulationen\/(\d+)/)?.[1] ?? '0',
    );
    expect(calcId).toBeGreaterThan(0);

    return calcId;
}

function firstPositionId(calcId: number): number {
    const id = Number(
        sqliteScalar(
            'select id from calculation_positions where calculation_id=? order by sort limit 1',
            [calcId],
        ),
    );
    expect(id).toBeGreaterThan(0);

    return id;
}

function posCol(positionId: number, column: string): string {
    return sqliteScalar(
        `select cast(${column} as text) from calculation_positions where id=?`,
        [positionId],
    );
}

async function reopenStepTwo(page: Page) {
    await page.reload();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function addPositionDiscount(page: Page, percent: string) {
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page
        .locator('[data-test="positions.0.position_discounts-add"]')
        .click();
    await page
        .locator('[data-test="positions.0.position_discounts-percent-0"]')
        .fill(percent);
}

test.describe.configure({ mode: 'serial' });

test.describe('NATIVE-3 Free-Position Wizard Browser Smoke', () => {
    test('E2E-1 Isolation, Registry-Fake und Free-Editor ohne Spot/OA-Felder', async ({
        page,
    }) => {
        expect(e2eDb.includes('dispo_mat_core')).toBe(false);
        expect(process.env.DB_DATABASE ?? '').not.toBe('dispo_mat_core');

        const fixture = loadFixture();
        // CATALOG bleibt planned; nur der E2E-Server-Fake macht das Pair buchbar.
        expect(fixture.registry.catalog_pair_status).toBe('planned');

        await startFreePosition(page, 'NATIVE-3 Editor GmbH');

        await expect(
            page.locator('[data-test="position-free-price-0"]'),
        ).toHaveAttribute('placeholder', '0,00 €');
        await expect(page.getByLabel('Preis', { exact: true })).toBeVisible();

        // Ausgeblendet: Länge, Preisjahr, Spot-Planer, TKP/OA, Festpreis.
        await expect(
            page.locator('[data-test="position-length-seconds-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="position-price-year-0"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="range-spots-0-0"]')).toHaveCount(
            0,
        );
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toHaveCount(
            0,
        );
        await expect(page.locator('[data-test^="oa-tkp-"]')).toHaveCount(0);
        await expect(page.locator('[data-test^="oa-platform"]')).toHaveCount(0);
        await expect(
            page.locator(
                '[data-test="pricing-settlement-radio-0-fixed_price"]',
            ),
        ).toHaveCount(0);
        await expect(page.getByText('free_price')).toHaveCount(0);
    });

    test('E2E-2 Preis 5000 + 10 % Rabatt: Preview, Save, Reload', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await startFreePosition(page, 'NATIVE-3 Smoke GmbH');

        await page.locator('[data-test="position-free-price-0"]').fill('5000');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="free-position-media-gross-0"]'),
        ).toContainText(/5\.000,00\s*€/);

        await addPositionDiscount(page, '10');
        await waitForPreview(page);
        await expect(page.getByText(/4\.500,00\s*€/).first()).toBeVisible();

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);

        expect(posCol(positionId, 'engine_profile_key')).toBe('free_position');
        expect(posCol(positionId, 'calculation_method_key')).toBe(
            'free_position',
        );
        expect(posCol(positionId, 'algorithm_version')).toBe('v1');
        expect(Number(posCol(positionId, 'media_gross'))).toBe(5000);
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(4500);
        expect(Number(posCol(positionId, 'length_seconds'))).toBe(0);
        expect(
            Number(
                sqliteScalar(
                    'select cast(percent as text) from calculation_position_discounts where calculation_position_id=? limit 1',
                    [positionId],
                ),
            ),
        ).toBe(10);

        // Reload: Preis und Rabatt bleiben erhalten.
        await reopenStepTwo(page);
        await expect(
            page.locator('[data-test="position-medium-0"]'),
        ).toHaveValue(String(loadFixture().medium.id));
        await expect(
            page.locator('[data-test="position-free-price-0"]'),
        ).toHaveValue(/^(5\.000(,00)?|5000(\.00)?)$/);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await expect(
            page.locator(
                '[data-test="positions.0.position_discounts-percent-0"]',
            ),
        ).toHaveValue(/^10(\.0+)?$/);
    });

    test('E2E-3 Preis ändern (Update) und AE per Header-Toggle', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await startFreePosition(page, 'NATIVE-3 Update GmbH');
        await page
            .locator('[data-test="position-free-price-0"]')
            .fill('5.000,00');
        await waitForPreview(page);
        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);
        expect(Number(posCol(positionId, 'media_gross'))).toBe(5000);
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(5000);

        await reopenStepTwo(page);
        await page.locator('[data-test="position-free-price-0"]').fill('7000');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="ae-enabled"]').click();
        await waitForPreview(page);
        await saveCalculation(page);

        expect(Number(posCol(positionId, 'media_gross'))).toBe(7000);
        expect(Number(posCol(positionId, 'ae_percent'))).toBe(15);
        // 7000 − 15 % AE = 5950 (server-authoritativ).
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(5950);
    });

    test('E2E-4 Ungültiger Preis blockiert Preview mit Hinweis', async ({
        page,
    }) => {
        await startFreePosition(page, 'NATIVE-3 Invalid GmbH');

        await page.locator('[data-test="position-free-price-0"]').fill('0');
        await expect(
            page.getByRole('alert').filter({ hasText: /größer als 0/ }),
        ).toBeVisible();
        await page
            .locator('[data-test="position-free-price-0"]')
            .fill('12,345');
        await expect(
            page.getByRole('alert').filter({ hasText: /2 Nachkommastellen/ }),
        ).toBeVisible();
    });
});
