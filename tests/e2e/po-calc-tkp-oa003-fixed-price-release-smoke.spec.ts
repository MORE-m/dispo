import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * PO-CALC-TKP-OA003-4: Fixed-Price-Release-Smoke (oa_tkp×tkp released/v1).
 * Capability=false, Preview-Surface=true, Mutation=true. Isoliert Port 8078.
 * Fixture-JSON wird vom gemeinsamen Seeder E2EOaTkpReleaseSmokeSeeder geschrieben.
 */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixturePath = path.join(
    root,
    'database/e2e-po-calc-tkp-1d4d-release-smoke.json',
);
const e2eDb = path.join(
    root,
    'database/e2e-po-calc-tkp-oa003-release-smoke.sqlite',
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
        '$pdo = new PDO("sqlite:" . getenv("OA0034_E2E_DB"));',
        '$st = $pdo->prepare(getenv("OA0034_E2E_SQL"));',
        '$params = json_decode(getenv("OA0034_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            OA0034_E2E_DB: e2eDb,
            OA0034_E2E_SQL: sql,
            OA0034_E2E_PARAMS: JSON.stringify(params),
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


async function startMidrollFixed(
    page: Page,
    customer: string,
    fixedPrice: string,
    withDmp: boolean,
) {
    const fixture = loadFixture();
    await login(page);
    await openNewCalculationStepTwo(page, customer);
    await selectInventory(page, fixture.inventory.code);
    await selectMedium(page, fixture.media.midroll_music_environment.name);
    await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
    await page.locator('[data-test="oa-tkp-impressions-0"]').fill('100000');
    await page.locator('[data-test="oa-tkp-settlement-fixed-0"]').click();
    await page.locator('[data-test="oa-tkp-fixed-price-0"]').fill(fixedPrice);
    if (withDmp) {
        await page
            .locator(`[data-test="oa-tkp-targeting-0-${fixture.targeting.id}"]`)
            .click();
    }
    await waitForPreview(page);
}

async function saveCalculation(page: Page): Promise<number> {
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await waitForPreview(page);
    await page.locator('[data-test="wizard-save"]').click();
    await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 60_000 });
    const calcId = Number(page.url().match(/\/kalkulationen\/(\d+)/)?.[1] ?? '0');
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

function reasonsJson(calcId: number): string {
    return sqliteScalar(
        'select special_approval_reasons from calculations where id=?',
        [calcId],
    );
}

