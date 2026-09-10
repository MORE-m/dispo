import {
    expect,
    test,
    type Page,
} from '@playwright/test';

/**
 * ADV-001c3a isolierte Suite: Null-kind-Medien + Wizard-Buchbarkeit.
 * Läuft nur über playwright.adv001c3a.config.ts (eigene DB, Port 8008).
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

test.describe.serial('ADV-001c3a Katalog Null-kind und Wizard', () => {
    test('Werbemittel ohne Kind in Nicht-Spots anlegen und Status getrennt zeigen', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration/katalog/werbemittel/neu');
        await expect(
            page.locator('[data-test="medium-catalog-note"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-kind-select"]'),
        ).toHaveCount(0);

        const categorySelect = page.locator(
            '[data-test="medium-category-select"]',
        );
        const options = categorySelect.locator('option');
        expect(await options.count()).toBeGreaterThanOrEqual(7);

        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`E2E OA ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`e2e_oa_${suffix}`);
        await categorySelect.selectOption({ label: 'Online Audio (online_audio)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });

        await expect(
            page.locator('[data-test="medium-status-panel"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-status-badge"]'),
        ).toHaveText('Aktiv');
        await expect(
            page.locator('[data-test="medium-bookability-badge"]'),
        ).toHaveText('Noch nicht technisch verfügbar');
        await expect(
            page.locator('[data-test="medium-unbookable-reason"]'),
        ).toContainText(/Berechnungsmethode|technisch/i);
        await expect(
            page.locator('[data-test="medium-kind-readonly"]'),
        ).toHaveCount(0);
    });

    test('Null-kind nicht im Wizard wählbar, Spot Classic weiterhin speicherbar', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration/katalog/werbemittel/neu');
        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`Wizard Null ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`wiz_null_${suffix}`);
        await page
            .locator('[data-test="medium-category-select"]')
            .selectOption({ label: 'Spots (spots)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });

        await page.goto('/kalkulationen/neu');
        await expect(page.getByText('Neue Kalkulation')).toBeVisible({
            timeout: 30_000,
        });

        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/wiz_null_/i);
        expect(body).toMatch(/Radio Hamburg|Selbst planen|Mit Budget planen/i);
    });
});
