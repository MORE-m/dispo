import { expect, test } from '@playwright/test';

async function loginAsSales(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.locator('#email').fill('sales@example.com');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

test('Health-Endpunkt antwortet', async ({ request }) => {
    const response = await request.get('/health');
    expect(response.ok()).toBeTruthy();
    await expect(response.json()).resolves.toMatchObject({ status: 'ok' });
});

test('Startseite ist erreichbar', async ({ page }) => {
    await page.goto('/');
    await expect(
        page.getByRole('heading', { name: 'Kalkulation und Disposition' }),
    ).toBeVisible();
});

test('Kalkulationen erfordern Anmeldung', async ({ page }) => {
    await page.goto('/kalkulationen');
    await expect(page).toHaveURL(/login/);
});

test('Wizard markiert Kalkulationen in der Navigation als aktiv', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    const navLink = page.locator('[data-sidebar="menu-button"]', {
        hasText: 'Kalkulationen',
    });
    await expect(navLink).toHaveAttribute('aria-current', 'page');
    await expect(navLink).toHaveClass(/bg-primary/);
});

test('CAL-001 Mehrsender-Wizard mit Durchschnitt und Konditionen', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="position-total-spots-0"]').fill('10');
    await page.locator('[data-test="position-length-seconds-0"]').fill('30');
    await page
        .locator('section')
        .first()
        .getByRole('button', { name: 'Preisstunde hinzufügen' })
        .click();
    await page.locator('#hour-0-1').fill('10');

    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.locator('[data-test="position-inventory-1"]').selectOption({
        label: 'ROCK ANTENNE Hamburg',
    });
    await page.locator('[data-test="position-total-spots-1"]').fill('5');
    await page.locator('[data-test="position-length-seconds-1"]').fill('20');
    await page.locator('#hour-1-0').fill('10');

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();

    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await expect(
        page.locator('[data-test="position-inventory-0"] option:checked'),
    ).toHaveText('Radio Hamburg');
    await expect(
        page.locator('[data-test="position-inventory-1"] option:checked'),
    ).toHaveText('ROCK ANTENNE Hamburg');
});

test('BUD-008 Budgetvorschlag und serverseitige Übernahme', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByLabel('Mit Budget planen').check();
    await page.getByLabel('Zielbudget N/N').fill('500');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();

    await page.locator('[data-test="position-total-spots-0"]').fill('1');
    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.locator('[data-test="position-inventory-1"]').selectOption({
        label: 'ROCK ANTENNE Hamburg',
    });
    await page.locator('[data-test="position-total-spots-1"]').fill('1');

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

    const url = page.url();
    const calculationId = url.match(/kalkulationen\/(\d+)/)?.[1];
    expect(calculationId).toBeTruthy();

    await page.getByRole('button', { name: '3. Konditionen' }).click();

    const proposeResponse = page.waitForResponse(
        (response) =>
            response.url().includes('budget-vorschlag') &&
            response.request().method() === 'POST',
    );
    await page.locator('[data-test="budget-propose"]').click();
    const proposalJson = await (await proposeResponse).json();
    const proposalId = proposalJson.proposal.id;
    expect(typeof proposalId).toBe('number');
    expect(proposalId).toBeGreaterThan(0);

    await expect(page.locator('[data-test="budget-apply"]')).toBeVisible();
    await page.locator('[data-test="budget-apply"]').click();
    await expect(page.getByText('Vorschlag übernommen')).toBeVisible();
    await expect(page.locator('[data-test="budget-apply"]')).toHaveCount(0);

    const cookies = await page.context().cookies();
    const xsrfCookie = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrfCookie).toBeTruthy();

    const secondApply = await page.request.post(
        `/kalkulationen/${calculationId}/budget-vorschlaege/${proposalId}/uebernehmen`,
        {
            maxRedirects: 0,
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie!.value),
            },
        },
    );
    expect(secondApply.status()).toBe(422);
});
