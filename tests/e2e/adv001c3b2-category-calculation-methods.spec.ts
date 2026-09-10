import { expect, test, type Page } from '@playwright/test';

/**
 * ADV-001c3b2 isolierte Suite: Kategorie-Methoden Desired State.
 * Läuft nur über playwright.adv001c3b2.config.ts (eigene DB, Port 8010).
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

test.describe('ADV-001c3b2 category calculation methods', () => {
    test('methods section, planned calendar, preview, apply tkp, noop, focus', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/katalog/oberkategorien');
        await page.locator('a').filter({ hasText: 'Spots' }).first().click();
        await expect(page).toHaveURL(/oberkategorien\/\d+$/);

        const section = page.locator(
            '[data-test="category-calculation-methods-section"]',
        );
        await expect(section).toBeVisible();
        await expect(
            page.locator('[data-test="category-methods-boundary-note"]'),
        ).toContainText('Desired State');

        await expect(
            page.locator('[data-test="category-method-row-calendar"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="category-method-tech-calendar"]'),
        ).toContainText('Geplant');
        await expect(
            page.locator('[data-test="category-method-registry-calendar"]'),
        ).toContainText('planned');

        await expect(
            page.locator('[data-test="category-default-option-average"]'),
        ).toHaveCount(1);
        await expect(
            page.locator('[data-test="category-default-option-calendar"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="category-default-option-tkp"]'),
        ).toHaveCount(0);

        await page
            .locator('[data-test="category-methods-preview-button"]')
            .click();
        const preview = page.locator('[data-test="category-methods-preview"]');
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="category-methods-preview-changes"]'),
        ).toContainText('Keine Änderungen');
        await page.locator('[data-test="category-methods-preview-noop"]').click();
        await expect(preview).toHaveCount(0);

        await page
            .locator('[data-test="category-method-active-tkp"]')
            .check();
        await page
            .locator('[data-test="category-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="category-methods-preview-changes"]'),
        ).toContainText('Es liegen Änderungen vor.');
        await page
            .locator('[data-test="category-methods-preview-confirm"]')
            .click();
        await expect(page.getByText('Berechnungsmethoden gespeichert.')).toBeVisible({
            timeout: 15_000,
        });
        await expect(
            page.locator('[data-test="category-method-active-tkp"]'),
        ).toBeChecked();

        await page
            .locator('[data-test="category-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="category-methods-preview-changes"]'),
        ).toContainText('Keine Änderungen');
        await page.locator('[data-test="category-methods-preview-noop"]').click();
    });
});
