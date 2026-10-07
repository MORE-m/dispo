import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P5-01a: SWF Trailer × Durchschnitt (PO-BLP501A-1 A1 + B1).
 * Isolierte Suite (playwright.blp501a.config.ts, Port 8055, eigene SQLite-DB).
 *
 * Synthetische Testwerte:
 * - Testsender A: 2,00 €/s · 20 s · +30 %  → 10 Spots = 520,00
 * - Testsender B: 1,50 €/s · 15 s · +50 %  →  5 Spots = 168,75
 * - Testsender C: Trailer-Regel ohne Länge/Aufschlag → fail-closed
 */

const trailerMedium = 'Trailer/Vorpr. Element Station Voice';
const grossA = '520,00';
const grossB = '168,75';
const grossSum = '688,75';

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

async function selectTrailerPosition(
    page: Page,
    index: number,
    inventoryCode: string,
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
        .selectOption({ label: trailerMedium });
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

async function expectTrailerUi(page: Page, index: number, length: string) {
    // Nur Durchschnitt; kein Kalender, keine Spot-Komponenten, keine Festpreis-Abwicklung.
    await expect(
        page.locator(`[data-test="calculation-method-radio-${index}-calendar"]`),
    ).toHaveCount(0);
    await expect(
        page.locator(`[data-test="spot-components-activate-${index}"]`),
    ).toHaveCount(0);
    await expect(
        page.locator(`[data-test="position-length-seconds-${index}"]`),
    ).toHaveValue(length);
}

test.describe.serial('BL-P5-01a SWF Trailer × Durchschnitt', () => {
    test('zwei Inventare: Preview, Save, Reload, Dispo', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer E2E GmbH');

        await selectTrailerPosition(page, 0, 'TTA');
        await expectTrailerUi(page, 0, '20');
        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
        await selectTrailerPosition(page, 1, 'TTB');
        await expectTrailerUi(page, 1, '15');
        await fillRange(page, 1, '8', '9', '5');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-1-0"]')).toContainText(
            grossB,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(grossSum, { timeout: 20_000 });

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );
        await expect(page.locator('[data-test="range-gross-1-0"]')).toContainText(
            grossB,
        );
        await expectTrailerUi(page, 0, '20');
        await expectTrailerUi(page, 1, '15');
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="preview-net-total"]').first(),
        ).toContainText(grossSum, { timeout: 20_000 });

        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, {
            timeout: 30_000,
        });
        await expect(page.locator('body')).toContainText('Trailer Testsender A');
        await expect(page.locator('body')).toContainText('Trailer Testsender B');
        await expect(page.locator('body')).toContainText(trailerMedium);
        await expect(
            page.locator('[data-test="dispo-order-position-components-0"]'),
        ).toHaveCount(0);
    });

    test('Inventar ohne Trailer-Konfiguration: deutsche Fehlermeldung, nichts gespeichert', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer E2E Unvollständig GmbH');

        await selectTrailerPosition(page, 0, 'TTC');
        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();

        await expect(page).toHaveURL(/\/kalkulationen\/neu/, { timeout: 10_000 });
        await expect(page.locator('body')).toContainText(
            /Trailer-Länge konfiguriert|Trailer-Aufschlag konfiguriert/,
            { timeout: 20_000 },
        );
    });
});
