import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P2-01a isolierte Suite: Inventar-Admin-Lifecycle.
 * Läuft nur über playwright.blp201a.config.ts (eigene DB, Port 8016).
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

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const token = await page.evaluate(() => {
        const row = document.cookie
            .split('; ')
            .find((part) => part.startsWith('XSRF-TOKEN='));
        return row ? decodeURIComponent(row.slice(11)) : '';
    });

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token,
    };
}

async function putJson(
    page: Page,
    url: string,
    body: Record<string, unknown> = {},
) {
    return page.request.put(url, {
        headers: await csrfHeaders(page),
        data: body,
    });
}

test.describe.serial('BL-P2-01a Inventar-Admin', () => {
    test('Admin öffnet Hub, legt Inventar an, bearbeitet, deaktiviert und reaktiviert', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await page.goto('/administration/inventare');
        await expect(
            page.getByRole('heading', { name: 'Inventare' }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="inventory-index-table"]'),
        ).toBeVisible();

        await page.locator('[data-test="inventory-create-link"]').click();
        await page
            .locator('[data-test="inventory-name-input"]')
            .fill(`E2E Inventar ${suffix}`);
        await page
            .locator('[data-test="inventory-code-input"]')
            .fill(`E2E${suffix}`);
        await page
            .locator('[data-test="inventory-type-input"]')
            .selectOption('sender');
        await page.locator('[data-test="inventory-sort-input"]').fill('40');
        await page.locator('[data-test="inventory-create-submit"]').click();
        await expect(page).toHaveURL(/\/administration\/inventare\/\d+$/);
        await expect(
            page.getByRole('heading', { name: `E2E Inventar ${suffix}` }),
        ).toBeVisible();

        await page
            .locator('[data-test="inventory-name-input"]')
            .fill(`E2E Inventar ${suffix} umbenannt`);
        await page.locator('[data-test="inventory-save-button"]').click();
        await expect(
            page.locator('[data-test="inventory-show-success"]'),
        ).toBeVisible();

        await page
            .locator('[data-test="inventory-deactivate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="inventory-impact-preview"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="inventory-impact-preview"]'),
        ).toContainText('nicht mehr neu');
        await page
            .locator('[data-test="inventory-deactivate-confirm"]')
            .click();
        await expect(page.locator('[data-test="inventory-status"]')).toHaveText(
            'Inaktiv',
        );

        await page.locator('[data-test="inventory-reactivate-button"]').click();
        await expect(page.locator('[data-test="inventory-status"]')).toHaveText(
            'Aktiv',
        );

        const inventoryUrl = page.url();
        const inventoryId = inventoryUrl.match(/inventare\/(\d+)/)?.[1];
        expect(inventoryId).toBeTruthy();
        const lockText = await page
            .locator('text=Sperrversion')
            .first()
            .textContent();
        const lockVersion = Number(lockText?.match(/Sperrversion\s+(\d+)/)?.[1] ?? 1);

        await putJson(page, `/administration/inventare/${inventoryId}`, {
            name: `E2E Inventar ${suffix} parallel`,
            sort: 40,
            lock_version: lockVersion,
        });

        await page
            .locator('[data-test="inventory-name-input"]')
            .fill(`E2E Inventar ${suffix} stale`);
        await page.locator('[data-test="inventory-save-button"]').click();
        await expect(
            page.locator('[data-test="inventory-show-error"]'),
        ).toContainText(/parallel|veraltet|neu laden/i, { timeout: 15_000 });
    });

    test('historischer Kalkulationsname bleibt nach Inventar-Rename sichtbar', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto('/kalkulationen');
        await page.getByRole('link', { name: /K-/ }).first().click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/);
        await expect(
            page.locator('[data-test="calculation-summary"]'),
        ).toContainText('Radio Hamburg');

        await page.goto('/administration/inventare');
        await page.getByRole('link', { name: 'Radio Hamburg' }).click();
        await page
            .locator('[data-test="inventory-name-input"]')
            .fill('Radio Hamburg E2E umbenannt');
        await page.locator('[data-test="inventory-save-button"]').click();
        await expect(
            page.locator('[data-test="inventory-show-success"]'),
        ).toBeVisible();

        await page.goto('/kalkulationen');
        await page.getByRole('link', { name: /K-/ }).first().click();
        await expect(
            page.locator('[data-test="calculation-summary"]'),
        ).toContainText('Radio Hamburg');
        await expect(
            page.locator('[data-test="calculation-summary"]'),
        ).not.toContainText('Radio Hamburg E2E umbenannt');

        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        const wizardLabels = await page
            .locator('[data-test="position-inventory-0"] option')
            .allTextContents();
        expect(wizardLabels).toContain('Radio Hamburg E2E umbenannt');
        expect(wizardLabels).toContain('ROCK ANTENNE Hamburg');
        expect(wizardLabels).not.toContain('Radio Hamburg');

        await page.goto('/kalkulationen/neu');
        await page
            .getByRole('radio', { name: /Mit Budget planen/i })
            .click({ force: true });
        await page.getByLabel('Zielbudget N/N').fill('500');
        await page.getByRole('button', { name: 'Weiter' }).click();
        await expect(
            page.locator('[data-test="budget-elements-step"]'),
        ).toBeVisible();
        const budgetLabels = await page
            .locator('#budget-element-inventory-0 option')
            .allTextContents();
        expect(budgetLabels).toContain('Radio Hamburg E2E umbenannt');
        expect(budgetLabels).toContain('ROCK ANTENNE Hamburg');
        expect(budgetLabels).not.toContain('Radio Hamburg');
    });

    test('Nicht-Admin erhält 403', async ({ page }) => {
        await login(page, 'sales@example.com');
        const response = await page.goto('/administration/inventare');
        expect(response?.status()).toBe(403);
    });
});
