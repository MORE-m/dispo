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

async function waitHeaderSaveReady(page: Page) {
    const save = page.locator('[data-test="dispo-order-header-save"]');
    await expect(save).toBeVisible({ timeout: 15_000 });
    await expect(save).toBeEnabled({ timeout: 30_000 });
    await expect(save).toHaveText('Kopfdaten speichern', { timeout: 30_000 });
}

async function saveHeader(page: Page) {
    await waitHeaderSaveReady(page);
    await page.locator('[data-test="dispo-order-header-save"]').click();
    await expect(page.getByText('Kopfdaten gespeichert')).toBeVisible({
        timeout: 20_000,
    });
    await waitHeaderSaveReady(page);
}

async function createDraftDispoOrder(page: Page) {
    await page.goto('/kalkulationen/neu');
    await expect(
        page.locator('[data-test="campaign-period-start"]'),
    ).toHaveCount(0);
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

test.describe('PO-CALC-DISPO-UX-1 PR5 operativer Zeitraum', () => {
    test('Wizard ohne Zeitraum; Dispo-Header setzen/löschen; Submit ohne Zeitraum', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await createDraftDispoOrder(page);

        await expect(
            page.locator('[data-test="dispo-order-header-editor"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-operational-campaign-starts-on"]'),
        ).toHaveValue('');
        await expect(
            page.locator('[data-test="dispo-operational-campaign-ends-on"]'),
        ).toHaveValue('');

        await page.locator('[data-test="dispo-invoice-recipient"]').selectOption(
            'customer',
        );
        await page
            .locator('[data-test="dispo-operational-campaign-starts-on"]')
            .fill('2026-11-01');
        await page
            .locator('[data-test="dispo-operational-campaign-ends-on"]')
            .fill('2026-11-30');
        await saveHeader(page);
        await expect(
            page.locator('[data-test="dispo-operational-campaign-starts-on"]'),
        ).toHaveValue('2026-11-01');

        // Halbes Paar → Validierungsfehler
        await page
            .locator('[data-test="dispo-operational-campaign-ends-on"]')
            .fill('');
        await waitHeaderSaveReady(page);
        await page.locator('[data-test="dispo-order-header-save"]').click();
        await expect(page.getByRole('alert').first()).toBeVisible({
            timeout: 15_000,
        });
        await waitHeaderSaveReady(page);

        // Zeitraum vollständig löschen
        await page
            .locator('[data-test="dispo-operational-campaign-starts-on"]')
            .fill('');
        await page
            .locator('[data-test="dispo-operational-campaign-ends-on"]')
            .fill('');
        await saveHeader(page);
        await expect(
            page.locator('[data-test="dispo-operational-campaign-starts-on"]'),
        ).toHaveValue('');

        const withoutUpload = page.locator(
            '[data-test="dispo-order-customer-confirmation-without-upload"]',
        );
        if (await withoutUpload.count()) {
            await withoutUpload.check();
            const reason = page.locator(
                '[data-test="dispo-order-customer-confirmation-exception-reason"]',
            );
            if (await reason.count()) {
                await reason.fill('E2E Ausnahme PR5');
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
            page.getByText(/eingereicht|Freigabe/i).first(),
        ).toBeVisible({ timeout: 20_000 });
    });
});
