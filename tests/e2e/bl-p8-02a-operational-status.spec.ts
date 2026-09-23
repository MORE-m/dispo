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

test.describe('BL-P8-02a operativer Statuskern', () => {
    test('Disposition: Bearbeitung → Material → Disponiert → Wiederöffnung', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'disposition@example.com');
        await openSeededOrder(page);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
        );
        await expect(
            page.locator('[data-test="dispo-order-operational-status-actions"]'),
        ).toBeVisible();

        await page.locator('[data-test="dispo-order-status-action-in_progress"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
            { timeout: 15_000 },
        );

        await page.locator('[data-test="dispo-order-status-action-material_missing"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Material fehlt',
            { timeout: 15_000 },
        );

        await page
            .locator('[data-test="dispo-order-status-action-material_received"]')
            .click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Material erhalten',
            { timeout: 15_000 },
        );

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Disponiert',
            { timeout: 15_000 },
        );

        await page.locator('[data-test="dispo-order-status-action-in_progress"]').click();
        await expect(page.locator('[data-test="dispo-order-reopen-dialog"]')).toBeVisible();
        await expect(page.locator('[data-test="dispo-order-reopen-confirm"]')).toBeDisabled();
        await page.locator('[data-test="dispo-order-reopen-reason"]').fill('   ');
        await expect(page.locator('[data-test="dispo-order-reopen-confirm"]')).toBeDisabled();
        await page
            .locator('[data-test="dispo-order-reopen-reason"]')
            .fill('Kunde ändert Spotzeiten');
        await page.locator('[data-test="dispo-order-reopen-confirm"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
            { timeout: 15_000 },
        );

        const history = page.locator('[data-test="dispo-order-status-history"]');
        await expect(history).toBeVisible();
        await expect(history).toContainText('Liegt bei Disposition → In Bearbeitung');
        await expect(history).toContainText('In Bearbeitung → Material fehlt');
        await expect(history).toContainText('Material fehlt → Material erhalten');
        await expect(history).toContainText('Material erhalten → Disponiert');
        await expect(history).toContainText('Disponiert → In Bearbeitung');
        await expect(history).toContainText('Wiederöffnung');
        await expect(history).toContainText('Kunde ändert Spotzeiten');
    });

    test('Vertrieb sieht keine operativen Statusaktionen', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await openSeededOrder(page);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-status-actions"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test^="dispo-order-status-action-"]'),
        ).toHaveCount(0);
    });
});
