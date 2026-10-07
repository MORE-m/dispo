import { expect, test, type Page } from '@playwright/test';

const monday = '2026-03-02';
const hour8 = 8;
const tandemGross = '600,00';
const tridemGross = '760,00';
const tridemNn = '1.200,00';

async function login(page: Page, email: string) {
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

async function ensureWeekContainsDate(page: Page, date: string) {
    const header = page.locator(`[data-test="planner-day-header-0-${date}"]`);
    if ((await header.count()) > 0) {
        return;
    }

    for (let i = 0; i < 80; i += 1) {
        await page.locator('[data-test="planner-week-prev-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    for (let i = 0; i < 160; i += 1) {
        await page.locator('[data-test="planner-week-next-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    await expect(header).toBeVisible();
}

async function selectInventory(page: Page, name: string) {
    const expand = page.locator('[data-test="position-inventory-expand-0"]');
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page.getByRole('button', { name, exact: true }).click();
}

async function waitForPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 20_000,
    });
}

async function fillTandemLengths(page: Page) {
    await expect(
        page.locator('[data-test="spot-components-section-0"]'),
    ).toBeVisible();
    await page
        .locator('[data-test="spot-component-length-main_spot-1-0"]')
        .fill('20');
    await page
        .locator('[data-test="spot-component-length-reminder-2-0"]')
        .fill('10');
}

async function fillTridemLengths(page: Page) {
    await expect(
        page.locator('[data-test="spot-components-section-0"]'),
    ).toBeVisible();
    await page
        .locator('[data-test="spot-component-length-main_spot-1-0"]')
        .fill('20');
    await page
        .locator('[data-test="spot-component-length-reminder-2-0"]')
        .fill('10');
    await page
        .locator('[data-test="spot-component-length-reminder-3-0"]')
        .fill('10');
}

async function expectNormalPreviewGross(page: Page, gross: string) {
    await expect(
        page.locator(
            `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
        ),
    ).toContainText(gross, { timeout: 20_000 });
}

async function expectFixedPricePreview(
    page: Page,
    gross: string,
    nn: string,
) {
    await expect(
        page.locator('[data-test="fixed-price-media-gross-0"]'),
    ).toContainText(gross, { timeout: 20_000 });
    await expect(
        page.locator('[data-test="fixed-price-nn-preview-0"]'),
    ).toContainText(nn);
}

test.describe.serial('BL-P4-03k Calendar × Tandem/Tridem', () => {
    test('Calendar × Tandem × normal: speichern, publish, adopt, frozen parity', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');
        await expect(
            page.locator('[data-test="standard-offer-scope-note"]'),
        ).toContainText('Tandem');

        await page.locator('[data-test="standard-offer-title"]').fill(
            'E2E Calendar Tandem normal März 2026',
        );
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await selectInventory(page, 'Radio Hamburg Tandem');
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await page.locator('[data-test="position-price-year-0"]').selectOption('2026');
        await fillTandemLengths(page);
        await ensureWeekContainsDate(page, monday);
        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="spot-components-units-0"]'),
        ).toContainText('10');
        await expectNormalPreviewGross(page, tandemGross);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(tandemGross, { timeout: 20_000 });
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-1-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toHaveValue('10');
        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`),
        ).toHaveValue('10');
        await expectNormalPreviewGross(page, tandemGross);

        await page.locator('[data-test="standard-offer-publish"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });

        const offerUrl = page.url();
        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        await page.goto(offerUrl);

        await page
            .locator('[data-test="standard-offer-customer"]')
            .fill('E2E Adopt Kunde 03k Tandem GmbH');
        await page.locator('[data-test="standard-offer-adopt"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-1-0"]'),
        ).toHaveValue('20');
        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`),
        ).toHaveValue('10');
        await expectNormalPreviewGross(page, tandemGross);
    });

    test('Calendar × Tridem × Festpreis: brutto, N/N, publish, adopt', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');

        await page.locator('[data-test="standard-offer-title"]').fill(
            'E2E Calendar Tridem Festpreis März 2026',
        );
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await selectInventory(page, 'Radio Hamburg Tridem');
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await page.locator('[data-test="position-price-year-0"]').selectOption('2026');
        await fillTridemLengths(page);
        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill('1200.00');
        await ensureWeekContainsDate(page, monday);
        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');
        await waitForPreview(page);
        await expectFixedPricePreview(page, tridemGross, tridemNn);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(tridemNn, { timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /1\.?200/,
        );
        await expectFixedPricePreview(page, tridemGross, tridemNn);

        await page.locator('[data-test="standard-offer-publish"]').click();
        const offerUrl = page.url();

        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        await page.goto(offerUrl);
        await page
            .locator('[data-test="standard-offer-customer"]')
            .fill('E2E Adopt Kunde 03k Tridem GmbH');
        await page.locator('[data-test="standard-offer-adopt"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /1\.?200/,
        );
        await expectFixedPricePreview(page, tridemGross, tridemNn);
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(tridemNn, { timeout: 20_000 });
    });
});
