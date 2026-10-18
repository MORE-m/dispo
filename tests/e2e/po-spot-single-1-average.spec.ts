import { expect, test, type Page } from '@playwright/test';

/**
 * PO-SPOT-SINGLE-1: Single-Spot × Durchschnitt (+50 %) + MAT-003-Negativ + Classic-Smoke.
 * Isoliert (playwright.po-spot-single-1.config.ts, Port 8069, eigene SQLite-DB).
 *
 * RH Ø 1,00 €/s · 30 s · 10 Spots:
 * - Single +50 % → 450,00
 * - Classic +0 % → 300,00
 */

const singleMedium = 'Single-Spot';
const classicMedium = 'Werbespot';
const grossSingle = '450,00';
const grossClassic = '300,00';

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

async function fillRange(
    page: Page,
    index: number,
    startHour: string,
    endHour: string,
    spots: string,
) {
    if (
        (await page.locator(`[data-test="range-spots-${index}-0"]`).count()) === 0
    ) {
        await page.locator(`[data-test="range-add-${index}"]`).click();
    }
    await page
        .locator(`[data-test="range-start-${index}-0"]`)
        .selectOption(startHour);
    await page
        .locator(`[data-test="range-end-${index}-0"]`)
        .selectOption(endHour);
    await page
        .locator(`[data-test="range-day-${index}-0"]`)
        .selectOption('mo_fr');
    await page.locator(`[data-test="range-spots-${index}-0"]`).fill(spots);
}

test.describe.serial('PO-SPOT-SINGLE-1 Single Average', () => {
    test('Single: Preview, Save, Reload, Dispo mit +50 %', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Single-Spot E2E GmbH');

        await selectInventoryAndMedium(page, 0, 'RH', singleMedium);
        await expect(
            page.locator('[data-test="position-length-seconds-0"]'),
        ).toHaveValue('30');
        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossSingle,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(grossSingle, { timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossSingle,
            { timeout: 20_000 },
        );
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(grossSingle, { timeout: 20_000 });

        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, {
            timeout: 30_000,
        });
        await expect(page.locator('body')).toContainText('Radio Hamburg');
        await expect(page.locator('body')).toContainText(singleMedium);
        await expect(page.locator('body')).toContainText(grossSingle);
    });

    test('MAT-003: Single auf MORE Hamburg-Kombi+ nicht wählbar', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'MAT-003 Negativ E2E GmbH');

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page
            .locator('[data-test="position-inventory-tile-0-MHKP"]')
            .click();

        const mediumSelect = page.locator('[data-test="position-medium-0"]');
        const options = await mediumSelect.locator('option').allTextContents();
        expect(options.some((label) => label.includes(singleMedium))).toBe(
            false,
        );
        await expect(mediumSelect).toContainText(classicMedium);
    });

    test('Classic-Smoke: Standardberechnung ohne Aufschlag unverändert', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Classic Smoke Single E2E GmbH');

        await selectInventoryAndMedium(page, 0, 'RH', classicMedium);
        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossClassic,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(grossClassic, { timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossClassic,
            { timeout: 20_000 },
        );
    });
});
