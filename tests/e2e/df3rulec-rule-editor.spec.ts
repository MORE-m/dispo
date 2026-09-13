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
    test('System-Kern: Seed sichtbar, Vorschau und No-op-Apply', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await login(page, 'admin@example.com');

        await page.goto('/administration/dynamische-felder/feldsets');
        await page
            .getByRole('link', { name: /system_calculation_core|Kalkulations-Kern/i })
            .first()
            .click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        const openDraft = page.locator('[data-test="fieldset-open-draft"]');
        const createDraft = page.locator('[data-test="fieldset-create-draft"]');
        if (await openDraft.isVisible()) {
            await openDraft.click();
        } else if (await createDraft.isVisible()) {
            await createDraft.click();
        } else {
            await page.getByRole('button', { name: /Entwurf/i }).first().click();
        }

        await expect(page).toHaveURL(/versionen\/\d+/, { timeout: 30_000 });
        await expect(page.locator('[data-test="fieldset-rules-editor"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByText('Systemregel').first()).toBeVisible();
        await expect(page.locator('[data-test="fieldset-rule-0"]')).toBeVisible();

        const previewResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln-vorschau') &&
                response.request().method() === 'POST',
        );
        await page.locator('[data-test="fieldset-rules-preview"]').click();
        const preview = await previewResponse;
        expect(preview.ok()).toBeTruthy();
        await expect(
            page.locator('[data-test="fieldset-rules-preview-result"]'),
        ).toBeVisible();

        const applyResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln') &&
                !response.url().includes('vorschau') &&
                response.request().method() === 'PUT',
        );
        await page.locator('[data-test="fieldset-rules-apply"]').click();
        const apply = await applyResponse;
        expect(apply.ok()).toBeTruthy();
        const body = (await apply.json()) as { has_changes?: boolean };
        expect(body.has_changes).toBe(false);
    });
});
