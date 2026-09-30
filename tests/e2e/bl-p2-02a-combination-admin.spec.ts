import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P2-02a isolierte Suite: Kombinationstabellen-Admin (MAT-CORE).
 * Läuft nur über playwright.blp202a.config.ts (eigene DB, Port 8043).
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

test.describe.serial('BL-P2-02a Kombinationstabellen-Admin', () => {
    test('Admin öffnet Hub, Liste, bearbeitet Kennzeichen und Hinweis', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Kombinationstabelle' }),
        ).toBeVisible();

        await page.goto('/administration/kombinationen');
        await expect(
            page.getByRole('heading', { name: 'Kombinationstabelle', exact: true }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="combination-filters"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="combination-table"]'),
        ).toBeVisible();

        await page.locator('[data-test^="combination-open-"]').first().click();
        await expect(page).toHaveURL(/\/administration\/kombinationen\/\d+$/);
        await expect(
            page.locator('[data-test="combination-edit-form"]'),
        ).toBeVisible();

        await page
            .locator('[data-test="combination-booking-input"]')
            .fill('L');
        await page
            .locator('[data-test="combination-hint-input"]')
            .fill('E2E-TEST-FIXTURE Hinweis MAT-CORE');
        await page.locator('[data-test="combination-save-submit"]').click();
        await expect(page.getByText('Kombination gespeichert.')).toBeVisible({
            timeout: 15_000,
        });

        await page.goto('/administration/kombinationen/neu');
        await expect(
            page.locator('[data-test="combination-create-form"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="combination-booking-input"]'),
        ).toBeVisible();
    });

    test('Sales hat keinen Zugang zur Kombinationstabelle', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        const response = await page.goto('/administration/kombinationen');
        expect(response?.status()).toBe(403);
    });
});
