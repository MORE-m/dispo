import { expect, test, type Page } from '@playwright/test';
import {
    setCustomerConfirmationException,
} from './helpers/customer-confirmation';

async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
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

async function createDraftDispoOrder(page: Page) {
    await saveSimpleCalculation(page);
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(page.locator('[data-test="dispo-order-create-open"]')).toBeVisible({
        timeout: 15_000,
    });
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

async function submitForApproval(page: Page) {
    await setCustomerConfirmationException(page);
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Wartet auf Vertriebsfreigabe',
        { timeout: 15_000 },
    );
}

test('Nachbesserung abgelehnter Dispoauftrag als neuer Entwurf', async ({
    page,
}) => {
    test.setTimeout(240_000);
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const rejectedUrl = page.url();
    const rejectedId = rejectedUrl.match(/dispoauftraege\/(\d+)/)?.[1];
    expect(rejectedId).toBeTruthy();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(rejectedUrl);
    await page.locator('[data-test="dispo-order-reject-open"]').click();
    await page
        .locator('[data-test="dispo-order-reject-reason"]')
        .fill('Konditionen nicht freigabefähig');
    await page.locator('[data-test="dispo-order-reject-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Freigabe abgelehnt',
        { timeout: 15_000 },
    );

    await page.context().clearCookies();
    await login(page, 'sales@example.com');
    await page.goto(rejectedUrl);
    await expect(page.locator('[data-test="dispo-order-revise-open"]')).toBeVisible();
    await page.locator('[data-test="dispo-order-revise-open"]').click();

    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
    await expect(page.locator('[data-test="dispo-order-revision-banner"]')).toBeVisible();
    await expect(
        page.locator('[data-test="dispo-order-revision-rejection-reason"]'),
    ).toContainText('Konditionen nicht freigabefähig');

    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('12');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert.')).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('[data-test="dispo-order-revision-banner"]')).toBeVisible();

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(page.locator('[data-test="dispo-order-create-open"]')).toHaveText(
        'Korrigierten Dispoauftrag erstellen',
        { timeout: 15_000 },
    );
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await expect(page.locator('[data-test="dispo-order-create-dialog"]')).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('[data-test="dispo-order-submit"]')).toHaveText(
        'Korrigierten Dispoauftrag erstellen',
        { timeout: 15_000 },
    );
    await page.locator('[data-test="dispo-order-submit"]').click();

    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
    const revisionUrl = page.url();
    expect(revisionUrl).not.toBe(rejectedUrl);
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Entwurf',
    );
    await expect(page.locator('[data-test="dispo-order-revises-link"]')).toContainText(
        'Nachbesserung von',
    );

    await page.goto(rejectedUrl);
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Freigabe abgelehnt',
    );
    await expect(page.locator('[data-test="dispo-order-revision-link"]')).toBeVisible();
    await expect(page.locator('[data-test="dispo-order-revise-open"]')).toHaveCount(0);

    await page.goto(revisionUrl);
    await setCustomerConfirmationException(page);
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Wartet auf Vertriebsfreigabe',
        { timeout: 15_000 },
    );
});

test('Nachbesserung mobil bedienbar', async ({ page }) => {
    test.setTimeout(240_000);
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'sales@example.com');
    await createDraftDispoOrder(page);
    await submitForApproval(page);
    const rejectedUrl = page.url();

    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(rejectedUrl);
    await page.locator('[data-test="dispo-order-reject-open"]').click();
    await page
        .locator('[data-test="dispo-order-reject-reason"]')
        .fill('Ablehnung mobil');
    await page.locator('[data-test="dispo-order-reject-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Freigabe abgelehnt',
        { timeout: 15_000 },
    );

    await page.context().clearCookies();
    await login(page, 'sales@example.com');
    await page.goto(rejectedUrl);
    await page.locator('[data-test="dispo-order-revise-open"]').click();
    await expect(page.locator('[data-test="dispo-order-revision-banner"]')).toBeVisible({
        timeout: 15_000,
    });
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Entwurf',
        { timeout: 15_000 },
    );
});
