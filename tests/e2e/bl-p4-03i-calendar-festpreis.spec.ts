import { expect, test, type Page } from '@playwright/test';

const monday = '2026-03-02';
const hour8 = 8;
/** Fixture: 30s × 10 Spots × 2,00 €/s = Mediabrutto 600,00; N/N-Festpreis 500,00 */
const expectedGross = '600,00';
const expectedNn = '500,00';

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

test.describe.serial('BL-P4-03i Calendar × Festpreis Einzelspot', () => {
    test('PM: Calendar-Festpreis speichern, reload, publish; Vertrieb adoptiert', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');
        await expect(
            page.locator('[data-test="standard-offer-scope-note"]'),
        ).toContainText('Festpreis');
        await expect(
            page.locator('[data-test="standard-offer-scope-note"]'),
        ).toContainText('Hauptspot');

        await page.locator('[data-test="standard-offer-title"]').fill(
            'E2E Calendar Festpreis März 2026',
        );
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await page.locator('[data-test="position-price-year-0"]').selectOption('2026');
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');

        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill('500,00');

        await ensureWeekContainsDate(page, monday);
        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');

        await expect(
            page.locator('[data-test="fixed-price-media-gross-0"]'),
        ).toContainText(expectedGross, { timeout: 20_000 });
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText(expectedNn);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(
            0,
            { timeout: 20_000 },
        );
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(expectedNn, { timeout: 20_000 });

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
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /500/,
        );
        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`),
        ).toHaveValue('10');
        await expect(
            page.locator('[data-test="fixed-price-media-gross-0"]'),
        ).toContainText(expectedGross, { timeout: 20_000 });
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText(expectedNn);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(expectedNn, { timeout: 20_000 });

        await page.locator('[data-test="standard-offer-publish"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });

        const offerUrl = page.url();

        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        await page.goto(offerUrl);

        await expect(
            page.locator('[data-test="standard-offer-adopt-hint"]'),
        ).toContainText('gespeicherten Termine und Preise');

        await page
            .locator('[data-test="standard-offer-customer"]')
            .fill('E2E Adopt Kunde 03i GmbH');
        await page.locator('[data-test="standard-offer-adopt"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });

        await page.reload();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /500/,
        );
        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`),
        ).toHaveValue('10');
        await expect(
            page.locator('[data-test="fixed-price-media-gross-0"]'),
        ).toContainText(expectedGross, { timeout: 20_000 });
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText(expectedNn);
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(
            0,
            { timeout: 20_000 },
        );
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(expectedNn, { timeout: 20_000 });
    });
});
