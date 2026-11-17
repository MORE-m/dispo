import { expect, test, type Page } from '@playwright/test';

/**
 * Isolierte Suite: Inventarauswahl + PO-CALC-DISPO-UX-1 Werbeelement-UX.
 * Nur über playwright.wizard-inventory-collapse.config.ts (SQLite, Port 8025).
 */

async function loginAsSales(page: Page) {
    await page.goto('/login');
    await page.locator('#email').fill('sales@example.com');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

async function openWerbeelemente(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await expect(
        page.locator('[data-test="position-inventory-picker-0"]'),
    ).toBeVisible();
}

async function selectInventoryTile(
    page: Page,
    positionIndex: number,
    code: string,
) {
    await page
        .locator(
            `[data-test="position-inventory-tile-${positionIndex}-${code}"]`,
        )
        .click();
}

test.describe.serial('PO-CALC-DISPO-UX-1 Wizard Werbeelemente', () => {
    test('A: frische Kalkulation ohne Inventar-Default, Auswahl sichtbar', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);

        const picker = page.locator(
            '[data-test="position-inventory-picker-0"]',
        );
        await expect(picker).toHaveAttribute('data-expanded', 'true');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="position-inventory-0"]'),
        ).toHaveValue('');
        await expect(
            page.locator('[data-test="position-inventory-required-0"]'),
        ).toBeVisible();

        await expect(
            page.locator('[data-test="position-inventory-tile-0-RH"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="position-inventory-tile-0-RAH"]'),
        ).toBeVisible();

        await selectInventoryTile(page, 0, 'RH');
        await expect(picker).toHaveAttribute('data-expanded', 'false');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('Radio Hamburg');
        await expect(
            page.locator('[data-test="position-medium-0"]'),
        ).toBeVisible();
    });

    test('B: zweites Werbeelement übernimmt Inventar und bleibt änderbar', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);
        await selectInventoryTile(page, 0, 'RH');

        await page.locator('[data-test="add-manual-position"]').click();
        await expect(
            page.locator('[data-test="position-inventory-picker-1"]'),
        ).toBeVisible();

        const selectedId = await page
            .locator('[data-test="position-inventory-1"]')
            .inputValue();
        const rhOption = page.locator(
            '[data-test="position-inventory-1"] option',
            { hasText: 'Radio Hamburg' },
        );
        await expect(rhOption).toHaveAttribute('value', selectedId);

        await page
            .locator('[data-test="position-inventory-expand-1"]')
            .click();
        await selectInventoryTile(page, 1, 'RAH');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-1"]'),
        ).toContainText('ROCK ANTENNE Hamburg');
    });

    test('C: Entfernen-Button im Header löscht Werbeelement 2', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);
        await selectInventoryTile(page, 0, 'RH');
        await page.locator('[data-test="add-manual-position"]').click();
        await expect(
            page.locator('[data-test="position-inventory-picker-1"]'),
        ).toBeVisible();

        await expect(
            page.locator('[data-test="position-remove-1"]'),
        ).toBeVisible();
        await page.locator('[data-test="position-remove-1"]').click();

        await expect(
            page.locator('[data-test="position-inventory-picker-1"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="position-inventory-picker-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="position-remove-0"]'),
        ).toHaveCount(0);
    });

    test('D: gespeicherte Kalkulation behält Inventar (kein Reset)', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAsSales(page);
        await openWerbeelemente(page);
        await selectInventoryTile(page, 0, 'RAH');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 60_000 });

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('ROCK ANTENNE Hamburg');
        await expect(
            page.locator('[data-test="position-inventory-picker-0"]'),
        ).toHaveAttribute('data-expanded', 'false');
    });

    test('E: Budget-Werbeelement startet ohne Inventar', async ({ page }) => {
        await loginAsSales(page);
        await page.goto('/kalkulationen/neu');
        await page
            .getByRole('radio', { name: /Mit Budget planen/i })
            .click({ force: true });
        await page.getByLabel('Zielbudget N/N').fill('500');
        await page.getByRole('button', { name: 'Weiter' }).click();

        await expect(
            page.locator('[data-test="budget-element-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('#budget-element-inventory-0'),
        ).toHaveValue('');

        await page.locator('[data-test="add-budget-element"]').click();
        await expect(
            page.locator('#budget-element-inventory-1'),
        ).toHaveValue('');
        await expect(
            page.locator('[data-test="budget-element-remove-1"]'),
        ).toBeVisible();
    });

    test('Inventarwechsel und Unplannable bleiben nutzbar', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);

        await selectInventoryTile(page, 0, 'RH');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('Radio Hamburg');

        await page
            .locator('[data-test="position-inventory-expand-0"]')
            .click();
        await expect(
            page.locator('[data-test="position-inventory-picker-0"]'),
        ).toHaveAttribute('data-expanded', 'true');

        const online = page.locator(
            '[data-test="position-inventory-tile-0-ONLINE_E2E"]',
        );
        await expect(online).toBeDisabled();
        await expect(online).toContainText('Derzeit nicht kalkulierbar');

        await selectInventoryTile(page, 0, 'RAH');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('ROCK ANTENNE Hamburg');
    });
});
