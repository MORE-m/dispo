import { expect, test } from '@playwright/test';

async function loginAsSales(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('sales@example.com');
    await page.getByLabel('Password').fill('password');
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

test('CAL-001 Wizard: zwei Sender, Konditionen, Speichern', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByLabel('1. Grunddaten').click();
    await page.getByLabel('Kunde').fill('E2E Kunde');
    await page.getByRole('button', { name: 'Weiter' }).click();

    await page.getByLabel('Spotanzahl gesamt').first().fill('10');
    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.getByLabel('Spotanzahl gesamt').nth(1).fill('5');
    await page.getByRole('button', { name: 'Weiter' }).click();

    await page.getByLabel('Positionsrabatt %').first().fill('5');
    await page.getByLabel('Zusätzlicher Auftragsrabatt %').fill('0');
    await page.getByRole('button', { name: 'Weiter' }).click();

    await expect(page.getByText('N/N-Invest')).toBeVisible();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();
});

test('BUD-008 Budgetvorschlag und explizite Übernahme', async ({ page }) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByLabel('Mit Budget planen').check();
    await page.getByLabel('Zielbudget N/N').fill('500');
    await page.getByRole('button', { name: 'Weiter' }).click();

    await page.getByLabel('Spotanzahl gesamt').first().fill('1');
    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.getByLabel('Spotanzahl gesamt').nth(1).fill('1');
    await page.getByRole('button', { name: 'Weiter' }).click();

    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    await expect(page.getByText('Verbrauch')).toBeVisible();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    await page.getByRole('button', { name: 'Vorschlag übernehmen' }).click();
    await expect(page.getByText(/übernommen|gespeichert/i)).toBeVisible();
});
