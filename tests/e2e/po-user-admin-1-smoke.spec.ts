import { expect, test, type Page } from '@playwright/test';

/**
 * PO-USER-ADMIN-1 Browser-Smoke.
 * Nur über playwright.po-user-admin-1.config.ts (SQLite, Port 8028).
 */

async function login(page: Page, email: string, password = 'password') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

test.describe.serial('PO-USER-ADMIN-1 Smoke', () => {
    test('Admin legt Vertrieb an, pflegt Felder, deaktiviert Login', async ({
        page,
        browser,
    }) => {
        test.setTimeout(180_000);

        const email = `ua1-sales-${Date.now()}@example.com`;

        await login(page, 'admin@example.com');

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Benutzer' }),
        ).toBeVisible();

        await page.goto('/administration/benutzer');
        await expect(
            page.getByRole('heading', { name: 'Benutzer' }),
        ).toBeVisible();
        await expect(page.locator('[data-test="user-admin-table"]')).toBeVisible();

        await page.locator('[data-test="user-create-link"]').click();
        await expect(page.locator('[data-test="user-create-form"]')).toBeVisible();

        await page.locator('[data-test="user-name-input"]').fill('UA1 Vertrieb');
        await page.locator('[data-test="user-email-input"]').fill(email);
        await page.locator('[data-test="user-role-input"]').selectOption('sales');
        await page.locator('[data-test="user-password-input"]').fill('password');
        await page
            .locator('[data-test="user-password-confirmation-input"]')
            .fill('password');
        await page.locator('[data-test="user-discount-input"]').fill('12');
        await page.locator('[data-test="user-create-submit"]').click();

        await expect(page.locator('[data-test="user-edit-form"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.locator('[data-test="user-admin-success"]')).toBeVisible();

        await page.goto('/administration/benutzer');
        const row = page.locator('[data-email="' + email + '"]');
        await expect(row).toBeVisible();
        await expect(row.getByRole('cell', { name: 'Vertrieb', exact: true })).toBeVisible();
        await expect(row.getByRole('cell', { name: 'Aktiv', exact: true })).toBeVisible();

        await row.locator('[data-test^="user-edit-link-"]').click();
        await expect(page.locator('[data-test="user-edit-form"]')).toBeVisible();
        await page.locator('[data-test="user-special-approve-input"]').check();
        await page.locator('[data-test="user-discount-input"]').fill('8');
        await page.locator('[data-test="user-active-input"]').uncheck();
        await page.locator('[data-test="user-edit-submit"]').click();
        await expect(page.locator('[data-test="user-admin-success"]')).toBeVisible({
            timeout: 15_000,
        });

        await page.goto('/administration/benutzer');
        const inactiveRow = page.locator('[data-email="' + email + '"]');
        await expect(inactiveRow.getByText('Inaktiv')).toBeVisible();
        await expect(inactiveRow.getByText('Ja')).toBeVisible();

        // Isolierter Context: Login des deaktivierten Nutzers muss scheitern.
        const blocked = await browser.newContext();
        const blockedPage = await blocked.newPage();
        try {
            await blockedPage.goto('/login');
            await blockedPage.locator('#email').fill(email);
            await blockedPage.locator('#password').fill('password');
            await blockedPage.locator('[data-test="login-button"]').click();
            await expect(blockedPage).toHaveURL(/\/login/, { timeout: 15_000 });
            await expect(
                blockedPage.locator('[data-test="login-button"]'),
            ).toBeVisible();
        } finally {
            await blocked.close();
        }

        await page.goto('/administration/benutzer');
        await page
            .locator('[data-email="' + email + '"]')
            .locator('[data-test^="user-edit-link-"]')
            .click();
        await page.locator('[data-test="user-active-input"]').check();
        await page.locator('[data-test="user-edit-submit"]').click();
        await expect(page.locator('[data-test="user-admin-success"]')).toBeVisible({
            timeout: 15_000,
        });

        const okContext = await browser.newContext();
        const okPage = await okContext.newPage();
        try {
            await login(okPage, email);
            await expect(okPage).not.toHaveURL(/\/login/);
        } finally {
            await okContext.close();
        }
    });
});
