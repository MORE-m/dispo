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

async function saveSimpleCalculation(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
}

async function openDispoOrderDialog(page: Page) {
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(page.locator('[data-test="dispo-order-create-open"]')).toBeVisible({
        timeout: 15_000,
    });
    await page.locator('[data-test="dispo-order-create-open"]').click();
}

test('Vertrieb legt Dispoauftrag aus Kalkulation an', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, 'sales@example.com');
    await saveSimpleCalculation(page);

    await openDispoOrderDialog(page);
    await expect(page.locator('[data-test="dispo-order-create-dialog"]')).toBeVisible();
    await page.locator('[data-test="dispo-order-submit"]').click();

    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Entwurf',
    );
    await expect(page.locator('[data-test="dispo-order-net-total"]')).toContainText(
        /€/,
    );

    const orderNumber = await page.locator('h1').first().textContent();

    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"]')).toBeVisible();
    await expect(page.getByText(orderNumber ?? '')).toBeVisible();
});

test('Disposition sieht Liste und Detail ohne Anlageaktion', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, 'sales@example.com');
    await saveSimpleCalculation(page);
    await openDispoOrderDialog(page);
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
    const detailUrl = page.url();

    await page.context().clearCookies();
    await login(page, 'disposition@example.com');
    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"]')).toBeVisible();
    await expect(page.locator('[data-test="dispo-order-create-open"]')).toHaveCount(0);

    await page.goto(detailUrl);
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Entwurf',
    );
});

test('Mobiler Ablauf für Dispoauftrag-Anlage', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'sales@example.com');
    await saveSimpleCalculation(page);

    await openDispoOrderDialog(page);
    await expect(page.locator('[data-test="dispo-order-create-dialog"]')).toBeVisible();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toBeVisible();
});
