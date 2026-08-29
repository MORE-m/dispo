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

    const firstSection = page.locator('section').first();
    await firstSection.getByLabel('Spotanzahl gesamt').fill('10');
    await firstSection.getByLabel('Länge (Sekunden)').fill('30');
    await page.getByRole('button', { name: 'Preisstunde hinzufügen' }).click();

    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    const secondSection = page.locator('section').nth(1);
    await secondSection
        .getByRole('combobox')
        .first()
        .selectOption({ label: 'ROCK ANTENNE Hamburg' });
    await secondSection.getByLabel('Spotanzahl gesamt').fill('5');
    await secondSection.getByLabel('Länge (Sekunden)').fill('20');

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByLabel('Positionsrabatt %').first().fill('5');
    await page.getByLabel('Zusätzlicher Auftragsrabatt %').fill('0');
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();

    await expect(page.getByText('N/N-Invest')).toBeVisible();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();
    await expect(page.getByText('Radio Hamburg')).toBeVisible();
    await expect(page.getByText('ROCK ANTENNE Hamburg')).toBeVisible();
});

test('BUD-008 Budgetvorschlag und serverseitige Übernahme', async ({
    page,
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
    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    await expect(page.getByText('Verbrauch')).toBeVisible();

    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    await page.getByRole('button', { name: 'Vorschlag übernehmen' }).click();
    await expect(page.getByText(/übernommen|gespeichert/i)).toBeVisible();

    await page.getByRole('button', { name: 'Vorschlag erzeugen' }).click();
    await page.getByRole('button', { name: 'Vorschlag übernehmen' }).click();
    await expect(page.getByText(/bereits übernommen|Übernahme nicht möglich/i)).toBeVisible();
});
