import { expect, test, type Browser, type Page } from '@playwright/test';
import {
    approveWithExceptionAcknowledgement,
    setCustomerConfirmationException,
} from './helpers/customer-confirmation';

/**
 * PO-AUTH-SPECIAL-APPROVE-1 Browser-Smoke.
 * Nur über playwright.po-auth-special-approve.config.ts (SQLite, Port 8026).
 * Admin und Sales in getrennten BrowserContexts (keine Neuanmeldung nach Entzug).
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

async function openCampaign(page: Page, campaign: string) {
    await page.goto('/dispoauftraege');
    await page
        .locator('[data-test^="dispo-order-row-"]')
        .filter({ hasText: campaign })
        .getByRole('link')
        .first()
        .click();
    await expect(
        page.locator('[data-test="dispo-order-special-approval-hint"]'),
    ).toBeVisible({
        timeout: 15_000,
    });
}

async function submitForApproval(page: Page) {
    await setCustomerConfirmationException(page);
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await expect(
        page.locator('[data-test="dispo-order-submit-dialog"]'),
    ).toBeVisible();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(
        page.locator('[data-test="dispo-order-status-badge"]'),
    ).toHaveText('Wartet auf Vertriebsfreigabe', { timeout: 15_000 });
}

async function openApproveDialogReady(page: Page) {
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await expect(
        page.locator('[data-test="dispo-order-approve-dialog"]'),
    ).toBeVisible();
    const ack = page.locator(
        '[data-test="customer-confirmation-exception-ack"]',
    );
    if ((await ack.count()) > 0) {
        await expect(
            page.locator('[data-test="dispo-order-approve-confirm"]'),
        ).toBeDisabled();
        await ack.click();
    }
    await expect(
        page.locator('[data-test="dispo-order-approve-confirm"]'),
    ).toBeEnabled();
}

test.describe.serial('PO-AUTH-SPECIAL-APPROVE-1 Smoke', () => {
    test('Vergabe → Sales entscheidet → Entzug in fremdem Context → Sitzung gesperrt', async ({
        browser,
    }: {
        browser: Browser;
    }) => {
        test.setTimeout(300_000);

        const adminContext = await browser.newContext();
        const limitedContext = await browser.newContext();
        const salesBContext = await browser.newContext();
        const adminPage = await adminContext.newPage();
        const limitedPage = await limitedContext.newPage();
        const salesBPage = await salesBContext.newPage();

        try {
            await login(adminPage, 'admin@example.com');
            await adminPage.goto('/administration');
            await expect(
                adminPage.getByRole('heading', { name: 'Administration' }),
            ).toBeVisible();
            await adminPage.goto('/administration/sonderfreigaben');
            await expect(
                adminPage.getByRole('heading', {
                    name: 'Sonderfreigaberechte',
                }),
            ).toBeVisible();
            await expect(
                adminPage.locator(
                    '[data-test="special-approve-rights-table"]',
                ),
            ).toBeVisible();

            const salesBRow = adminPage.locator(
                '[data-email="sales-b@example.com"]',
            );
            await expect(salesBRow).toBeVisible();
            const salesBId = await salesBRow.getAttribute('data-test');
            const id = salesBId?.replace('special-approve-row-', '') ?? '';
            expect(id).not.toBe('');

            await adminPage
                .locator(`[data-test="special-approve-grant-${id}"]`)
                .click();
            await expect(
                adminPage.locator(
                    '[data-test="special-approve-rights-success"]',
                ),
            ).toBeVisible({ timeout: 15_000 });
            await expect(
                adminPage.locator(
                    `[data-test="special-approve-status-${id}"]`,
                ),
            ).toHaveText('Ja');

            await login(limitedPage, 'sales-limited@example.com');
            await openCampaign(limitedPage, 'Sonderfreigabe-Smoke-A');
            await submitForApproval(limitedPage);

            await login(salesBPage, 'sales-b@example.com');
            await openCampaign(salesBPage, 'Sonderfreigabe-Smoke-A');
            await expect(
                salesBPage.locator('[data-test="dispo-order-approve-open"]'),
            ).toBeVisible();
            await expect(
                salesBPage.locator('[data-test="dispo-order-reject-open"]'),
            ).toBeVisible();
            await approveWithExceptionAcknowledgement(salesBPage);
            await expect(
                salesBPage.locator('[data-test="dispo-order-status-badge"]'),
            ).toHaveText('Liegt bei Disposition', { timeout: 15_000 });

            await openCampaign(limitedPage, 'Sonderfreigabe-Smoke-B');
            await submitForApproval(limitedPage);

            await openCampaign(salesBPage, 'Sonderfreigabe-Smoke-B');
            await expect(
                salesBPage.locator('[data-test="dispo-order-approve-open"]'),
            ).toBeVisible();
            await openApproveDialogReady(salesBPage);

            await adminPage
                .locator(`[data-test="special-approve-revoke-${id}"]`)
                .click();
            await expect(
                adminPage.locator(
                    '[data-test="special-approve-rights-success"]',
                ),
            ).toBeVisible({ timeout: 15_000 });
            await expect(
                adminPage.locator(
                    `[data-test="special-approve-status-${id}"]`,
                ),
            ).toHaveText('Nein');

            await salesBPage
                .locator('[data-test="dispo-order-approve-confirm"]')
                .click();
            await expect(
                salesBPage.locator('[data-test="dispo-order-approve-error"]'),
            ).toBeVisible({ timeout: 15_000 });
            await expect(
                salesBPage.locator('[data-test="dispo-order-status-badge"]'),
            ).toHaveText('Wartet auf Vertriebsfreigabe');

            await salesBPage.reload();
            await expect(
                salesBPage.locator(
                    '[data-test="dispo-order-special-approval-hint"]',
                ),
            ).toBeVisible({ timeout: 15_000 });
            await expect(
                salesBPage.locator('[data-test="dispo-order-approve-open"]'),
            ).toHaveCount(0);
            await expect(
                salesBPage.locator('[data-test="dispo-order-reject-open"]'),
            ).toHaveCount(0);
            await expect(
                salesBPage.locator('[data-test="dispo-order-status-badge"]'),
            ).toHaveText('Wartet auf Vertriebsfreigabe');
        } finally {
            await adminContext.close();
            await limitedContext.close();
            await salesBContext.close();
        }
    });
});
