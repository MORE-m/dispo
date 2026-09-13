import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3-RULE-C isolierte Admin-Smoke-Suite.
 * playwright.df3rulec.config.ts (eigene DB, Port 8015).
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

test.describe('DF-3-RULE-C Regel-Editor', () => {
    test('System-Kern: Seed sichtbar, freie Regel speichern und Vorschau', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/dynamische-felder/feldsets');
        await page
            .locator('a[href*="/feldsets/"]')
            .filter({ hasText: /calculation|Kalkulation|system_calculation/i })
            .first()
            .click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        const draftLink = page.locator('[data-test="fieldset-open-draft"]');
        if (await draftLink.isVisible()) {
            await draftLink.click();
        } else {
            await page.locator('button, a').filter({ hasText: /Entwurf/i }).first().click();
        }

        await expect(page.locator('[data-test="fieldset-rules-editor"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByText('Systemregel').first()).toBeVisible();

        await page.locator('[data-test="fieldset-rules-add"]').click();
        await expect(page.locator('[data-test="fieldset-rule-1"]')).toBeVisible();

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/regeln-vorschau') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
            ),
            page.locator('[data-test="fieldset-rules-preview"]').click(),
        ]);
        await expect(
            page.locator('[data-test="fieldset-rules-preview-result"]'),
        ).toBeVisible();

        // Zweite Regel wieder entfernen, damit nur Seed bleibt (Apply ohne Duplikat-Konflikt).
        await page
            .locator('[data-test="fieldset-rule-1"]')
            .getByRole('button', { name: 'Entfernen' })
            .click();

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/regeln') &&
                    response.request().method() === 'PUT' &&
                    response.ok(),
            ),
            page.locator('[data-test="fieldset-rules-apply"]').click(),
        ]);
    });
});
