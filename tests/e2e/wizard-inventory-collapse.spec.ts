import { expect, test, type Page } from '@playwright/test';

/**
 * Isolierte Suite: Inventarauswahl einklappen / aufklappen / wechseln.
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

test.describe.serial('Wizard Inventarauswahl einklappen', () => {
    test('startet eingeklappt, klappt auf und wechselt Inventar', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);

        const picker = page.locator(
            '[data-test="position-inventory-picker-0"]',
        );
        await expect(picker).toHaveAttribute('data-expanded', 'false');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('Radio Hamburg');

        await page
            .locator('[data-test="position-inventory-expand-0"]')
            .click();
        await expect(picker).toHaveAttribute('data-expanded', 'true');

        await page
            .locator('[data-test="position-inventory-tile-0-RAH"]')
            .click();
        await expect(picker).toHaveAttribute('data-expanded', 'false');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('ROCK ANTENNE Hamburg');

        const selectedId = await page
            .locator('[data-test="position-inventory-0"]')
            .inputValue();
        const rahOption = page.locator(
            '[data-test="position-inventory-0"] option',
            { hasText: 'ROCK ANTENNE Hamburg' },
        );
        await expect(rahOption).toHaveAttribute('value', selectedId);
    });

    test('Spot-Inventar ist wählbar; Inventar ohne kalkulierbares Medium bleibt gesperrt', async ({
        page,
    }) => {
        await loginAsSales(page);
        await openWerbeelemente(page);

        await page
            .locator('[data-test="position-inventory-expand-0"]')
            .click();

        const spot = page.locator(
            '[data-test="position-inventory-tile-0-RH"]',
        );
        const online = page.locator(
            '[data-test="position-inventory-tile-0-ONLINE_E2E"]',
        );

        await expect(spot).toBeEnabled();
        await expect(online).toBeDisabled();
        await expect(online).toContainText('Derzeit nicht kalkulierbar');

        const before = await page
            .locator('[data-test="position-inventory-0"]')
            .inputValue();
        await online.click({ force: true });
        await expect(
            page.locator('[data-test="position-inventory-0"]'),
        ).toHaveValue(before);

        await spot.click();
        await expect(
            page.locator('[data-test="position-inventory-picker-0"]'),
        ).toHaveAttribute('data-expanded', 'false');
        await expect(
            page.locator('[data-test="position-inventory-collapsed-0"]'),
        ).toContainText('Radio Hamburg');
    });
});
