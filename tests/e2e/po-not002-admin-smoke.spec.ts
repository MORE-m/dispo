import { expect, test, type Page } from '@playwright/test';

/**
 * PO-NOT002-ADMIN-1 Browser-Smoke.
 * Nur über playwright.po-not002-admin.config.ts (SQLite, Port 8027).
 * Kein SMTP, kein Dispatch.
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

test.describe.serial('PO-NOT002-ADMIN-1 Smoke', () => {
    test('Admin: Liste, Filter, Detail, Suppression; Sales: 403', async ({
        page,
        browser,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'admin@example.com');

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Benachrichtigungen / Outbox',
            }),
        ).toBeVisible();
        await expect(
            page.locator('a[href="/administration/benachrichtigungen"]'),
        ).toBeVisible();

        await page.goto('/administration/benachrichtigungen');
        await expect(
            page.getByRole('heading', {
                name: 'Benachrichtigungen / Outbox',
            }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="notification-outbox-table"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="notification-outbox-status-filter"]'),
        ).toHaveValue('failed');
        await expect(
            page.locator('[data-test^="notification-outbox-row-"]').first(),
        ).toBeVisible();
        await expect(
            page
                .locator('[data-test^="notification-outbox-row-"]')
                .first()
                .getByText('Fehlgeschlagen'),
        ).toBeVisible();
        await expect(page.getByText('e2e-secret-should-mask')).toHaveCount(0);

        const detailLink = page
            .locator('[data-test^="notification-outbox-detail-link-"]')
            .first();
        await expect(detailLink).toBeVisible();
        await detailLink.click();
        await expect(
            page.locator('[data-test="notification-outbox-detail"]'),
        ).toBeVisible();
        await expect(page.getByText('e2e-secret-should-mask')).toHaveCount(0);
        await expect(page.getByText('[redacted]').first()).toBeVisible();

        await page.goto(
            '/administration/benachrichtigungen?status=sent&event_type=dispo_order.approval.approved',
        );
        await expect(
            page.locator('[data-test="notification-outbox-status-filter"]'),
        ).toHaveValue('sent');
        await expect(
            page.locator('[data-test^="notification-outbox-row-"]').first(),
        ).toBeVisible();
        await expect(
            page
                .locator('[data-test^="notification-outbox-row-"]')
                .first()
                .getByText('Gesendet'),
        ).toBeVisible();
        await expect(
            page
                .locator('[data-test^="notification-outbox-row-"]')
                .first()
                .getByText('Freigabe erteilt'),
        ).toBeVisible();

        await page.goto('/administration/benachrichtigungen/unterdrueckt');
        await expect(
            page.getByRole('heading', {
                name: 'Unterdrückte Benachrichtigungen',
            }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="notification-suppress-notice"]'),
        ).toContainText('kein SMTP-Fehler');
        await expect(
            page.locator('[data-test="notification-suppress-table"]'),
        ).toBeVisible();
        await expect(page.getByText('Mediaberater fehlt')).toBeVisible();
        await expect(page.getByText('Ungültige E-Mail-Adresse')).toBeVisible();

        const salesContext = await browser.newContext();
        const salesPage = await salesContext.newPage();
        try {
            await login(salesPage, 'sales@example.com');
            const denied = await salesPage.goto(
                '/administration/benachrichtigungen',
            );
            expect(denied?.status()).toBe(403);
        } finally {
            await salesContext.close();
        }
    });
});
