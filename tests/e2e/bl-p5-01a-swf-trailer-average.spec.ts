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

    test('Spot mit Komponenten → Trailer: Komponenten weg, Länge aus Regel, Preview/Save/Reload', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer Komponentenwechsel GmbH');

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-TTA"]').click();
        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: 'Spot Classic' });

        await page.locator('[data-test="spot-components-activate-0"]').click();
        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await page.locator('[data-test="spot-component-length-main_spot-0"]').fill('20');
        await page.locator('[data-test="spot-components-add-allonge-0"]').click();
        await page.locator('[data-test="spot-component-length-allonge-0"]').fill('10');

        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: trailerMedium });

        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="spot-components-activate-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="position-length-seconds-0"]'),
        ).toHaveValue('20');

        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expectTrailerUi(page, 0, '20');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );
    });

    test('Spot Festpreis → Trailer: Normal-Abschluss, keine Festpreiswahl', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer Festpreiswechsel GmbH');

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-TTA"]').click();
        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: 'Spot Classic' });

        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill('400,00');

        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: trailerMedium });

        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-normal"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveCount(0);

        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );
    });

    test('Spot Festpreis-Validierungsfehler → Trailer: Fehler weg, Preview/Save/Reload', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(
            page,
            'Trailer Festpreis Validierung Cleanup GmbH',
        );

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-TTA"]').click();
        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: 'Spot Classic' });

        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);

        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill('');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page.locator('[data-test="preview-error"]')).toContainText(
            'Festpreis erfordert',
            { timeout: 10_000 },
        );

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: trailerMedium });

        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-normal"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveCount(0);
        await expect(page.locator('[data-test="preview-error"]')).toHaveCount(0);
        await expect(page.locator('body')).not.toContainText('Festpreis erfordert');

        await expectTrailerUi(page, 0, '20');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expectTrailerUi(page, 0, '20');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );
        await expect(page.locator('body')).not.toContainText('Festpreis erfordert');
    });

    test('Trailer Inventar A → B: Ziel-Länge, Ziel-Aufschlag, Zielpreise', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer Inventarwechsel GmbH');

        await selectTrailerPosition(page, 0, 'TTA');
        await expectTrailerUi(page, 0, '20');
        await fillRange(page, 0, '8', '9', '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            grossA,
            { timeout: 20_000 },
        );

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-TTB"]').click();
        await expectTrailerUi(page, 0, '15');
        await waitForPreview(page);
        // 10 × 1,50 × 15 × 1,50 = 337,50
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
            '337,50',
            { timeout: 20_000 },
        );
    });

    test('Rückwechsel Trailer → Spot: Komponenten wieder aktivierbar, keine Regression', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Trailer Rueckwechsel Spot GmbH');

        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-TTA"]').click();
        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: 'Spot Classic' });
        await page.locator('[data-test="spot-components-activate-0"]').click();
        await page.locator('[data-test="spot-component-length-main_spot-0"]').fill('20');
        await page.locator('[data-test="spot-components-add-allonge-0"]').click();
        await page.locator('[data-test="spot-component-length-allonge-0"]').fill('10');

        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: trailerMedium });
        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toHaveCount(0);

        await page
            .locator('[data-test="position-medium-0"]')
            .selectOption({ label: 'Spot Classic' });
        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-allonge-0"]'),
        ).toHaveValue('10');
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeVisible();
    });
});
