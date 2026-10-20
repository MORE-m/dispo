import { expect, test, type Page } from '@playwright/test';

/**
 * PO-SWF-SETTLEMENT-1: Familien-E2E Promo × Calendar × Fixed Settlement.
 * Isoliert (playwright.po-swf-settlement-1.config.ts, Port 8076).
 *
 * RH 1,00 €/s · 20 s · +50 % · 10 Spots @ Mo 08:00 → Baseline 300,00 → N/N 225,00.
 */

const promoMedium = 'Promo/Moderation';
const monday = '2026-09-14';
const hour8 = 8;
const grossPromo = '300,00';
/** Eingabe im Formular (mit Nachkommastellen). */
const fixedNnInput = '225,00';
/** Nach Reload formatiert `formatFixedPriceNnInput` ganze Euro ohne ,00. */
const fixedNnReload = '225';
const fixedNnPreview = '225,00';

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
        timeout: 20_000,
    });
}

async function openNewCalculationStepTwo(page: Page, customer: string) {
    await page.goto('/kalkulationen/neu');
    await page.locator('[data-test="crm-customer-search"]').fill(customer);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function selectInventoryAndMedium(
    page: Page,
    index: number,
    inventoryCode: string,
    mediumLabel: string,
) {
    const expand = page.locator(`[data-test="position-inventory-expand-${index}"]`);
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(`[data-test="position-inventory-tile-${index}-${inventoryCode}"]`)
        .click();
    await page
        .locator(`[data-test="position-medium-${index}"]`)
        .selectOption({ label: mediumLabel });
}

async function ensureWeekContainsDate(page: Page, date: string) {
    const header = page.locator(`[data-test="planner-day-header-0-${date}"]`);
    if ((await header.count()) > 0) {
        return;
    }

    for (let i = 0; i < 60; i += 1) {
        await page.locator('[data-test="planner-week-prev-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    for (let i = 0; i < 120; i += 1) {
        await page.locator('[data-test="planner-week-next-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    await expect(header).toBeVisible();
}

test.describe.serial('PO-SWF-SETTLEMENT-1 Promo Calendar Fixed', () => {
    test('Promo Calendar + Festpreis: Preview, Save, Reload', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'SWF Settlement E2E GmbH');

        await selectInventoryAndMedium(page, 0, 'RH', promoMedium);
        await expect(
            page.locator('[data-test="position-length-seconds-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeVisible();

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');
        await waitForPreview(page);

        await expect(
            page.locator(`[data-test="planner-cell-gross-0-${monday}-${hour8}"]`),
        ).toContainText(grossPromo, { timeout: 20_000 });

        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill(fixedNnInput);
        await waitForPreview(page);

        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText(fixedNnPreview, { timeout: 20_000 });

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(fixedNnPreview, { timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
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
            fixedNnReload,
        );
        await expect(
            page.locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`),
        ).toHaveValue('10');
        await expect(
            page.locator(`[data-test="planner-cell-gross-0-${monday}-${hour8}"]`),
        ).toContainText(grossPromo, { timeout: 20_000 });

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(fixedNnPreview, { timeout: 20_000 });
    });
});
