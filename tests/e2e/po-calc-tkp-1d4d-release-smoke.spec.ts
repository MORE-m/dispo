import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * PO-CALC-TKP-1D4d: Release-Smoke – echter oa_tkp×tkp released/v1 Browserpfad.
 * Capability=false, Preview-Surface=true, Mutation=true. Isoliert Port 8075.
 */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixturePath = path.join(
    root,
    'database/e2e-po-calc-tkp-1d4d-release-smoke.json',
);
const e2eDb = path.join(
    root,
    'database/e2e-po-calc-tkp-1d4d-release-smoke.sqlite',
);

type Fixture = {
    inventory: { id: number; code: string; name: string };
    priceList: { id: number; year: number; version: string };
    media: Record<string, { id: number; code: string; name: string }>;
    nativeAd: { id: number; code: string; name: string };
    targeting: {
        id: number;
        code: string;
        name: string;
        tkp_surcharge: string;
        minimum_tkp_surcharge: string;
    };
    release: {
        engine_profile_key: string;
        calculation_method_key: string;
        pair_status: string;
        current_released_version: string;
        algorithm_version: string;
    };
    signals: {
        oa_tkp_wizard_capability: boolean;
        oa_tkp_preview_surface_allowed: boolean;
        oa_tkp_http_mutation_allowed: boolean;
    };
    midroll: { list_tkp: string; minimum_tkp: string };
};

const coreCodes = [
    'pre_stream',
    'in_stream',
    'pre_stream_influencer',
    'in_stream_influencer',
    'midroll_music_environment',
] as const;

function loadFixture(): Fixture {
    return JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
}

function assertIsolatedDb(): void {
    expect(e2eDb.includes('dispo_mat_core')).toBe(false);
    expect(process.env.DB_DATABASE ?? '').not.toBe('dispo_mat_core');
}

