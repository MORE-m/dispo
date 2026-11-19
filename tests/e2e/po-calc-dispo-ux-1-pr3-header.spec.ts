import { expect, test, type Page } from '@playwright/test';
import { ensureWizardCustomerFreitext } from './helpers/wizard-customer';
import { ensureWizardInventorySelected } from './helpers/wizard-inventory';

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

async function createDraftDispoOrder(page: Page) {
    await page.goto('/kalkulationen/neu');
    await ensureWizardCustomerFreitext(page);
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await ensureWizardInventorySelected(page);
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(
        page.locator('[data-test="dispo-order-create-open"]'),
    ).toBeVisible({ timeout: 15_000 });
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

test.describe('PO-CALC-DISPO-UX-1 PR3 Kopfdaten', () => {
    test('Draft: Briefing speichern und RE leer vor Submit blockieren', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        await createDraftDispoOrder(page);

        await expect(
            page.locator('[data-test="dispo-order-header-editor"]'),
        ).toBeVisible();

        await page.locator('[data-test="dispo-briefing"]').fill('PR3 Briefing');
        await page.locator('[data-test="dispo-invoice-recipient"]').selectOption('');
        await page.locator('[data-test="dispo-order-header-save"]').click();
        await expect(page.getByText('Kopfdaten gespeichert')).toBeVisible({
            timeout: 15_000,
        });

        await expect(page.locator('[data-test="dispo-briefing"]')).toHaveValue(
            'PR3 Briefing',
        );

        // Kundenbestätigungs-Ausnahme für Submit
        const withoutUpload = page.locator(
            '[data-test="dispo-order-customer-confirmation-without-upload"]',
        );
        if (await withoutUpload.count()) {
            await withoutUpload.check();
            const reason = page.locator(
                '[data-test="dispo-order-customer-confirmation-exception-reason"]',
            );
            if (await reason.count()) {
                await reason.fill('E2E Ausnahme');
            }
            const saveCc = page.locator(
                '[data-test="dispo-order-customer-confirmation-save"]',
            );
            if (await saveCc.count()) {
                await saveCc.click();
            }
        }

        await page.locator('[data-test="dispo-order-submit-open"]').click();
        await page.locator('[data-test="dispo-order-submit-confirm"]').click();
        await expect(
            page.getByText(/Rechnungsempfänger/i).first(),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page).toHaveURL(/dispoauftraege\/\d+/);
    });

    test('PR2-Layout bleibt mit Kopfdaten-Editor erhalten', async ({ page }) => {
        await login(page, 'sales@example.com');
        await createDraftDispoOrder(page);

        await page.setViewportSize({ width: 1280, height: 900 });
        await expect(
            page.locator('[data-test="dispo-order-show-layout"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-communication-aside"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-closing-block"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-header-editor"]'),
        ).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        await expect(
            page.locator('[data-test="dispo-order-communication-aside"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-submit-open"]'),
        ).toBeVisible();
    });
});
