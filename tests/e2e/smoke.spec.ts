import { expect, test } from '@playwright/test';

async function loginAsSales(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.locator('#email').fill('sales@example.com');
    await page.locator('#password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).not.toHaveURL(/login/);
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

test('CAL-001 Mehrsender-Wizard mit Durchschnitt und Konditionen', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('section').first().getByLabel('Spotanzahl gesamt').fill('10');
    await page.locator('section').first().getByLabel('Spotlänge (Sek.)').fill('30');
    await page.locator('section').first().getByRole('checkbox', { name: '8' }).check();
    await page.locator('section').first().getByRole('checkbox', { name: '10' }).check();

    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page
        .locator('section')
        .nth(1)
        .getByRole('combobox')
        .first()
        .selectOption({ label: 'ROCK ANTENNE Hamburg' });
    await page.locator('section').nth(1).getByLabel('Spotanzahl gesamt').fill('5');
    await page.locator('section').nth(1).getByLabel('Spotlänge (Sek.)').fill('20');
    await page.locator('section').nth(1).getByRole('checkbox', { name: '10' }).check();

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();

    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();
    await expect(page.getByText('Radio Hamburg')).toBeVisible();
    await expect(page.getByText('ROCK ANTENNE Hamburg')).toBeVisible();
});

test('BUD-008 Budgetvorschlag und serverseitige Übernahme', async ({
    page,
    request,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByLabel('Mit Budget planen').check();
    await page.getByLabel('Zielbudget N/N').fill('500');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();

    await page.locator('section').first().getByLabel('Spotanzahl gesamt').fill('1');
    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page
        .locator('section')
        .nth(1)
        .getByRole('combobox')
        .first()
        .selectOption({ label: 'ROCK ANTENNE Hamburg' });
    await page.locator('section').nth(1).getByLabel('Spotanzahl gesamt').fill('1');

    await page.getByRole('button', { name: '3. Konditionen' }).click();

    const proposeResponse = page.waitForResponse(
        (response) =>
            response.url().includes('budget-vorschlag') &&
            response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    const proposalJson = await (await proposeResponse).json();
    const proposalId = proposalJson.proposal.id as number;

    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

    const url = page.url();
    const calculationId = url.match(/kalkulationen\/(\d+)/)?.[1];
    expect(calculationId).toBeTruthy();

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await expect(page.getByRole('button', { name: 'Vorschlag übernehmen' })).toBeVisible();
    await page.getByRole('button', { name: 'Vorschlag übernehmen' }).click();
    await expect(page.getByText('Vorschlag übernommen')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Vorschlag übernehmen' }),
    ).toHaveCount(0);

    const secondApply = await request.post(
        `/kalkulationen/${calculationId}/budget-vorschlaege/${proposalId}/uebernehmen`,
        { maxRedirects: 0 },
    );
    expect(secondApply.status()).toBe(422);
});
