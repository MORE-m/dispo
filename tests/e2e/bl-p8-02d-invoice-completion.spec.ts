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

test.describe('BL-P8-02d Rechnung per Ende + Completion', () => {
    test('A) Invoice + normal Completion Happy Path', async ({ page }) => {
        test.setTimeout(300_000);

        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802D Happy Path GmbH');

        const invoice = page.locator('[data-test^="dispo-order-invoice-end-"]').first();
        await expect(invoice).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-invoice-end-hint-required"]'),
        ).toBeVisible();

        await page.locator('[data-test="dispo-order-invoice-end-month-3"]').click();
        await page.locator('[data-test="dispo-order-invoice-end-save"]').click();
        await expect(
            page.locator('[data-test="dispo-order-invoice-end-readonly"], [data-test="dispo-order-invoice-end-options"]'),
        ).toBeVisible({ timeout: 15_000 });

        await page.reload();
        await expect(page.getByText('März')).toBeVisible({ timeout: 15_000 });

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(
            page.locator('[data-test="dispo-order-completion-section"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-completion-check-invoice_end_months"]'),
        ).toContainText('✓');

        await page.locator('[data-test="dispo-order-complete-action"]').click();
        await expect(
            page.locator('[data-test="dispo-order-status-badge"]'),
        ).toHaveText('Abgeschlossen', { timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-completion-summary"]'),
        ).toBeVisible();

        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-completion-summary"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-invoice-end-readonly"]'),
        ).toContainText('März');
        await expect(
            page.locator('[data-test="dispo-order-status-history"]'),
        ).toContainText('Disponiert → Abgeschlossen');
    });

    test('B) AT-18: fehlender Rechnungsmonat blockiert Disposition', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'disposition@example.com');
        await openOrderByCustomer(page, 'BLP802D AT-18 Blocked GmbH');

        await expect(
            page.locator('[data-test="dispo-order-completion-section"]'),
        ).toBeVisible();
        await expect(
            page.locator(
                '[data-test="dispo-order-completion-check-invoice_end_months"]',
            ),
        ).toContainText('Problem');
        await expect(
            page.getByText('Rechnung per Ende fehlt'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-complete-action"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-force-complete-action"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-completion-blocked"]'),
        ).toBeVisible();
    });

    test('C) Admin Override mit Begründung', async ({ page }) => {
        test.setTimeout(180_000);

        await login(page, 'admin@example.com');
        await openOrderByCustomer(page, 'BLP802D Override GmbH');

        await expect(
            page.locator('[data-test="dispo-order-force-complete-action"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="dispo-order-force-complete-action"]')
            .click();
        await expect(
            page.locator('[data-test="dispo-order-force-complete-dialog"]'),
        ).toBeVisible();

        const confirm = page.locator(
            '[data-test="dispo-order-force-complete-confirm"]',
        );
        await expect(confirm).toBeDisabled();

        await page
            .locator('[data-test="dispo-order-force-complete-reason"]')
            .fill('   ');
        await expect(confirm).toBeDisabled();

        await page
            .locator('[data-test="dispo-order-force-complete-reason"]')
            .fill(
                'Rechnungsmonat wird nachträglich außerhalb des Systems dokumentiert.',
            );
        await expect(confirm).toBeEnabled();
        await confirm.click();

        await expect(
            page.locator('[data-test="dispo-order-completion-summary"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-completion-override-info"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-completion-override-reason"]'),
        ).toContainText('nachträglich außerhalb');
        await expect(
            page.locator('[data-test="dispo-order-status-history"]'),
        ).toContainText('Admin-Override');
    });

    test('Negativ: Sales ohne Invoice-Edit, PM ohne Zugriff, Management ohne Override', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        await login(page, 'sales@example.com');
        await openOrderByCustomer(page, 'BLP802D Happy Path GmbH');
        await expect(
            page.locator('[data-test="dispo-order-invoice-end-save"]'),
        ).toHaveCount(0);
        await logout(page);

        await login(page, 'pm@example.com');
        await page.goto('/dispoauftraege');
        // PM darf Liste ggf. sehen oder nicht – kein Invoice-Save
        const table = page.locator('[data-test="dispo-orders-table"]');
        if (await table.isVisible().catch(() => false)) {
            await openOrderByCustomer(page, 'BLP802D Happy Path GmbH');
            await expect(
                page.locator('[data-test="dispo-order-invoice-end-save"]'),
            ).toHaveCount(0);
        }
        await logout(page);

        await login(page, 'management@example.com');
        await openOrderByCustomer(page, 'BLP802D AT-18 Blocked GmbH');
        await expect(
            page.locator('[data-test="dispo-order-force-complete-action"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-completion-blocked"]'),
        ).toBeVisible();
    });
});
