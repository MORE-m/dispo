import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * NATIVE-5: Release-Smoke Native-Ad als Freie Preisposition (isoliert, Port 8080).
 * Registry-CATALOG free_position×free_position = released/v1 – kein E2E-Fake.
 * Fixture-JSON schreibt E2EFreePositionNative5Seeder.
 */
const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
);
const fixturePath = path.join(
    root,
    'database/e2e-free-position-native-5-release.json',
);
const e2eDb = path.join(
    root,
    'database/e2e-free-position-native-5-release.sqlite',
);

type InventoryFixture = {
    id: number;
    code: string;
    name: string;
    has_native_rule: boolean;
    bookable: boolean;
    planning_responsibility_key: string | null;
    booking_code: string | null;
};

type Fixture = {
    medium: { id: number; code: string; name: string; bookable: boolean };
    inventories: {
        historical: InventoryFixture[];
        podcast: InventoryFixture;
        more_oa: InventoryFixture;
    };
    registry: {
        catalog_pair_status: string;
        current_released_version: string;
        e2e_fake_released: boolean;
    };
    active_rule_count: number;
};

function loadFixture(): Fixture {
    return JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
}

function sqliteScalar(
    sql: string,
    params: Array<string | number> = [],
): string {
    const php = [
        '$pdo = new PDO("sqlite:" . getenv("FP5_E2E_DB"));',
        '$st = $pdo->prepare(getenv("FP5_E2E_SQL"));',
        '$params = json_decode(getenv("FP5_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            FP5_E2E_DB: e2eDb,
            FP5_E2E_SQL: sql,
            FP5_E2E_PARAMS: JSON.stringify(params),
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

/** Login, neue Kalkulation, Inventar + Native-Ad wählen; Free-Preisfeld sichtbar. */
async function startFreePosition(
    page: Page,
    customer: string,
    inventoryCode: string,
) {
    const fixture = loadFixture();
    await login(page);
    await openNewCalculationStepTwo(page, customer);
    await selectInventory(page, inventoryCode);
    await page
        .locator('[data-test="position-medium-0"]')
        .selectOption({ label: fixture.medium.name });
    await expect(
        page.locator('[data-test="position-free-price-0"]'),
    ).toBeVisible();
}

async function addPositionDiscount(page: Page, percent: string) {
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page
        .locator('[data-test="positions.0.position_discounts-add"]')
        .click();
    await page
        .locator('[data-test="positions.0.position_discounts-percent-0"]')
        .fill(percent);
    await waitForPreview(page);
}

async function addOrderDiscount(page: Page, percent: string) {
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.locator('[data-test="order_discounts-add"]').click();
    await page
        .locator('[data-test="order_discounts-type-0"]')
        .selectOption('quantity');
    await page.locator('[data-test="order_discounts-percent-0"]').fill(percent);
    await waitForPreview(page);
}

async function saveCalculation(page: Page): Promise<number> {
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

function dispoCol(orderId: number, column: string): string {
    return sqliteScalar(
        `select cast(${column} as text) from dispo_order_positions where dispo_order_id=? order by sort limit 1`,
        [orderId],
    );
}

async function reopenStepTwo(page: Page) {
    await page.reload();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

test.describe.configure({ mode: 'serial' });

test.describe('NATIVE-5 Free-Position Release Browser Smoke', () => {
    test('E2E-1 Isolation, Registry released/v1 ohne Fake und Katalog-Fixture', async () => {
        expect(e2eDb.includes('dispo_mat_core')).toBe(false);
        expect(process.env.DB_DATABASE ?? '').not.toBe('dispo_mat_core');

        const fixture = loadFixture();
        // Registry-CATALOG (Seeder liest per Reflection) – kein Fake.
        expect(fixture.registry.catalog_pair_status).toBe('released');
        expect(fixture.registry.current_released_version).toBe('v1');
        expect(fixture.registry.e2e_fake_released).toBe(false);
        expect(process.env.E2E_FREE_POSITION_PAIR_RELEASED ?? '').toBe('');

        expect(fixture.medium.code).toBe('native_ad');
        expect(fixture.medium.bookable).toBe(true);
        expect(fixture.active_rule_count).toBe(5);

        expect(fixture.inventories.historical).toHaveLength(4);
        for (const inventory of fixture.inventories.historical) {
            expect(inventory.has_native_rule, inventory.code).toBe(true);
            expect(inventory.bookable, inventory.code).toBe(true);
            expect(inventory.planning_responsibility_key).toBe('redaktion');
        }
        expect(fixture.inventories.podcast.code).toBe('inv_more_kombi_podcast');
        expect(fixture.inventories.podcast.bookable).toBe(true);
        expect(fixture.inventories.podcast.planning_responsibility_key).toBe(
            'redaktion',
        );
        expect(fixture.inventories.more_oa.code).toBe(
            'inv_more_kombi_online_audio',
        );
        expect(fixture.inventories.more_oa.has_native_rule).toBe(false);
        expect(fixture.inventories.more_oa.bookable).toBe(false);

        // Podcast×Native-Ad längenneutral; Position später length_seconds=0.
        expect(
            sqliteScalar(
                `select default_length_seconds from inventory_medium_rules where advertising_medium_id=? and inventory_id=?`,
                [fixture.medium.id, fixture.inventories.podcast.id],
            ),
        ).toBe('');

        // DB-Gegenprobe: genau 5 aktive Native-Ad-Rules, keine MORE-OA-Rule.
        expect(
            Number(
                sqliteScalar(
                    `select count(*) from inventory_medium_rules where advertising_medium_id=? and is_active=1`,
                    [fixture.medium.id],
                ),
            ),
        ).toBe(5);
        expect(
            Number(
                sqliteScalar(
                    `select count(*) from inventory_medium_rules where advertising_medium_id=? and inventory_id=?`,
                    [fixture.medium.id, fixture.inventories.more_oa.id],
                ),
            ),
        ).toBe(0);
    });

    test('E2E-2 UI: Freier Preis sichtbar, Länge/Spots/OA/TKP/Planer nicht', async ({
        page,
    }) => {
        const fixture = loadFixture();
        await startFreePosition(
            page,
            'NATIVE-5 Editor GmbH',
            fixture.inventories.historical[0].code,
        );

        await expect(
            page.locator('[data-test="position-free-price-0"]'),
        ).toHaveAttribute('placeholder', '0,00 €');

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
            page.locator('[data-test^="position-total-spot-count"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test^="position-planner"]'),
        ).toHaveCount(0);
        await expect(
            page.locator(
                '[data-test="pricing-settlement-radio-0-fixed_price"]',
            ),
        ).toHaveCount(0);
    });

    test('E2E-3 Hauptfall 5000 / 10 % Position / 20 % Auftrag / AE 15 % → N/N 3.060', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const fixture = loadFixture();
        await startFreePosition(
            page,
            'NATIVE-5 Hauptfall GmbH',
            fixture.inventories.historical[0].code,
        );

        await page.locator('[data-test="position-free-price-0"]').fill('5000');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="free-position-media-gross-0"]'),
        ).toContainText(/5\.000,00\s*€/);

        await addPositionDiscount(page, '10');
        await expect(page.getByText(/4\.500,00\s*€/).first()).toBeVisible();

        await addOrderDiscount(page, '20');
        await page.locator('[data-test="ae-enabled"]').click();
        await waitForPreview(page);

        // Preview: 5000 − 10 % = 4500 − 20 % = 3600 − 15 % AE = 3060.
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(/3\.060,00\s*€/);

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);

        expect(posCol(positionId, 'engine_profile_key')).toBe('free_position');
        expect(posCol(positionId, 'calculation_method_key')).toBe(
            'free_position',
        );
        expect(posCol(positionId, 'algorithm_version')).toBe('v1');
        expect(Number(posCol(positionId, 'media_gross'))).toBe(5000);
        expect(Number(posCol(positionId, 'ae_percent'))).toBe(15);
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(3060);
        expect(
            Number(
                sqliteScalar(
                    'select cast(nn_invest as text) from calculations where id=?',
                    [calcId],
                ),
            ),
        ).toBe(3060);

        // Reload: Preis, Rabatte, AE und Preview-Summe bleiben erhalten.
        await reopenStepTwo(page);
        await expect(
            page.locator('[data-test="position-free-price-0"]'),
        ).toHaveValue(/^(5\.000(,00)?|5000(\.00)?)$/);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await expect(
            page.locator(
                '[data-test="positions.0.position_discounts-percent-0"]',
            ),
        ).toHaveValue(/^10(\.0+)?$/);
        await expect(
            page.locator('[data-test="order_discounts-percent-0"]'),
        ).toHaveValue(/^20(\.0+)?$/);
        await expect(page.locator('[data-test="ae-enabled"]')).toBeChecked();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(/3\.060,00\s*€/);
    });

    test('E2E-4 Podcast-Position (MORE-Kombi Podcast) 2500: Save, Reload, Freeze redaktion', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const fixture = loadFixture();
        await startFreePosition(
            page,
            'NATIVE-5 Podcast GmbH',
            fixture.inventories.podcast.code,
        );

        await page.locator('[data-test="position-free-price-0"]').fill('2500');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="free-position-media-gross-0"]'),
        ).toContainText(/2\.500,00\s*€/);

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);

        expect(posCol(positionId, 'inventory_id')).toBe(
            String(fixture.inventories.podcast.id),
        );
        expect(posCol(positionId, 'advertising_medium_id')).toBe(
            String(fixture.medium.id),
        );
        expect(posCol(positionId, 'engine_profile_key')).toBe('free_position');
        expect(posCol(positionId, 'algorithm_version')).toBe('v1');
        expect(Number(posCol(positionId, 'media_gross'))).toBe(2500);
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(2500);
        // Freeze aus der Podcast×native_ad-Rule.
        expect(posCol(positionId, 'planning_responsibility_key')).toBe(
            'redaktion',
        );
        expect(posCol(positionId, 'booking_code')).toBe('Online Audio (UA)');

        await reopenStepTwo(page);
        await expect(
            page.locator('[data-test="position-medium-0"]'),
        ).toHaveValue(String(fixture.medium.id));
        await expect(
            page.locator('[data-test="position-free-price-0"]'),
        ).toHaveValue(/^(2\.500(,00)?|2500(\.00)?)$/);
    });

    test('E2E-5 MORE-Kombi Online Audio × Native-Ad wird nicht als buchbares Inventar angeboten', async ({
        page,
    }) => {
        const fixture = loadFixture();
        await startFreePosition(
            page,
            'NATIVE-5 MoreOA GmbH',
            fixture.inventories.historical[0].code,
        );

        const expand = page.locator(
            '[data-test="position-inventory-expand-0"]',
        );
        if (await expand.isVisible()) {
            await expand.click();
        }
        const tile = page.locator(
            `[data-test="position-inventory-tile-0-${fixture.inventories.more_oa.code}"]`,
        );
        await expect(tile).toBeVisible();
        await expect(tile).toBeDisabled();
        await expect(tile).toContainText('Derzeit nicht kalkulierbar');

        // Gegenprobe: Podcast-Kombi bleibt wählbar.
        await expect(
            page.locator(
                `[data-test="position-inventory-tile-0-${fixture.inventories.podcast.code}"]`,
            ),
        ).toBeEnabled();

        // Fail-closed in der DB: keine MORE-OA×native_ad-Rule.
        expect(
            Number(
                sqliteScalar(
                    `select count(*) from inventory_medium_rules where advertising_medium_id=? and inventory_id=?`,
                    [fixture.medium.id, fixture.inventories.more_oa.id],
                ),
            ),
        ).toBe(0);
    });

    test('E2E-6 Dispo-Create aus gespeicherter Free-Position: neutraler Freeze', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const fixture = loadFixture();
        const caravan = fixture.inventories.historical.find(
            (inventory) => inventory.code === 'inv_caravan_fm',
        );
        expect(caravan).toBeDefined();

        await startFreePosition(page, 'NATIVE-5 Dispo GmbH', caravan!.code);
        await page.locator('[data-test="position-free-price-0"]').fill('5000');
        await waitForPreview(page);
        await addPositionDiscount(page, '10');

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);

        await page.locator('[data-test="dispo-order-create-open"]').click();
        const dialog = page.locator('[data-test="dispo-order-create-dialog"]');
        await expect(dialog).toBeVisible({ timeout: 15_000 });

        const detail = page.locator(
            `[data-test="dispo-order-position-detail-${positionId}"]`,
        );
        await expect(detail).toContainText('Freie Preisposition');
        await expect(detail).toContainText(/5\.000,00\s*€/);
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

        // Freeze in der Dispo-Position: neutral, keine Spot-/OA-Scheinwerte.
        expect(dispoCol(orderId, 'kind')).toBe('free_position');
        expect(dispoCol(orderId, 'spot_method')).toBe('free_position');
        expect(dispoCol(orderId, 'engine_profile_key')).toBe('free_position');
        expect(dispoCol(orderId, 'calculation_method_key')).toBe(
            'free_position',
        );
        expect(dispoCol(orderId, 'algorithm_version')).toBe('v1');
        expect(Number(dispoCol(orderId, 'length_seconds'))).toBe(0);
        expect(Number(dispoCol(orderId, 'total_spot_count'))).toBe(0);
        expect(dispoCol(orderId, 'impressions')).toBe('');
        expect(Number(dispoCol(orderId, 'media_gross'))).toBe(5000);
        expect(Number(dispoCol(orderId, 'nn_invest'))).toBe(4500);
        expect(dispoCol(orderId, 'planning_responsibility_key')).toBe(
            'redaktion',
        );
        expect(dispoCol(orderId, 'booking_code')).toBe('UC');
    });
});
