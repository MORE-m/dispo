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

async function createDraftDispoOrder(page: Page) {
    await saveSimpleCalculation(page);
    await openDispoOrderDialog(page);
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

async function submitForApproval(page: Page) {
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await expect(page.locator('[data-test="dispo-order-submit-dialog"]')).toBeVisible();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Wartet auf Vertriebsfreigabe',
        { timeout: 15_000 },
    );
}

async function openDispoOrdersViaSidebar(page: Page) {
    await page
        .locator('[data-sidebar="menu"]')
        .getByRole('link', { name: 'Dispoaufträge' })
        .click();
    await expect(page).toHaveURL(/\/dispoauftraege\/?$/);
}

async function expectRowStatus(page: Page, orderId: string, label: string) {
    await expect(
        page.locator(`[data-test="dispo-order-row-${orderId}"]`),
    ).toContainText(label, { timeout: 15_000 });
}

test('Liste zeigt nach Einreichen aktuellen Status ohne Reload', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'sales@example.com');
    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"], [data-test="dispo-orders-empty"]')).toBeVisible();

    await createDraftDispoOrder(page);
    const orderId = page.url().match(/dispoauftraege\/(\d+)/)?.[1];
    expect(orderId).toBeTruthy();

    await submitForApproval(page);
    await openDispoOrdersViaSidebar(page);
    await expectRowStatus(page, orderId!, 'Wartet auf Vertriebsfreigabe');
});

test('Liste zeigt nach Genehmigen aktuellen Status ohne Reload', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const detailUrl = page.url();
    const orderId = detailUrl.match(/dispoauftraege\/(\d+)/)?.[1];
    expect(orderId).toBeTruthy();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto('/dispoauftraege');
    await expect(page.locator(`[data-test="dispo-order-row-${orderId}"]`)).toBeVisible();
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
        { timeout: 15_000 },
    );

    await openDispoOrdersViaSidebar(page);
    await expectRowStatus(page, orderId!, 'Liegt bei Disposition');
});

test('Liste zeigt nach Ablehnen aktuellen Status ohne Reload', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const detailUrl = page.url();
    const orderId = detailUrl.match(/dispoauftraege\/(\d+)/)?.[1];
    expect(orderId).toBeTruthy();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto('/dispoauftraege');
    await expect(page.locator(`[data-test="dispo-order-row-${orderId}"]`)).toBeVisible();
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-reject-open"]').click();
    await page.locator('[data-test="dispo-order-reject-reason"]').fill(
        'Konditionen nicht freigabefähig',
    );
    await page.locator('[data-test="dispo-order-reject-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Freigabe abgelehnt',
        { timeout: 15_000 },
    );

    await openDispoOrdersViaSidebar(page);
    await expectRowStatus(page, orderId!, 'Freigabe abgelehnt');
});

test('Reguläre Freigabe mit Vier-Augen-Prinzip', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);

    await expect(page.locator('[data-test="four-eyes-creator-hint"]')).toBeVisible();
    await expect(page.locator('[data-test="dispo-order-approve-open"]')).toHaveCount(0);

    const detailUrl = page.url();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
        { timeout: 15_000 },
    );

    await page.context().clearCookies();
    await login(page, 'disposition@example.com');
    await page.goto(detailUrl);
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
    );
});

test('Sonderfreigabe nur durch Admin', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, 'sales-limited@example.com');
    await page.goto('/dispoauftraege');
    const specialRow = page.locator('[data-test^="dispo-order-row-"]').filter({
        hasText: 'Sonderfreigabe-Kampagne',
    });
    await specialRow.getByRole('link').first().click();
    await expect(page.locator('[data-test="dispo-order-special-approval-hint"]')).toBeVisible();

    await submitForApproval(page);

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto('/dispoauftraege');
    await page
        .locator('[data-test^="dispo-order-row-"]')
        .filter({ hasText: 'Sonderfreigabe-Kampagne' })
        .getByRole('link')
        .first()
        .click();
    await expect(page.locator('[data-test="dispo-order-approve-open"]')).toHaveCount(0);

    await page.context().clearCookies();
    await login(page, 'admin@example.com');
    await page.goto('/dispoauftraege');
    await page
        .locator('[data-test^="dispo-order-row-"]')
        .filter({ hasText: 'Sonderfreigabe-Kampagne' })
        .getByRole('link')
        .first()
        .click();
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
        { timeout: 15_000 },
    );
});

test('Ablehnung mit Begründung und Historie', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const detailUrl = page.url();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-reject-open"]').click();
    await page.locator('[data-test="dispo-order-reject-reason"]').fill(
        'Konditionen nicht freigabefähig',
    );
    await page.locator('[data-test="dispo-order-reject-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Freigabe abgelehnt',
        { timeout: 15_000 },
    );
    await expect(page.locator('[data-test="dispo-order-rejection-reason"]')).toContainText(
        'Konditionen nicht freigabefähig',
    );
    await expect(page.locator('[data-test="dispo-order-approval-history"]')).toBeVisible();
});

test('Mobiler Freigabeablauf', async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const detailUrl = page.url();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
        { timeout: 15_000 },
    );
});