function sqliteScalar(sql: string, params: Array<string | number> = []): string {
    const php = [
        '$pdo = new PDO("sqlite:" . getenv("D4D_E2E_DB"));',
        '$st = $pdo->prepare(getenv("D4D_E2E_SQL"));',
        '$params = json_decode(getenv("D4D_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            D4D_E2E_DB: e2eDb,
            D4D_E2E_SQL: sql,
            D4D_E2E_PARAMS: JSON.stringify(params),
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

async function assertNoPlatformUi(page: Page) {
    // Keine Endnutzer-Plattformwahl (Bundle bleibt Medium-Name, nicht Allocation-UI).
    await expect(page.locator('[data-test^="oa-platform"]')).toHaveCount(0);
    await expect(page.locator('[data-test*="platform-allocation"]')).toHaveCount(0);
    await expect(
        page.getByRole('checkbox', { name: /^(Spotify|YouTube|Youtube|Deezer)$/i }),
    ).toHaveCount(0);
}

test.describe.configure({ mode: 'serial' });

test.describe('PO-CALC-TKP-1D4d Release Browser Smoke', () => {
    test('Fixture, Isolation und released Signale', async () => {
        assertIsolatedDb();
        const fixture = loadFixture();
        expect(fixture.release.pair_status).toBe('released');
        expect(fixture.release.current_released_version).toBe('v1');
        expect(fixture.release.engine_profile_key).toBe('oa_tkp');
        expect(fixture.release.calculation_method_key).toBe('tkp');
        expect(fixture.signals.oa_tkp_wizard_capability).toBe(false);
        expect(fixture.signals.oa_tkp_preview_surface_allowed).toBe(true);
        expect(fixture.signals.oa_tkp_http_mutation_allowed).toBe(true);
        expect(fixture.midroll.list_tkp).toBe('80.0000');
        expect(fixture.midroll.minimum_tkp).toBe('20.0000');
        expect(fixture.targeting.tkp_surcharge).toBe('25.0000');
        expect(sqliteScalar('select count(*) from oa_tkp_price_lists')).toBe('1');
        expect(sqliteScalar('select count(*) from oa_tkp_price_list_items')).toBe(
            '5',
        );
        expect(
            sqliteScalar(
                "select list_tkp from oa_tkp_price_list_items where advertising_medium_id=?",
                [fixture.media.midroll_music_environment.id],
            ),
        ).toMatch(/^80/);
    });

    test('Inertia-Props: Capability false, Preview+Mutation true', async ({
        page,
    }) => {
        await login(page);
        const response = await page.goto('/kalkulationen/neu');
        expect(response?.ok()).toBeTruthy();
        const script = page.locator('script[data-page][type="application/json"]');
        await expect(script).toHaveCount(1);
        const raw = await script.textContent();
        expect(raw).toBeTruthy();
        const props = JSON.parse(raw!).props as Record<string, unknown>;
        expect(props.oa_tkp_wizard_capability).toBe(false);
        expect(props.oa_tkp_preview_surface_allowed).toBe(true);
        expect(props.oa_tkp_http_mutation_allowed).toBe(true);
    });

    test('Midroll Hauptflow: Metadata, DMP, Preview, Save, Reload, Freeze', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const fixture = loadFixture();
        const midroll = fixture.media.midroll_music_environment;

        await login(page);
        await openNewCalculationStepTwo(page, 'D4d Midroll Release Smoke GmbH');
        await selectInventory(page, fixture.inventory.code);
        await selectMedium(page, midroll.name);

        const tkpRadio = page.locator('[data-test="calculation-method-radio-0-tkp"]');
        if ((await tkpRadio.count()) > 0) {
            await tkpRadio.check();
        } else {
            await expect(page.getByText(/Berechnungsbasis.*TKP|Methode.*TKP|^TKP$/i).first()).toBeVisible();
        }
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();

        await assertNoPlatformUi(page);
        await expect(
            page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="oa-tkp-save-blocked"]')).toHaveCount(
            0,
        );

        await expect(page.locator('[data-test="oa-tkp-price-year-0"]')).toBeEnabled();
        await expect(page.locator('[data-test="oa-tkp-price-year-0"]')).toHaveValue(
            String(fixture.priceList.year),
        );

        await expect(page.getByText('80,0000 €').first()).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByText('20,0000 €').first()).toBeVisible();

        await page.locator('[data-test="oa-tkp-impressions-0"]').fill('100000');
        await page.locator('[data-test="oa-tkp-basis-0"]').fill('80.0000');
        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '80,0000',
        );
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toHaveCount(0);
        await expect(page.getByText('95,0000')).toHaveCount(0);

        await page
            .locator(`[data-test="oa-tkp-targeting-0-${fixture.targeting.id}"]`)
            .click();
        await waitForPreview(page);
        await expect(page.getByText('25,0000 €').first()).toBeVisible();
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '105,0000',
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 60_000 });

        const calcId = Number(
            page.url().match(/\/kalkulationen\/(\d+)/)?.[1] ?? '0',
        );
        expect(calcId).toBeGreaterThan(0);

        const positionId = Number(
            sqliteScalar(
                'select id from calculation_positions where calculation_id=? order by sort limit 1',
                [calcId],
            ),
        );
        expect(positionId).toBeGreaterThan(0);
        expect(
            sqliteScalar(
                'select engine_profile_key from calculation_positions where id=?',
                [positionId],
            ),
        ).toBe('oa_tkp');
        expect(
            sqliteScalar(
                'select calculation_method_key from calculation_positions where id=?',
                [positionId],
            ),
        ).toBe('tkp');
        expect(
            sqliteScalar(
                'select algorithm_version from calculation_positions where id=?',
                [positionId],
            ),
        ).toBe('v1');
        expect(
            sqliteScalar(
                'select cast(list_tkp_snapshot as text) from calculation_positions where id=?',
                [positionId],
            ),
        ).toMatch(/^80/);
        // Preislisten-Grundminimum vs. effektives Minimum inkl. DMP (+5).
        expect(
            sqliteScalar(
                'select cast(price_list_minimum_tkp_snapshot as text) from calculation_positions where id=?',
                [positionId],
            ),
        ).toMatch(/^20/);
        expect(
            sqliteScalar(
                'select cast(minimum_tkp_snapshot as text) from calculation_positions where id=?',
                [positionId],
            ),
        ).toMatch(/^25/);
        expect(
            sqliteScalar(
                'select cast(final_tkp as text) from calculation_positions where id=?',
                [positionId],
            ),
        ).toMatch(/^105/);
        expect(
            sqliteScalar(
                'select oa_tkp_price_list_id from calculation_positions where id=?',
                [positionId],
            ),
        ).toBe(String(fixture.priceList.id));
        expect(
            sqliteScalar(
                'select count(*) from calculation_position_oa_targeting_selections where calculation_position_id=?',
                [positionId],
            ),
        ).toBe('1');
        expect(
            sqliteScalar(
                'select cast(tkp_surcharge_snapshot as text) from calculation_position_oa_targeting_selections where calculation_position_id=? limit 1',
                [positionId],
            ),
        ).toMatch(/^25/);

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="position-medium-0"]')).toHaveValue(
            String(midroll.id),
        );
        const reloadedTkp = page.locator(
            '[data-test="calculation-method-radio-0-tkp"]',
        );
        if ((await reloadedTkp.count()) > 0) {
            await expect(reloadedTkp).toBeChecked();
        } else {
            await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
        }
        await expect(page.locator('[data-test="oa-tkp-impressions-0"]')).toHaveValue(
            '100000',
        );
        await expect(page.locator('[data-test="oa-tkp-price-year-0"]')).toHaveValue(
            String(fixture.priceList.year),
        );
        // Nach Reload: gespeicherte Targetings als historische Pins (nicht Active-Checkbox).
        await expect(
            page.locator(
                `[data-test="oa-tkp-targeting-historical-0-${fixture.targeting.code}"]`,
            ),
        ).toBeVisible();
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '105,0000',
        );
    });

    test('5-Core-Matrix bookable + TKP; native_ad OUT', async ({ page }) => {
        test.setTimeout(180_000);
        const fixture = loadFixture();
        await login(page);
        await openNewCalculationStepTwo(page, 'D4d Core Matrix GmbH');
        await selectInventory(page, fixture.inventory.code);

        const mediumSelect = page.locator('[data-test="position-medium-0"]');
        const labels = await mediumSelect.locator('option').allTextContents();

        for (const code of coreCodes) {
            const name = fixture.media[code].name;
            expect(labels.some((label) => label.includes(name))).toBe(true);
            await selectMedium(page, name);
            const tkpRadio = page.locator(
                '[data-test="calculation-method-radio-0-tkp"]',
            );
            if ((await tkpRadio.count()) > 0) {
                await expect(tkpRadio).toBeVisible();
            } else {
                await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
            }
            await expect(
                page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
            ).toHaveCount(0);
        }

        expect(labels.some((label) => label.includes(fixture.nativeAd.name))).toBe(
            false,
        );
    });

    test('pre_stream Preview-Smoke', async ({ page }) => {
        test.setTimeout(180_000);
        const fixture = loadFixture();
        await login(page);
        await openNewCalculationStepTwo(page, 'D4d Pre-Stream Preview GmbH');
        await selectInventory(page, fixture.inventory.code);
        await selectMedium(page, fixture.media.pre_stream.name);
        const tkpRadio = page.locator('[data-test="calculation-method-radio-0-tkp"]');
        if ((await tkpRadio.count()) > 0) {
            await tkpRadio.check();
        }
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
        await expect(page.getByText('56,0000 €').first()).toBeVisible({
            timeout: 30_000,
        });
        await page.locator('[data-test="oa-tkp-impressions-0"]').fill('50000');
        await page.locator('[data-test="oa-tkp-basis-0"]').fill('56.0000');
        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '56,0000',
        );
        await assertNoPlatformUi(page);
    });

    test('Mindest-TKP: Sonderfreigabe-Banner (OA-002)', async ({ page }) => {
        test.setTimeout(180_000);
        const fixture = loadFixture();
        await login(page);
        await openNewCalculationStepTwo(page, 'D4d Under-Min GmbH');
        await selectInventory(page, fixture.inventory.code);
        await selectMedium(page, fixture.media.midroll_music_environment.name);
        const tkpRadio = page.locator('[data-test="calculation-method-radio-0-tkp"]');
        if ((await tkpRadio.count()) > 0) {
            await tkpRadio.check();
        }
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
        await expect(page.getByText('20,0000 €').first()).toBeVisible({
            timeout: 30_000,
        });
        await page.locator('[data-test="oa-tkp-impressions-0"]').fill('100000');
        await page.locator('[data-test="oa-tkp-basis-0"]').fill('10.0000');
        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toBeVisible();
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toContainText(
            /Sonderfreigabe erforderlich/i,
        );
        await expect(
            page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await expect(page.getByLabel(/Festpreis/i)).toHaveCount(0);
    });

    test('Fehlende Preisliste: kein Cross-Year (servernah)', async () => {
        const fixture = loadFixture();
        expect(
            sqliteScalar(
                'select count(*) from oa_tkp_price_lists where year=? and status=?',
                [2027, 'active'],
            ),
        ).toBe('0');
        expect(
            sqliteScalar(
                'select count(*) from oa_tkp_price_lists where year=? and status=?',
                [fixture.priceList.year, 'active'],
            ),
        ).toBe('1');
        expect(
            sqliteScalar(
                "select count(*) from oa_tkp_price_lists where status='draft'",
            ),
        ).toBe('0');
    });
});
