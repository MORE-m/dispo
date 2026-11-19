import type { Page } from '@playwright/test';

/**
 * Setzt im Wizard-Schritt 1 einen vorläufigen Kunden (Freitext),
 * damit Calc→Dispo einen gebundenen Kunden und RE=customer erhält (PO-UX-04).
 */
export async function ensureWizardCustomerFreitext(
    page: Page,
    customerName = `E2E Kunde ${Date.now()}`,
): Promise<string> {
    await page.locator('[data-test="crm-customer-search"]').fill(customerName);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();

    return customerName;
}
