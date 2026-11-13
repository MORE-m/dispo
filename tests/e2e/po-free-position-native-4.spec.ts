import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * NATIVE-4: Freie Preisposition in Dispo-Create/-Show (isoliert, Port 8080).
 * Pair bleibt im Registry-CATALOG planned; der E2E-Server simuliert den Release nur per Test-Fake.
 * Fixture-JSON schreibt E2EFreePositionNative3Seeder (geteilt mit NATIVE-3).
 */
const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
);
const fixturePath = path.join(root, 'database/e2e-free-position-native-3.json');
const e2eDb = path.join(root, 'database/e2e-po-free-position-native-4.sqlite');

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
        '$pdo = new PDO("sqlite:" . getenv("FP4_E2E_DB"));',
        '$st = $pdo->prepare(getenv("FP4_E2E_SQL"));',
        '$params = json_decode(getenv("FP4_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            FP4_E2E_DB: e2eDb,
            FP4_E2E_SQL: sql,
            FP4_E2E_PARAMS: JSON.stringify(params),
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

async function waitForPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 30_000,
    });
}

async function createFreeCalculation(
    page: Page,
    customer: string,
    price: string,
    discountPercent: string | null,
): Promise<number> {
    const fixture = loadFixture();
    await login(page);
    await page.goto('/kalkulationen/neu');
    await page.locator('[data-test="crm-customer-search"]').fill(customer);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();

    const expand = page.locator('[data-test="position-inventory-expand-0"]');
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(
            `[data-test="position-inventory-tile-0-${fixture.inventory.code}"]`,
        )
        .click();
    await page
        .locator('[data-test="position-medium-0"]')
        .selectOption({ label: fixture.medium.name });
    await expect(
        page.locator('[data-test="position-free-price-0"]'),
    ).toBeVisible();
    await page.locator('[data-test="position-free-price-0"]').fill(price);
    await waitForPreview(page);

    if (discountPercent !== null) {
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page
            .locator('[data-test="positions.0.position_discounts-add"]')
            .click();
        await page
            .locator('[data-test="positions.0.position_discounts-percent-0"]')
            .fill(discountPercent);
        await waitForPreview(page);
    }

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await waitForPreview(page);
    const saveResponse = page.waitForResponse(
        (response) => {
            const request = response.request();
            const pathname = new URL(request.url()).pathname;

            return (
                request.method() === 'POST' &&
                /\/kalkulationen\/?$/.test(pathname) &&
                response.status() < 400
            );
        },
        { timeout: 60_000 },
    );
    await page.locator('[data-test="wizard-save"]').click();
    await saveResponse;
    await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 60_000 });
    const calcId = Number(
        page.url().match(/\/kalkulationen\/(\d+)/)?.[1] ?? '0',
    );
    expect(calcId).toBeGreaterThan(0);

    return calcId;
}

function calcPositionId(calcId: number): number {
    const id = Number(
        sqliteScalar(
            'select id from calculation_positions where calculation_id=? order by sort limit 1',
            [calcId],
        ),
    );
    expect(id).toBeGreaterThan(0);

    return id;
}

function dispoCol(orderId: number, column: string): string {
    return sqliteScalar(
        `select cast(${column} as text) from dispo_order_positions where dispo_order_id=? order by sort limit 1`,
        [orderId],
    );
}

test.describe.configure({ mode: 'serial' });

test.describe('NATIVE-4 Free-Position Dispo Browser Smoke', () => {
    test('E2E-1 Isolation und Registry-Pair bleibt planned', async () => {
        expect(e2eDb.includes('dispo_mat_core')).toBe(false);
        expect(process.env.DB_DATABASE ?? '').not.toBe('dispo_mat_core');

        const fixture = loadFixture();
        expect(fixture.registry.catalog_pair_status).toBe('planned');
    });

    test('E2E-2 Free speichern → Dispo-Create zeigt Freie Preisposition → Übernahme → Show', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const calcId = await createFreeCalculation(
            page,
            'NATIVE-4 Dispo GmbH',
            '5000',
            '10',
        );
        const positionId = calcPositionId(calcId);

        await page.locator('[data-test="dispo-order-create-open"]').click();
        const dialog = page.locator('[data-test="dispo-order-create-dialog"]');
        await expect(dialog).toBeVisible({ timeout: 15_000 });

        const detail = page.locator(
            `[data-test="dispo-order-position-detail-${positionId}"]`,
        );
        await expect(detail).toContainText('Freie Preisposition');
        await expect(detail).toContainText(/5\.000,00\s*€/);
        await expect(detail).not.toContainText('Spots');
        await expect(detail).not.toContainText(/\b0\s*s\b/);
        await expect(detail).not.toContainText('Impressions');
        await expect(dialog).toContainText(/4\.500,00\s*€\s*N\/N/);

        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/dispoauftraege\/\d+/, {
            timeout: 30_000,
        });
        const orderId = Number(
            page.url().match(/dispoauftraege\/(\d+)/)?.[1] ?? '0',
        );
        expect(orderId).toBeGreaterThan(0);

        const meta = page.locator('[data-test="dispo-order-position-meta-0"]');
        await expect(meta).toBeVisible();
        await expect(meta).toHaveAttribute(
            'data-method-classification',
            'free_position',
        );
        await expect(meta).toContainText('Freie Preisposition');
        await expect(meta).toContainText(/5\.000,00\s*€/);
        await expect(meta).not.toContainText('Spots');
        await expect(meta).not.toContainText(/\b0\s*s\b/);
        await expect(meta).not.toContainText('Impressions');
        await expect(meta).not.toContainText('TKP');
        await expect(meta).not.toContainText('Preisliste');

        // Kein OA-Panel, keine Spot-Planung/-Komponenten für Free.
        await expect(
            page.locator('[data-test^="dispo-order-oa-tkp-"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-position-components-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test^="dispo-planner-entries-"]'),
        ).toHaveCount(0);

        // Geldwerte stehen eingefroren in der Show.
        await expect(page.getByText(/4\.500,00\s*€/).first()).toBeVisible();

        // Freeze in der DB: neutral, keine Spot-/OA-Scheinwerte.
        expect(dispoCol(orderId, 'engine_profile_key')).toBe('free_position');
        expect(dispoCol(orderId, 'calculation_method_key')).toBe(
            'free_position',
        );
        expect(dispoCol(orderId, 'kind')).toBe('free_position');
        expect(dispoCol(orderId, 'algorithm_version')).toBe('v1');
        expect(Number(dispoCol(orderId, 'media_gross'))).toBe(5000);
        expect(Number(dispoCol(orderId, 'position_discount_amount'))).toBe(500);
        expect(Number(dispoCol(orderId, 'nn_invest'))).toBe(4500);
        expect(Number(dispoCol(orderId, 'total_spot_count'))).toBe(0);
        expect(Number(dispoCol(orderId, 'length_seconds'))).toBe(0);
        expect(dispoCol(orderId, 'impressions')).toBe('');
        expect(dispoCol(orderId, 'final_tkp')).toBe('');
        expect(dispoCol(orderId, 'price_list_id')).toBe('');
        expect(
            Number(
                sqliteScalar(
                    "select count(*) from dispo_order_positions where dispo_order_id=? and line_role='production'",
                    [orderId],
                ),
            ),
        ).toBe(0);
    });
});
