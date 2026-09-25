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

async function logout(page: Page) {
    await page.context().clearCookies();
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible({ timeout: 30_000 });
}

async function openOrderByCustomer(page: Page, customerName: string) {
    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"]')).toBeVisible({
        timeout: 15_000,
    });
    const row = page
        .locator('[data-test^="dispo-order-row-"]')
        .filter({ hasText: customerName })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('link').first().click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

test.describe('BL-P8-02e Completed-Reopen + Storno', () => {
    test('A) Completed Reopen: Disposition nein, Admin mit Begründung', async ({
        page,
    }) => {
        test.setTimeout(300_000);

        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802E Completed Reopen GmbH');
        await expect(
            page.locator('[data-test="dispo-order-reopen-completed-action"]'),
        ).toHaveCount(0);
        await logout(page);

        await login(page, 'admin@example.com');
        await openOrderByCustomer(page, 'BLP802E Completed Reopen GmbH');
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Abgeschlossen');

        await page
            .locator('[data-test="dispo-order-reopen-completed-action"]')
            .click();
        await expect(
            page.locator('[data-test="dispo-order-reopen-completed-dialog"]'),
        ).toBeVisible();

        const confirm = page.locator(
            '[data-test="dispo-order-reopen-completed-confirm"]',
        );
        await expect(confirm).toBeDisabled();

        await page
            .locator('[data-test="dispo-order-reopen-completed-reason"]')
            .fill('   ');
        await expect(confirm).toBeDisabled();

        const reopenReason =
            'Auftrag muss operativ nachbearbeitet werden.';
        await page
            .locator('[data-test="dispo-order-reopen-completed-reason"]')
            .fill(reopenReason);
        await expect(confirm).toBeEnabled();
        await confirm.click();

        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('In Bearbeitung', { timeout: 15_000 });

        const history = page.locator('[data-test="dispo-order-status-history"]');
        await expect(history).toContainText('Wiederöffnung');
        await expect(history).toContainText(reopenReason);

        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('In Bearbeitung', { timeout: 15_000 });
    });

    test('B) AT-19: Storno nach Abgeschlossen mit Pflichtgrund', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802E AT19 Cancel GmbH');
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Abgeschlossen');

        await page.locator('[data-test="dispo-order-cancel-action"]').click();
        await expect(
            page.locator('[data-test="dispo-order-cancel-dialog"]'),
        ).toBeVisible();

        const confirm = page.locator('[data-test="dispo-order-cancel-confirm"]');
        await expect(confirm).toBeDisabled();

        const cancelReason =
            'Kampagne wurde nachträglich vom Kunden storniert.';
        await page
            .locator('[data-test="dispo-order-cancel-reason"]')
            .fill(cancelReason);
        await expect(confirm).toBeEnabled();
        await confirm.click();

        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Storniert', { timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-cancellation-summary"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-cancelled-reason"]'),
        ).toContainText(cancelReason);
        await expect(
            page.locator('[data-test="dispo-order-cancelled-from"]'),
        ).toContainText('Abgeschlossen');

        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-cancellation-summary"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-cancel-action"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-reopen-completed-action"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-complete-action"]'),
        ).toHaveCount(0);
    });

    test('C) Disposed Storno: Disposition storniert aus Disponiert', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802E Disposed Cancel GmbH');
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Disponiert');

        await page.locator('[data-test="dispo-order-cancel-action"]').click();
        await page
            .locator('[data-test="dispo-order-cancel-reason"]')
            .fill('Auftrag vor Ausstrahlung storniert.');
        await page.locator('[data-test="dispo-order-cancel-confirm"]').click();

        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Storniert', { timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-cancelled-from"]'),
        ).toContainText('Disponiert');
    });

    test('D) Negativ: Sales und PM ohne Storno-Aktion', async ({ page }) => {
        test.setTimeout(180_000);

        await login(page, 'sales@example.com');
        await openOrderByCustomer(page, 'BLP802E Sales Deny GmbH');
        await expect(
            page.locator('[data-test="dispo-order-cancel-action"]'),
        ).toHaveCount(0);
        await logout(page);

        await login(page, 'pm@example.com');
        await page.goto('/dispoauftraege');
        const table = page.locator('[data-test="dispo-orders-table"]');
        if (await table.isVisible().catch(() => false)) {
            await openOrderByCustomer(page, 'BLP802E Sales Deny GmbH');
            await expect(
                page.locator('[data-test="dispo-order-cancel-action"]'),
            ).toHaveCount(0);
            await expect(
                page.locator('[data-test="dispo-order-reopen-completed-action"]'),
            ).toHaveCount(0);
        }
    });

    test('E) Management: Reopen und Storno sichtbar', async ({ page }) => {
        test.setTimeout(180_000);

        await login(page, 'management@example.com');
        await openOrderByCustomer(page, 'BLP802E Management GmbH');
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Abgeschlossen');
        await expect(
            page.locator('[data-test="dispo-order-reopen-completed-action"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-cancel-action"]'),
        ).toBeVisible();
    });
});
