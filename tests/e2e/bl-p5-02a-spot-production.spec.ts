import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P5-02a: Spotproduktion an Spot Classic × Average (PO-BLP502-1).
 * Isolierte Suite (playwright.blp502a.config.ts, Port 8057, eigene SQLite-DB).
 *
 * Synthetische Fixtures:
 * - SPA: 2 × 150,00 = 300,00
 * - SPB: 3 × 80,00 = 240,00
 * - Produktionssumme 540,00 zusätzlich zur Medienrechnung
 * - SPC: ohne Active → fail-closed
 */

const spotMedium = 'Spot Classic';

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
    await page.locator('#customer').fill(customer);
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function selectSpotInventory(page: Page, index: number, code: string) {
    const expand = page.locator(
        `[data-test="position-inventory-expand-${index}"]`,
    );
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(`[data-test="position-inventory-tile-${index}-${code}"]`)
        .click();
    await page
        .locator(`[data-test="position-medium-${index}"]`)
        .selectOption({ label: spotMedium });
}

async function fillRange(
    page: Page,
    index: number,
    spots: string,
) {
    if (
        (await page.locator(`[data-test="range-spots-${index}-0"]`).count()) ===
        0
    ) {
        await page.locator(`[data-test="range-add-${index}"]`).click();
    }
    await page
        .locator(`[data-test="range-start-${index}-0"]`)
        .selectOption('8');
    await page.locator(`[data-test="range-end-${index}-0"]`).selectOption('9');
    await page
        .locator(`[data-test="range-day-${index}-0"]`)
        .selectOption('mo_fr');
    await page.locator(`[data-test="range-spots-${index}-0"]`).fill(spots);
    await page.locator(`[data-test="position-length-seconds-${index}"]`).fill('30');
}

async function addProductionLine(
    page: Page,
    positionIndex: number,
    lineIndex: number,
    label: string,
    quantity: string,
) {
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.locator(`[data-test="production-add-${positionIndex}"]`).click();
    await page
        .locator(`[data-test="production-label-${positionIndex}-${lineIndex}"]`)
        .fill(label);
    await page
        .locator(
            `[data-test="production-quantity-${positionIndex}-${lineIndex}"]`,
        )
        .fill(quantity);
}

test.describe.serial('BL-P5-02a Spotproduktion', () => {
    test('Admin-Preis anlegen/aktivieren und Vertical Slice SPA+SPB', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        // Admin: zusätzlichen Draft für SPA anlegen und aktivieren (ersetzt Seed-Active).
        await login(page, 'admin@example.com');
        await page.goto('/administration/produktionspreise/neu');
        const inventorySelect = page.locator(
            '[data-test="production-price-inventory-input"]',
        );
        const spaValue = await inventorySelect.locator('option').evaluateAll(
            (options) =>
                options.find((option) =>
                    (option.textContent ?? '').includes('Produktion Testsender A'),
                )?.getAttribute('value') ?? '',
        );
        expect(spaValue).not.toBe('');
        await inventorySelect.selectOption(spaValue);
        await page.locator('[data-test="production-price-year-input"]').fill(
            String(new Date().getFullYear()),
        );
        await page
            .locator('[data-test="production-price-name-input"]')
            .fill('E2E Admin SPA 150');
        await page
            .locator('[data-test="production-price-unit-price-input"]')
            .fill('150,00');
        await page.locator('[data-test="production-price-create-submit"]').click();
        await expect(page).toHaveURL(/\/administration\/produktionspreise\/\d+/, {
            timeout: 30_000,
        });
        await page.locator('[data-test="production-price-activate"]').click();
        await expect(
            page.locator('[data-test="production-price-activate"]'),
        ).toHaveCount(0, { timeout: 20_000 });

        // Sales: Wizard mit SPA + SPB Produktionszeilen.
        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        await openNewCalculationStepTwo(page, 'Produktion E2E GmbH');

        await selectSpotInventory(page, 0, 'SPA');
        await fillRange(page, 0, '10');
        await waitForPreview(page);

        await addProductionLine(page, 0, 0, 'Spotproduktion A', '2');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="production-unit-price-0-0"]'),
        ).toHaveValue(/150/);
        await expect(
            page.locator('[data-test="production-total-0-0"]'),
        ).toHaveValue(/300/);

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
        await selectSpotInventory(page, 1, 'SPB');
        await fillRange(page, 1, '5');
        await waitForPreview(page);

        await addProductionLine(page, 1, 0, 'Spotproduktion B', '3');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="production-unit-price-1-0"]'),
        ).toHaveValue(/80/);
        await expect(
            page.locator('[data-test="production-total-1-0"]'),
        ).toHaveValue(/240/);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        // Medien + 540 Produktion; exakter Media-Wert abhängig von Spotformel.
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toBeVisible({ timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await expect(
            page.locator('[data-test="production-total-0-0"]'),
        ).toHaveValue(/300/, { timeout: 20_000 });
        await expect(
            page.locator('[data-test="production-total-1-0"]'),
        ).toHaveValue(/240/);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, {
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-test="dispo-production-lines"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-production-booking-code"]').first(),
        ).toContainText('S');
        await expect(page.locator('body')).toContainText('Spotproduktion A');
        await expect(page.locator('body')).toContainText('Spotproduktion B');
    });

    test('Inventarwechsel auf SPC ohne Preis: Fehler, keine Teilmutation', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Produktion E2E Fail GmbH');

        await selectSpotInventory(page, 0, 'SPA');
        await fillRange(page, 0, '10');
        await addProductionLine(page, 0, 0, 'Spotproduktion', '2');
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="production-total-0-0"]'),
        ).toHaveValue(/300/);

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });
        const calcUrl = page.url();

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await selectSpotInventory(page, 0, 'SPC');
        await waitForPreview(page);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await expect(
            page.locator('[data-test="production-error-0"]'),
        ).toBeVisible({ timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(calcUrl, { timeout: 10_000 });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        // Pin bleibt auf SPA (keine Teilmutation).
        await expect(page.locator('body')).toContainText(
            'Produktion Testsender A',
            { timeout: 20_000 },
        );
    });

    test('Calendar-Methode mit Produktionszeile: Sperre, Entfernung möglich', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Produktion E2E Methode GmbH');

        await selectSpotInventory(page, 0, 'SPA');
        await fillRange(page, 0, '10');
        await addProductionLine(page, 0, 0, 'Spotproduktion', '1');
        await waitForPreview(page);

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await expect(
            page.locator('[data-test="production-unsupported-0"]'),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator('[data-test="production-line-0-0"]'),
        ).toBeVisible();

        await page.locator('[data-test="production-remove-0-0"]').click();
        await expect(
            page.locator('[data-test="production-line-0-0"]'),
        ).toHaveCount(0);
    });
});
