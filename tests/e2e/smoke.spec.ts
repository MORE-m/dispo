import { expect, test } from '@playwright/test';

test('Health-Endpunkt antwortet', async ({ request }) => {
    const response = await request.get('/health');
    expect(response.ok()).toBeTruthy();
    await expect(response.json()).resolves.toMatchObject({ status: 'ok' });
});

test('Startseite ist erreichbar', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Kalkulation und Disposition' })).toBeVisible();
});
