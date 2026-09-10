import { expect, test, type Page } from '@playwright/test';

/**
 * ADV-001c3b1 isolierte Suite: Methodenstammdaten und Lifecycle.
 * Läuft nur über playwright.adv001c3b1.config.ts (eigene DB, Port 8009).
 */

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

test.describe('ADV-001c3b1 calculation method lifecycle', () => {
    test('hub tile, index, detail, metadata, blocked and allowed deactivate', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/katalog');
        await expect(
            page.locator('[data-test="catalog-tile-methods"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="catalog-tile-methods"]')
            .getByRole('link', { name: 'Öffnen' })
            .click();
        await expect(page).toHaveURL(/berechnungsmethoden/);
        await expect(
            page.locator('[data-test="methods-boundary-note"]'),
        ).toContainText('nicht automatisch buchbar');

        const averageRow = page.locator('[data-test="method-row-average"]');
        await expect(averageRow).toBeVisible();
        await expect(
            page.locator('[data-test="method-row-tkp"]'),
        ).toBeVisible();

        await averageRow.getByRole('link', { name: 'Öffnen' }).click();
        await expect(
            page.locator('[data-test="method-key-readonly"]'),
        ).toHaveValue('average');
        await expect(
            page.locator('[data-test="method-registry-badge"]'),
        ).toContainText('spot_classic');

        await page
            .locator('[data-test="method-deactivate-preview-button"]')
            .click();
        const preview = page.locator('[data-test="method-deactivate-preview"]');
        await expect(preview).toBeVisible();
        await expect(preview).toContainText('Zuordnungen');
        await expect(
            page.locator('[data-test="method-deactivate-confirm"]'),
        ).toBeDisabled();
        await page.locator('[data-test="method-deactivate-cancel"]').click();
        await expect(preview).toHaveCount(0);

        await page.goto('/administration/katalog/berechnungsmethoden');
        await page
            .locator('[data-test="method-row-free_position"]')
            .getByRole('link', { name: 'Öffnen' })
            .click();

        await page
            .locator('[data-test="method-name-input"]')
            .fill('Freie Position E2E');
        await page
            .locator('[data-test="method-help-input"]')
            .fill('E2E Hilfetext');
        await page.locator('[data-test="method-sort-input"]').fill('42');
        await page.locator('[data-test="method-save-button"]').click();
        await expect(
            page.getByText('Berechnungsmethode gespeichert.'),
        ).toBeVisible({
            timeout: 15_000,
        });

        await page
            .locator('[data-test="method-deactivate-preview-button"]')
            .click();
        const freePreview = page.locator(
            '[data-test="method-deactivate-preview"]',
        );
        await expect(freePreview).toBeVisible();
        const confirm = page.locator('[data-test="method-deactivate-confirm"]');
        await expect(confirm).toBeEnabled();
        await expect(confirm).toBeFocused();
        await confirm.click();
        await expect(
            page.locator('[data-test="method-status-badge"]'),
        ).toContainText('Inaktiv');

        await page.locator('[data-test="method-reactivate-button"]').click();
        await expect(
            page.locator('[data-test="method-status-badge"]'),
        ).toContainText('Aktiv');
    });
});
