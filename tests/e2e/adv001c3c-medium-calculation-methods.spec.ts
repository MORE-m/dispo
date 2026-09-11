import { expect, test, type Page } from '@playwright/test';

/**
 * ADV-001c3c isolierte Suite: Medium-Methoden Desired State.
 * Läuft nur über playwright.adv001c3c.config.ts (eigene DB, Port 8011).
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

test.describe('ADV-001c3c medium calculation methods', () => {
    test('methods section, inherit, preview noop, override apply, focus', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await login(page, 'admin@example.com');

        await page.goto('/administration/katalog/werbemittel');
        await page.getByRole('link', { name: 'Spot Classic' }).first().click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/);

        const section = page.locator(
            '[data-test="medium-calculation-methods-section"]',
        );
        await expect(section).toBeVisible();
        await expect(
            page.locator('[data-test="medium-methods-boundary-note"]'),
        ).toContainText('Desired State');
        await expect(
            page.locator('[data-test="medium-effective-source"]'),
        ).toContainText('Oberkategorie');

        const modeSelect = page.locator(
            '[data-test="medium-calculation-method-mode"]',
        );
        await expect(modeSelect).toHaveValue('inherit');
        await expect(
            page.locator('[data-test="medium-inherit-stored-note"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-category-methods-list"]'),
        ).toBeVisible();

        const preview = page.locator('[data-test="medium-methods-preview"]');

        // Gespeicherte Override-Zuordnung in inherit anlegen → danach ist Preview-Noop möglich.
        await page
            .locator('[data-test="medium-method-active-average"]')
            .check();
        await page
            .locator('[data-test="medium-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="medium-methods-preview-confirm"]'),
        ).toBeEnabled();
        await page
            .locator('[data-test="medium-methods-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Berechnungsmethoden gespeichert.'),
        ).toBeVisible({ timeout: 15_000 });

        await page
            .locator('[data-test="medium-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="medium-methods-preview-changes"]'),
        ).toContainText('Keine Änderungen');
        await page
            .locator('[data-test="medium-methods-preview-noop"]')
            .click();
        await expect(preview).toHaveCount(0);

        // Override-Apply auf unbookable Medium (Seeder: Spot Classic ist buchbar, Default ohne Profil blockiert).
        const suffix = Date.now().toString().slice(-6);
        await page.goto('/administration/katalog/werbemittel/neu');
        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`E2E c3c ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`e2e_c3c_${suffix}`);
        await page
            .locator('[data-test="medium-category-select"]')
            .selectOption({ label: 'Online Audio (online_audio)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="medium-bookability-badge"]'),
        ).toHaveText('Noch nicht technisch verfügbar');

        await modeSelect.selectOption('override');
        await expect(
            page.locator('[data-test="medium-methods-list"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="medium-method-active-average"]')
            .check();

        await page
            .locator('[data-test="medium-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="medium-methods-preview-changes"]'),
        ).toContainText('Es liegen Änderungen vor.');
        await expect(
            page.locator('[data-test="medium-methods-preview-confirm"]'),
        ).toBeEnabled();
        await page
            .locator('[data-test="medium-methods-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Berechnungsmethoden gespeichert.'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(modeSelect).toHaveValue('override');
        await expect(
            page.locator('[data-test="medium-method-active-average"]'),
        ).toBeChecked();

        await page
            .locator('[data-test="medium-methods-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="medium-methods-preview-changes"]'),
        ).toContainText('Keine Änderungen');
        await page
            .locator('[data-test="medium-methods-preview-noop"]')
            .click();
    });
});
