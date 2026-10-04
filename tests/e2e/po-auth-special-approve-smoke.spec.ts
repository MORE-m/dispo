import { expect, test, type Page } from '@playwright/test';
import {
    approveWithExceptionAcknowledgement,
    setCustomerConfirmationException,
} from './helpers/customer-confirmation';

/**
 * PO-AUTH-SPECIAL-APPROVE-1 Browser-Smoke.
 * Nur über playwright.po-auth-special-approve.config.ts (SQLite, Port 8026).
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
    await expect(page.locator('[data-test="dispo-order-special-approval-hint"]')).toBeVisible({
        timeout: 15_000,
    });
}

async function submitForApproval(page: Page) {
    await setCustomerConfirmationException(page);
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await expect(page.locator('[data-test="dispo-order-submit-dialog"]')).toBeVisible();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Wartet auf Vertriebsfreigabe',
        { timeout: 15_000 },
    );
}

test.describe.serial('PO-AUTH-SPECIAL-APPROVE-1 Smoke', () => {
    test('Vergabe → Sales entscheidet Sonderfreigabe → Entzug → Entscheidung gesperrt', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        await login(page, 'admin@example.com');
        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await page.goto('/administration/sonderfreigaben');
        await expect(
            page.getByRole('heading', { name: 'Sonderfreigaberechte' }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="special-approve-rights-table"]'),
        ).toBeVisible();

        const salesBRow = page.locator('[data-email="sales-b@example.com"]');
        await expect(salesBRow).toBeVisible();
        const salesBId = await salesBRow.getAttribute('data-test');
        const id = salesBId?.replace('special-approve-row-', '') ?? '';
        expect(id).not.toBe('');

        await page.locator(`[data-test="special-approve-grant-${id}"]`).click();
        await expect(
            page.locator('[data-test="special-approve-rights-success"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator(`[data-test="special-approve-status-${id}"]`),
        ).toHaveText('Ja');

        await page.context().clearCookies();
        await login(page, 'sales-limited@example.com');
        await openCampaign(page, 'Sonderfreigabe-Smoke-A');
        await submitForApproval(page);

        await page.context().clearCookies();
        await login(page, 'sales-b@example.com');
        await openCampaign(page, 'Sonderfreigabe-Smoke-A');
        await expect(page.locator('[data-test="dispo-order-approve-open"]')).toBeVisible();
        await approveWithExceptionAcknowledgement(page);
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
            { timeout: 15_000 },
        );

        await page.context().clearCookies();
        await login(page, 'admin@example.com');
        await page.goto('/administration/sonderfreigaben');
        await page.locator(`[data-test="special-approve-revoke-${id}"]`).click();
        await expect(
            page.locator('[data-test="special-approve-rights-success"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator(`[data-test="special-approve-status-${id}"]`),
        ).toHaveText('Nein');

        await page.context().clearCookies();
        await login(page, 'sales-limited@example.com');
        await openCampaign(page, 'Sonderfreigabe-Smoke-B');
        await submitForApproval(page);

        await page.context().clearCookies();
        await login(page, 'sales-b@example.com');
        await openCampaign(page, 'Sonderfreigabe-Smoke-B');
        await expect(page.locator('[data-test="dispo-order-approve-open"]')).toHaveCount(0);
        await expect(page.locator('[data-test="dispo-order-reject-open"]')).toHaveCount(0);
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Wartet auf Vertriebsfreigabe',
        );
    });
});
