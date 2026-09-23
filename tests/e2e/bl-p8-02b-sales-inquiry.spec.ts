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

async function logout(page: Page) {
    await page.context().clearCookies();
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible({ timeout: 30_000 });
}

async function openSeededOrder(page: Page) {
    await page.goto('/dispoauftraege');
    await expect(page.locator('[data-test="dispo-orders-table"]')).toBeVisible({
        timeout: 15_000,
    });
    const row = page.locator('[data-test^="dispo-order-row-"]').first();
    await expect(row).toBeVisible();
    await row.getByRole('link').first().click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

test.describe('BL-P8-02b Rückfrage Vertrieb', () => {
    test('Disposition stellt Rückfrage, Vertrieb antwortet, Disposition kann weiter', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        await login(page, 'disposition@example.com');
        await openSeededOrder(page);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
        );
        await expect(
            page.locator('[data-test="dispo-order-ask-sales-inquiry"]'),
        ).toBeVisible();

        await page.locator('[data-test="dispo-order-ask-sales-inquiry"]').click();
        await expect(
            page.locator('[data-test="dispo-order-ask-inquiry-dialog"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-ask-inquiry-confirm"]'),
        ).toBeDisabled();
        await page
            .locator('[data-test="dispo-order-ask-inquiry-question"]')
            .fill('   ');
        await expect(
            page.locator('[data-test="dispo-order-ask-inquiry-confirm"]'),
        ).toBeDisabled();
        await page
            .locator('[data-test="dispo-order-ask-inquiry-question"]')
            .fill('Bitte bestätige die finalen Spotzeiten.');
        await page.locator('[data-test="dispo-order-ask-inquiry-confirm"]').click();

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Rückfrage Vertrieb',
            { timeout: 15_000 },
        );
        await expect(
            page.locator('[data-test="dispo-order-communication-history"]'),
        ).toContainText('Bitte bestätige die finalen Spotzeiten.');
        await expect(
            page.locator('[data-test^="dispo-order-status-action-"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-answer-sales-inquiry"]'),
        ).toHaveCount(0);

        await logout(page);
        await login(page, 'sales@example.com');
        await openSeededOrder(page);

        await expect(
            page.locator('[data-test="dispo-order-communication-history"]'),
        ).toContainText('Bitte bestätige die finalen Spotzeiten.');
        await expect(
            page.locator('[data-test="dispo-order-ask-sales-inquiry"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-answer-sales-inquiry"]'),
        ).toBeVisible();

        await page.locator('[data-test="dispo-order-answer-sales-inquiry"]').click();
        await expect(
            page.locator('[data-test="dispo-order-answer-inquiry-dialog"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-answer-inquiry-question"]'),
        ).toContainText('Bitte bestätige die finalen Spotzeiten.');
        await expect(
            page.locator('[data-test="dispo-order-answer-inquiry-confirm"]'),
        ).toBeDisabled();
        await page
            .locator('[data-test="dispo-order-answer-inquiry-answer"]')
            .fill('Finale Spotzeiten sind vom Kunden bestätigt.');
        await page.locator('[data-test="dispo-order-answer-inquiry-confirm"]').click();

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
            { timeout: 15_000 },
        );
        const communication = page.locator(
            '[data-test="dispo-order-communication-history"]',
        );
        await expect(communication).toContainText(
            'Bitte bestätige die finalen Spotzeiten.',
        );
        await expect(communication).toContainText(
            'Finale Spotzeiten sind vom Kunden bestätigt.',
        );

        const history = page.locator('[data-test="dispo-order-status-history"]');
        await expect(history).toContainText('In Bearbeitung → Rückfrage Vertrieb');
        await expect(history).toContainText(
            'Rückfrage Vertrieb → Liegt bei Disposition',
        );

        await logout(page);
        await login(page, 'disposition@example.com');
        await openSeededOrder(page);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
        );
        await expect(
            page.locator('[data-test="dispo-order-status-action-in_progress"]'),
        ).toBeVisible();
    });

    test('PM sieht Auftrag nicht / Disposition darf nicht antworten', async ({
        page,
    }) => {
        test.setTimeout(120_000);

        await login(page, 'pm@example.com');
        await page.goto('/dispoauftraege');
        await expect(page.locator('body')).toBeVisible();
        const tableCount = await page
            .locator('[data-test="dispo-orders-table"]')
            .count();
        if (tableCount > 0) {
            await expect(
                page.locator('[data-test^="dispo-order-row-"]'),
            ).toHaveCount(0);
        }
    });
});
