import { expect, test, type Page } from '@playwright/test';
import { CUSTOMER_CONFIRMATION_REASON } from './helpers/customer-confirmation';

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

test.describe('BL-P8-02c Kundenbestätigung Ausnahmeweg', () => {
    test('Happy Path: Ausnahme setzen, Submit, Mitfreigabe, Historie', async ({
        page,
    }) => {
        test.setTimeout(300_000);

        await login(page, 'sales@example.com');
        await openOrderByCustomer(page, 'BLP802C Happy Path GmbH');

        await expect(
            page.locator('[data-test="dispo-order-customer-confirmation"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="customer-confirmation-status"]'),
        ).toContainText('Noch nicht bestätigt');

        await page.locator('[data-test="dispo-order-submit-open"]').click();
        await page.locator('[data-test="dispo-order-submit-confirm"]').click();
        await expect(
            page.locator('[data-test="dispo-order-submit-error"]'),
        ).toContainText(/Kundenbestätigung/i, { timeout: 15_000 });
        await page
            .locator('[data-test="dispo-order-submit-dialog"]')
            .getByRole('button', { name: 'Abbrechen' })
            .click();

        await page
            .locator('[data-test="customer-confirmation-without-upload"]')
            .click();
        await expect(
            page.locator('[data-test="customer-confirmation-exception-reason"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="customer-confirmation-save"]'),
        ).toBeDisabled();

        await page
            .locator('[data-test="customer-confirmation-exception-reason"]')
            .fill('   ');
        await expect(
            page.locator('[data-test="customer-confirmation-save"]'),
        ).toBeDisabled();

        await page
            .locator('[data-test="customer-confirmation-exception-reason"]')
            .fill(CUSTOMER_CONFIRMATION_REASON);
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/kundenbestaetigung') &&
                    response.ok(),
            ),
            page.locator('[data-test="customer-confirmation-save"]').click(),
        ]);

        await expect(
            page.locator('[data-test="customer-confirmation-exception-reason"]'),
        ).toHaveValue(CUSTOMER_CONFIRMATION_REASON, { timeout: 15_000 });
        await page.reload();
        await expect(
            page.locator('[data-test="customer-confirmation-exception-reason"]'),
        ).toHaveValue(CUSTOMER_CONFIRMATION_REASON);

        await page.locator('[data-test="dispo-order-submit-open"]').click();
        await page.locator('[data-test="dispo-order-submit-confirm"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Wartet auf Vertriebsfreigabe',
            { timeout: 15_000 },
        );
        await expect(
            page.locator('[data-test="customer-confirmation-readonly"]'),
        ).toBeVisible();
        await expect(
            page.locator(
                '[data-test="customer-confirmation-exception-reason-readonly"]',
            ),
        ).toContainText(CUSTOMER_CONFIRMATION_REASON);
        await expect(
            page.locator('[data-test="four-eyes-creator-hint"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-approve-open"]'),
        ).toHaveCount(0);

        const detailUrl = page.url();
        await logout(page);
        await login(page, 'sales-b@example.com');
        await page.goto(detailUrl);

        await page.locator('[data-test="dispo-order-approve-open"]').click();
        await expect(
            page.locator('[data-test="customer-confirmation-exception-ack"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-approve-confirm"]'),
        ).toBeDisabled();

        await page
            .locator('[data-test="customer-confirmation-exception-ack"]')
            .click();
        await page.locator('[data-test="dispo-order-approve-confirm"]').click();

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
            { timeout: 15_000 },
        );
        await expect(
            page.locator('[data-test="approval-history-exception-reason"]'),
        ).toContainText(CUSTOMER_CONFIRMATION_REASON);
        await expect(
            page.locator('[data-test="approval-history-exception-ack"]'),
        ).toContainText('E2E Vertrieb B');
    });
});

test.describe('BL-P8-02c Negativ Disposition', () => {
    test('Disposition kann Draft-Ausnahme nicht setzen', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802C Disposition Negativ GmbH');
        await expect(
            page.locator('[data-test="dispo-order-customer-confirmation"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="customer-confirmation-without-upload"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="customer-confirmation-status"]'),
        ).toContainText('Noch nicht bestätigt');
    });
});
