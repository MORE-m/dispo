import { expect, test, type Page } from '@playwright/test';
import { adoptFreitext } from './helpers/crm-wizard-picker';
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

test.describe('PO-CALC-DISPO-UX-1 PR4 Calc-Cleanup', () => {
    test('Neue Calc: keine Agentur/RE/Briefing; Kunde + Zeitraum; Dispo leer', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');

        await expect(page.locator('[data-test="crm-customer"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.locator('[data-test="crm-agency"]')).toHaveCount(0);
        await expect(page.locator('#invoice-recipient')).toHaveCount(0);
        await expect(page.locator('#briefing')).toHaveCount(0);
        // PO-PR4-01: Kampagnenzeitraum bleibt bis PR5 im Wizard.
        await expect(page.getByText('Kampagnenzeitraum').first()).toBeVisible({
            timeout: 15_000,
        });

        await ensureWizardCustomerFreitext(page);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await ensureWizardInventorySelected(page);
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });

        await page.reload();
        await expect(page.locator('[data-test="crm-agency"]')).toHaveCount(0);
        await expect(page.locator('#invoice-recipient')).toHaveCount(0);

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });

        await expect(
            page.locator('[data-test="dispo-order-header-editor"]'),
        ).toBeVisible();
        await expect(page.locator('[data-test="dispo-invoice-recipient"]')).toHaveValue(
            '',
        );
        await expect(page.locator('[data-test="dispo-briefing"]')).toHaveValue('');
        await expect(
            page.locator('[data-test="dispo-crm-agency-search"]'),
        ).toBeVisible();

        await adoptFreitext(page, 'dispo-crm-agency', 'PR4 Dispo Agentur');
        await page
            .locator('[data-test="dispo-invoice-recipient"]')
            .selectOption('customer');
        await page.locator('[data-test="dispo-briefing"]').fill('PR4 Dispo Briefing');
        await page.locator('[data-test="dispo-order-header-save"]').click();
        await expect(page.getByText('Kopfdaten gespeichert')).toBeVisible({
            timeout: 15_000,
        });
        await expect(page.locator('[data-test="dispo-briefing"]')).toHaveValue(
            'PR4 Dispo Briefing',
        );
    });
});
