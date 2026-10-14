import { expect, test, type Page } from '@playwright/test';

/**
 * PO-ALLONGE-1: SWF-Medium Allonge × Durchschnitt.
 * Isoliert (playwright.po-allonge-1.config.ts, Port 8067, eigene SQLite-DB).
 *
 * Synthetisch: Default 10 s, Nutzer 15 s, 10 × 2,00 × 15 × 1,30 = 390,00.
 * Keine operativen Startwerte.
 */

const allongeMedium = 'Allonge';

async function logout(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
        timeout: 15_000,
    });
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
        timeout: 20_000,
    });
}

async function openNewCalculationStepTwo(page: Page, customer: string) {
    await page.goto('/kalkulationen/neu');
    await page.locator('[data-test="crm-customer-search"]').fill(customer);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function selectAllonge(page: Page, index: number, inventoryCode: string) {
    const expand = page.locator(`[data-test="position-inventory-expand-${index}"]`);
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(`[data-test="position-inventory-tile-${index}-${inventoryCode}"]`)
        .click();
    await page
        .locator(`[data-test="position-medium-${index}"]`)
        .selectOption({ label: allongeMedium });
}

async function fillRange(page: Page, index: number, spots: string) {
    if ((await page.locator(`[data-test="range-spots-${index}-0"]`).count()) === 0) {
        await page.locator(`[data-test="range-add-${index}"]`).click();
    }
    await page.locator(`[data-test="range-start-${index}-0"]`).selectOption('8');
    await page.locator(`[data-test="range-end-${index}-0"]`).selectOption('9');
    await page.locator(`[data-test="range-day-${index}-0"]`).selectOption('mo_fr');
    await page.locator(`[data-test="range-spots-${index}-0"]`).fill(spots);
}

test.describe.serial('PO-ALLONGE-1 SWF Allonge × Durchschnitt', () => {
    test('unbestätigte 30/0-Regel sperrt die Kalkulation', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Unbestätigt GmbH');
        await selectAllonge(page, 0, 'ATU');
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
            'nicht bestätigt',
        );
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toHaveCount(0);
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/neu/, { timeout: 15_000 });
        await expect(page.locator('body')).toContainText('nicht bestätigt', {
            timeout: 20_000,
        });
    });

    test('Admin-Speichern bestätigt ausdrücklich 30/0 ohne Checkbox', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'admin@example.com');
        await page.goto('/administration/kombinationen');
        await page
            .locator('[data-test="combination-filter-inventory"]')
            .selectOption({ label: 'Allonge Testsender Unbestätigt (ATU)' });
        const row = page
            .locator('[data-test^="combination-row-"]')
            .filter({
                has: page.getByRole('cell', { name: 'Allonge', exact: true }),
            })
            .first();
        await row.locator('[data-test^="combination-open-"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'nicht bestätigt',
        );
        await expect(page.getByRole('checkbox', { name: /bestätigt/i })).toHaveCount(0);
        await page.locator('#default_length_seconds').fill('30');
        await page.locator('#surcharge_percent').fill('0');
        await page.locator('[data-test="combination-save-submit"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'bestätigt am',
            { timeout: 20_000 },
        );
    });

    test('Preview, Save, Reload und Dispo mit Nutzerlänge 15 s', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge E2E GmbH');
        await selectAllonge(page, 0, 'ATA');
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('10');
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
        await expect(
            page.locator('[data-test="spot-components-activate-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(page.locator('[data-test="preview-net-total"]').first()).toContainText(
            '390,00',
            { timeout: 20_000 },
        );
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(page.locator('[data-test="preview-net-total"]').first()).toContainText(
            '390,00',
            { timeout: 20_000 },
        );

        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, { timeout: 30_000 });
        await expect(page.locator('body')).toContainText('Allonge Testsender A');
        await expect(page.locator('body')).toContainText('390,00');
        await expect(page.locator('[data-test="dispo-order-position-components-0"]')).toHaveCount(0);
    });

    test('Wechsel von Spot mit Allonge-Komponente räumt Komponenten und Festpreis ab', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Komponentenwechsel GmbH');
        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-ATA"]').click();
        await page.locator('[data-test="position-medium-0"]').selectOption({ label: 'Spot Classic' });
        await page.locator('[data-test="spot-components-activate-0"]').click();
        await page.locator('[data-test="spot-component-length-main_spot-0"]').fill('20');
        await page.locator('[data-test="spot-components-add-allonge-0"]').click();
        await page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]').check();
        await page.locator('[data-test="position-medium-0"]').selectOption({ label: allongeMedium });

        await expect(page.locator('[data-test="spot-components-section-0"]')).toHaveCount(0);
        await expect(page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]')).toHaveCount(0);
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('10');
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
    });

    test('gespeicherter Snapshot bleibt nach Entbestätigung nutzbar, Live-Rebind nicht', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Snapshot GmbH');
        await selectAllonge(page, 0, 'ATA');
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        const calculationUrl = page.url();

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, { timeout: 30_000 });
        const dispoUrl = page.url();
        await expect(page.locator('body')).toContainText('390,00');

        await logout(page);
        await login(page, 'admin@example.com');
        await page.goto('/administration/kombinationen');
        await page
            .locator('[data-test="combination-filter-inventory"]')
            .selectOption({ label: 'Allonge Testsender A (ATA)' });
        const row = page
            .locator('[data-test^="combination-row-"]')
            .filter({
                has: page.getByRole('cell', { name: 'Allonge', exact: true }),
            })
            .first();
        await row.locator('[data-test^="combination-open-"]').click();
        await page.locator('#surcharge_percent').fill('');
        await page.locator('[data-test="combination-save-submit"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'nicht bestätigt',
            { timeout: 20_000 },
        );

        await logout(page);
        await login(page);
        await page.goto(calculationUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
            'bleiben gültig',
        );
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).not.toContainText(
            'gesperrt',
        );
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(calculationUrl, { timeout: 30_000 });
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });

        await page.goto(dispoUrl);
        await expect(page.locator('body')).toContainText('390,00');

        await page.goto(calculationUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        const priceYear = page.locator('[data-test="position-price-year-0"]');
        const currentYear = await priceYear.inputValue();
        const otherYear = await priceYear
            .locator('option:not([disabled])')
            .evaluateAll(
                (options, current) =>
                    options
                        .map((option) => (option as HTMLOptionElement).value)
                        .find((value) => value !== '' && value !== current) ?? null,
                currentYear,
            );
        if (otherYear !== null) {
            await priceYear.selectOption(otherYear);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await page.reload();
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toBeVisible();
        }
        await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
        await selectAllonge(page, 1, 'ATA');
        await expect(page.locator('[data-test="allonge-unconfirmed-1"]')).toContainText(
            'Kalkulation ist gesperrt',
        );
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(calculationUrl, { timeout: 20_000 });
        await expect(page.locator('body')).toContainText('nicht bestätigt', {
            timeout: 20_000,
        });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toBeVisible();
        await expect(page.locator('[data-test="position-length-seconds-1"]')).toHaveCount(0);
        await page.goto(dispoUrl);
        await expect(page.locator('body')).toContainText('390,00');
    });
});
