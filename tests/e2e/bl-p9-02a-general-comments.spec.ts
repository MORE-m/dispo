import { expect, test, type Page } from '@playwright/test';

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

async function openSeededOrder(page: Page) {
    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"]')).toBeVisible({
        timeout: 15_000,
    });
    const row = page.locator('[data-test^="dispo-order-row-"]').first();
    await expect(row).toBeVisible();
    await row.getByRole('link').first().click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

test.describe('BL-P9-02a allgemeine Kommentare', () => {
    test('Disposition schreibt Kommentar; Status bleibt; Historie zeigt Eintrag', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'disposition@example.com');
        await openSeededOrder(page);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
        );
        await expect(
            page.locator('[data-test="dispo-order-comment-form"]'),
        ).toBeVisible();

        await page
            .locator('[data-test="dispo-order-comment-body"]')
            .fill('E2E allgemeiner Kommentar');
        await page.locator('[data-test="dispo-order-comment-submit"]').click();

        await expect(
            page.locator('[data-test="dispo-order-communication-history"]'),
        ).toContainText('E2E allgemeiner Kommentar', { timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-communication-history"]'),
        ).toContainText('Kommentar');
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
        );
    });

    test('PM ohne Extra-Recht sieht keine Dispoliste', async ({ page }) => {
        test.setTimeout(120_000);

        await page.context().clearCookies();
        await login(page, 'pm@example.com');
        const response = await page.goto('/dispoauftraege');
        expect(response?.status()).toBe(403);
    });
});