async function reopenStepTwo(page: Page) {
    await page.reload();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

test.describe.configure({ mode: 'serial' });

test.describe('PO-CALC-TKP-OA003-4 Fixed-Price Release Browser Smoke', () => {
    test('E2E-1 Release-Signale, Fixture und Inertia-Props', async ({ page }) => {
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
        expect(fixture.targeting.minimum_tkp_surcharge).toBe('5.0000');
        expect(sqliteScalar('select count(*) from oa_tkp_price_lists')).toBe('1');

        await login(page);
        const response = await page.goto('/kalkulationen/neu');
        expect(response?.ok()).toBeTruthy();
        const script = page.locator('script[data-page][type="application/json"]');
        await expect(script).toHaveCount(1);
        const props = JSON.parse((await script.textContent())!).props as Record<
            string,
            unknown
        >;
        expect(props.oa_tkp_wizard_capability).toBe(false);
        expect(props.oa_tkp_preview_surface_allowed).toBe(true);
        expect(props.oa_tkp_http_mutation_allowed).toBe(true);
    });

    test('E2E-2 Midroll Fixed: Preview, DMP, Save, Persistenz, Reload', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const fixture = loadFixture();
        const midroll = fixture.media.midroll_music_environment;

        await login(page);
        await openNewCalculationStepTwo(page, 'OA003-4 Fixed Midroll GmbH');
        await selectInventory(page, fixture.inventory.code);
        await selectMedium(page, midroll.name);
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();

        // Kein Method-Key fixed_price; Settlement-Toggle sichtbar; keine Plattform-UI.
        await expect(
            page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="oa-tkp-settlement-fixed-0"]'),
        ).toBeVisible();
        await assertNoPlatformUi(page);

        await page.locator('[data-test="oa-tkp-impressions-0"]').fill('100000');
        await page.locator('[data-test="oa-tkp-settlement-fixed-0"]').click();
        await page.locator('[data-test="oa-tkp-fixed-price-0"]').fill('5000');

        await expect(page.locator('[data-test="oa-tkp-basis-0"]')).toHaveCount(0);
        await expect(
            page.locator('[data-test="oa-tkp-fixed-approval-0"]'),
        ).toBeVisible();

        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '50,0000',
        );
        await expect(page.locator('[data-test="oa-tkp-fixed-nn-0"]')).toContainText(
            /5\.000/,
        );
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toHaveCount(0);
        await expect(
            page.locator('[data-test="oa-tkp-fixed-approval-0"]'),
        ).toBeVisible();

        await page
            .locator(`[data-test="oa-tkp-targeting-0-${fixture.targeting.id}"]`)
            .click();
        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-media-gross-0"]')).toContainText(
            /10\.500/,
        );
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '50,0000',
        );
        await expect(page.getByText('25,0000 €').first()).toBeVisible();
        await expect(page.getByText('65,0000')).toHaveCount(0);
        await expect(page.getByText('95,0000')).toHaveCount(0);
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toHaveCount(0);
        await assertNoPlatformUi(page);

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);

        expect(posCol(positionId, 'engine_profile_key')).toBe('oa_tkp');
        expect(posCol(positionId, 'calculation_method_key')).toBe('tkp');
        expect(posCol(positionId, 'algorithm_version')).toBe('v1');
        expect(posCol(positionId, 'pricing_settlement_mode')).toBe('fixed_price');
        expect(Number(posCol(positionId, 'fixed_price_nn'))).toBe(5000);
        expect(Number(posCol(positionId, 'impressions'))).toBe(100000);
        expect(Number(posCol(positionId, 'basis_tkp'))).toBe(50);
        expect(Number(posCol(positionId, 'final_tkp'))).toBe(50);
        expect(Number(posCol(positionId, 'list_tkp_snapshot'))).toBe(80);
        expect(Number(posCol(positionId, 'price_list_minimum_tkp_snapshot'))).toBe(20);
        expect(Number(posCol(positionId, 'minimum_tkp_snapshot'))).toBe(25);
        expect(Number(posCol(positionId, 'targeting_surcharge_total'))).toBe(0);
        expect(Number(posCol(positionId, 'media_gross'))).toBe(10500);
        expect(Number(posCol(positionId, 'nn_invest'))).toBe(5000);
        expect(posCol(positionId, 'oa_tkp_price_list_id')).toBe(
            String(fixture.priceList.id),
        );
        expect(
            sqliteScalar(
                'select count(*) from calculation_position_oa_targeting_selections where calculation_position_id=?',
                [positionId],
            ),
        ).toBe('1');
        expect(
            Number(
                sqliteScalar(
                    'select cast(tkp_surcharge_snapshot as text) from calculation_position_oa_targeting_selections where calculation_position_id=? limit 1',
                    [positionId],
                ),
            ),
        ).toBe(25);
        // Kein +15 Adserver-Aufschlag.
        expect(posCol(positionId, 'final_tkp')).not.toMatch(/^(65|95)/);

        expect(
            sqliteScalar(
                'select requires_special_approval from calculations where id=?',
                [calcId],
            ),
        ).toBe('1');
        const reasons = reasonsJson(calcId);
        expect(reasons).toContain('oa_tkp_fixed_price');
        expect(reasons).not.toContain('oa_tkp_basis_below_minimum');

        // Reload
        await reopenStepTwo(page);
        await expect(page.locator('[data-test="position-medium-0"]')).toHaveValue(
            String(midroll.id),
        );
        await expect(
            page.locator('[data-test="oa-tkp-settlement-fixed-0"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="oa-tkp-fixed-price-0"]')).toHaveValue(
            /^(5\.000(,00)?|5000(\.00)?)$/,
        );
        await expect(page.locator('[data-test="oa-tkp-impressions-0"]')).toHaveValue(
            '100000',
        );
        await expect(page.locator('[data-test="oa-tkp-basis-0"]')).toHaveCount(0);
        await expect(
            page.locator('[data-test="oa-tkp-fixed-approval-0"]'),
        ).toBeVisible();
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '50,0000',
        );
        await expect(
            page.locator(
                `[data-test="oa-tkp-targeting-historical-0-${fixture.targeting.code}"]`,
            ),
        ).toBeVisible();
    });

    test('E2E-3 Under-Min: Festpreis 2300 mit DMP', async ({ page }) => {
        test.setTimeout(240_000);
        await startMidrollFixed(page, 'OA003-4 Under-Min GmbH', '2300', true);

        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '23,0000',
        );
        await expect(page.getByText('25,0000 €').first()).toBeVisible();
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toBeVisible();
        await expect(
            page.locator('[data-test="oa-tkp-fixed-approval-0"]'),
        ).toBeVisible();

        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);
        expect(posCol(positionId, 'pricing_settlement_mode')).toBe('fixed_price');
        expect(Number(posCol(positionId, 'fixed_price_nn'))).toBe(2300);
        expect(Number(posCol(positionId, 'final_tkp'))).toBe(23);
        expect(Number(posCol(positionId, 'minimum_tkp_snapshot'))).toBe(25);

        const reasons = reasonsJson(calcId);
        expect(reasons).toContain('oa_tkp_fixed_price');
        expect(reasons).toContain('oa_tkp_basis_below_minimum');
        expect(reasons).not.toMatch(/discount/i);
        expect(reasons).not.toMatch(/limit/i);
        const codes = (
            JSON.parse(reasons) as Array<{ code?: string } | string>
        ).map((r) => (typeof r === 'string' ? r : (r.code ?? '')));
        expect(codes.sort()).toEqual([
            'oa_tkp_basis_below_minimum',
            'oa_tkp_fixed_price',
        ]);
    });

    test('E2E-4 Fixed -> Normal: Basis-Pflicht, Save, Reason entfällt', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await startMidrollFixed(page, 'OA003-4 Fixed zu Normal GmbH', '5000', false);
        const calcId = await saveCalculation(page);
        const positionId = firstPositionId(calcId);
        expect(posCol(positionId, 'pricing_settlement_mode')).toBe('fixed_price');
        expect(reasonsJson(calcId)).toContain('oa_tkp_fixed_price');

        await reopenStepTwo(page);
        await expect(
            page.locator('[data-test="oa-tkp-settlement-fixed-0"]'),
        ).toBeChecked();

        await page.locator('[data-test="oa-tkp-settlement-normal-0"]').click();
        await expect(page.locator('[data-test="oa-tkp-fixed-price-0"]')).toHaveCount(
            0,
        );
        await expect(
            page.locator('[data-test="oa-tkp-fixed-approval-0"]'),
        ).toHaveCount(0);
        const basis = page.locator('[data-test="oa-tkp-basis-0"]');
        await expect(basis).toBeVisible();
        await expect(basis).toHaveValue('');

        // Preview blockiert, solange Basis-TKP leer ist.
        // (Summary zeigt „Berechnet …“ + „TKP-Angaben noch unvollständig“.)
        await expect(page.getByText(/TKP-Angaben noch unvollständig/i)).toBeVisible();
        await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(1);

        await basis.fill('80.0000');
        await waitForPreview(page);
        await expect(page.locator('[data-test="oa-tkp-final-0"]')).toContainText(
            '80,0000',
        );
        await expect(page.locator('[data-test="oa-tkp-under-min-0"]')).toHaveCount(0);

        await saveCalculation(page);
        expect(posCol(positionId, 'pricing_settlement_mode')).toBe('normal');
        expect(
            sqliteScalar(
                'select fixed_price_nn is null from calculation_positions where id=?',
                [positionId],
            ),
        ).toBe('1');
        expect(Number(posCol(positionId, 'final_tkp'))).toBe(80);
        expect(reasonsJson(calcId)).not.toContain('oa_tkp_fixed_price');
    });

    test('E2E-5 Fünf Core-Medien: Editor + Settlement-Toggle', async ({ page }) => {
        test.setTimeout(180_000);
        const fixture = loadFixture();
        await login(page);
        await openNewCalculationStepTwo(page, 'OA003-4 Core Matrix GmbH');
        await selectInventory(page, fixture.inventory.code);

        const labels = await page
            .locator('[data-test="position-medium-0"]')
            .locator('option')
            .allTextContents();

        for (const code of coreCodes) {
            const name = fixture.media[code].name;
            expect(labels.some((label) => label.includes(name))).toBe(true);
            await selectMedium(page, name);
            await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
            await expect(
                page.locator('[data-test="oa-tkp-settlement-fixed-0"]'),
            ).toBeVisible();
            await expect(
                page.locator('[data-test="oa-tkp-settlement-normal-0"]'),
            ).toBeVisible();
        }
    });

    test('E2E-6 Negativfälle: native_ad, Plattform-UI, fixed_price-Method', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const fixture = loadFixture();
        await login(page);
        await openNewCalculationStepTwo(page, 'OA003-4 Negativ GmbH');
        await selectInventory(page, fixture.inventory.code);

        const labels = await page
            .locator('[data-test="position-medium-0"]')
            .locator('option')
            .allTextContents();
        expect(labels.some((label) => label.includes(fixture.nativeAd.name))).toBe(
            false,
        );

        await selectMedium(page, fixture.media.midroll_music_environment.name);
        await expect(page.locator('[data-test="oa-tkp-editor-0"]')).toBeVisible();
        await assertNoPlatformUi(page);
        await expect(
            page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await page.locator('[data-test="oa-tkp-settlement-fixed-0"]').click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await assertNoPlatformUi(page);
    });
});
