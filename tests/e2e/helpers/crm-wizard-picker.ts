import { expect, type Page } from '@playwright/test';

/** Übernimmt eine freie Firmierung im CRM-Wizard-Picker (ohne Suchtreffer-Zwang). */
export async function adoptFreitext(
    page: Page,
    prefix: 'crm-customer' | 'crm-agency',
    name: string,
) {
    await page.locator(`[data-test="${prefix}-search"]`).fill(name);
    await page.locator(`[data-test="${prefix}-use-freitext"]`).click();
    await expect(
        page.locator(`[data-test="${prefix}-selected-name"]`),
    ).toHaveText(name);
}

/** Wählt einen bestehenden Account aus der Suche (expliziter Treffer-Klick). */
export async function selectAccountHit(
    page: Page,
    prefix: 'crm-customer' | 'crm-agency',
    query: string,
    accountId: number,
) {
    await page.locator(`[data-test="${prefix}-search"]`).fill(query);
    await page.locator(`[data-test="${prefix}-search-btn"]`).click();
    await expect(page.locator(`[data-test="${prefix}-hits"]`)).toBeVisible({
        timeout: 15_000,
    });
    await page.locator(`[data-test="${prefix}-hit-${accountId}"]`).click();
    await expect(
        page.locator(`[data-test="${prefix}-selected-name"]`),
    ).toBeVisible();
}
