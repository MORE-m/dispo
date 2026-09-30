import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P2-02c isolierte Suite: Initialkatalog (14 Inventare / 42 Werbemittel).
 * Läuft nur über playwright.blp202c.config.ts (eigene DB, Port 8044).
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

test.describe.serial('BL-P2-02c Initialkatalog', () => {
    test('Admin sieht 14 Inventare und 42 Werbemittel inkl. Kategorien/Status', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/inventare');
        await expect(
            page.getByRole('heading', { name: 'Inventare' }),
        ).toBeVisible();
        await expect(page.getByText('MORE Hamburg-Kombi+', { exact: true })).toBeVisible();
        await expect(page.getByText('Radio Hamburg', { exact: true })).toBeVisible();
        await expect(
            page.getByText('MORE-Kombi Events ROCK ANTENNE Hamburg', {
                exact: true,
            }),
        ).toBeVisible();
        const inventoryRows = page.locator('[data-test^="inventory-row-"]');
        await expect(inventoryRows).toHaveCount(14);

        await page.goto('/administration/katalog/werbemittel');
        await expect(
            page.getByRole('heading', { name: 'Werbemittel' }),
        ).toBeVisible();
        await expect(page.getByText('Werbespot', { exact: true })).toBeVisible();
        await expect(
            page.getByText('Sondersendung (4x90Sek)', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Mid-Roll Spotify / Deezer / Youtube Musikumfeld',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(page.getByText('Online Facebook', { exact: true })).toBeVisible();
        const mediumRows = page.locator(
            '[data-test="medium-index-table"] tbody tr',
        );
        await expect(mediumRows).toHaveCount(42);
        await expect(
            page.locator('[data-test="medium-filter-category"]'),
        ).toContainText('Spots');
        await expect(
            page.locator('[data-test="medium-filter-category"]'),
        ).toContainText('Social Media / Online');
        await expect(page.getByText('Aktiv').first()).toBeVisible();
    });

    test('Sales hat keinen Zugang zur Katalogpflege', async ({ page }) => {
        await login(page, 'sales@example.com');

        const inventoryResponse = await page.goto('/administration/inventare');
        expect(inventoryResponse?.status()).toBeGreaterThanOrEqual(400);

        const mediaResponse = await page.goto(
            '/administration/katalog/werbemittel',
        );
        expect(mediaResponse?.status()).toBeGreaterThanOrEqual(400);
    });
});
