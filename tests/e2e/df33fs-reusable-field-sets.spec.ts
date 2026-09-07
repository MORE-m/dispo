import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3.3-fs isolierte Admin-Smoke-Suite.
 * Läuft nur über playwright.df33fs.config.ts (eigene DB, Port 8003).
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

async function createCustomDefinition(page: Page, label: string) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#scope').selectOption('header');
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

test.describe('DF-3.3-fs freie Feldsets', () => {
    test('anlegen, aktivieren, deaktivieren, Version aktivieren, reaktivieren', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await createCustomDefinition(page, 'FS Targeting Hinweis');

        await page.goto('/administration/dynamische-felder/feldsets');
        await expect(page.locator('[data-test="fieldset-core-section"]')).toBeVisible();
        await page.locator('[data-test="fieldset-create-link"]').click();

        await page.locator('[data-test="fieldset-name-input"]').fill('Targeting Admin');
        await page.locator('[data-test="fieldset-key-input"]').fill('targeting_admin_e2e');
        await page.locator('[data-test="fieldset-applies-to-select"]').selectOption('both');
        await page.locator('[data-test="fieldset-create-submit"]').click();

        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
        await expect(page.locator('[data-test="fieldset-usability-label"]')).toContainText(
            'Entwurf / noch nicht nutzbar',
        );
        await expect(page.locator('[data-test="fieldset-runtime-note"]')).toBeVisible();

        await page.locator('[data-test="fieldset-open-draft"]').click();
        await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
            timeout: 15_000,
        });

        await page.locator('[data-test="fieldset-add-custom-field"]').click();
        const definitionSelect = page.locator(
            '[data-test="fieldset-add-definition-select"]',
        );
        await expect(definitionSelect).toBeVisible({ timeout: 15_000 });
        const optionValue = await definitionSelect
            .locator('option')
            .filter({ hasText: 'fs_targeting_hinweis' })
            .or(definitionSelect.locator('option').filter({ hasText: 'FS Targeting' }))
            .first()
            .getAttribute('value');
        expect(optionValue).toBeTruthy();
        await definitionSelect.selectOption(optionValue!);
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/felder') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
            ),
            page.locator('[data-test="fieldset-add-membership-submit"]').click(),
        ]);

        await page.locator('a[href*="/vorschau"]').first().click();
        await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
        await expect(
            page.locator('[data-test="fieldset-preview-runtime-note"]'),
        ).toBeVisible();

        const activate = page.locator('[data-test="fieldset-version-activate"]');
        page.once('dialog', (dialog) => dialog.accept());
        await activate.click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
        await expect(page.locator('[data-test="fieldset-usability-label"]')).toContainText(
            'assignierbar',
        );

        await page.locator('[data-test="fieldset-deactivate"]').click();
        await expect(page.locator('[data-test="fieldset-usability-label"]')).toContainText(
            'deaktiviert',
            { timeout: 15_000 },
        );

        await page.locator('[data-test="fieldset-create-draft"]').click();
        await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
            timeout: 15_000,
        });
        await page.locator('a[href*="/vorschau"]').first().click();
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('[data-test="fieldset-version-activate"]').click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
        await expect(page.locator('[data-test="fieldset-usability-label"]')).toContainText(
            'deaktiviert',
        );

        await page.locator('[data-test="fieldset-reactivate"]').click();
        await expect(page.locator('[data-test="fieldset-usability-label"]')).toContainText(
            'assignierbar',
            { timeout: 15_000 },
        );
    });
});
