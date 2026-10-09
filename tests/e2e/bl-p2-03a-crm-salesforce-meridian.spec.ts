import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * BL-P2-03a Smoke: CSV-Import → Vorschau → Anwenden → Stammdaten sichtbar.
 * Isolierter Port/SQLite über playwright.blp203a.config.ts.
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

test.describe('BL-P2-03a CRM Salesforce/Meridian', () => {
    test('Admin importiert synthetische CSV und sieht Account', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/crm/import');
        await expect(
            page.getByRole('heading', { name: 'Salesforce-CSV importieren' }),
        ).toBeVisible();

        const csv = [
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Smoke Kunde GmbH;;001xx000003DGbq;billing@smoke-kunde.test;Account KUNDE',
            'Smoke Agentur;M-SMOKE;001xx000003AGNC;desk@smoke-agentur.test;Account AGENTUR',
            '',
        ].join('\n');
        const tmp = path.join(os.tmpdir(), `bl-p2-03a-${Date.now()}.csv`);
        fs.writeFileSync(tmp, csv, 'utf8');

        await page.locator('[data-test="crm-import-file"]').setInputFiles(tmp);
        await page.locator('[data-test="crm-import-upload"]').click();
        await expect(
            page.locator('[data-test="crm-import-preview"]'),
        ).toBeVisible({ timeout: 30_000 });
        await page.locator('[data-test="crm-import-apply"]').click();
        await expect(
            page.locator('[data-test="crm-import-applied"]'),
        ).toBeVisible({ timeout: 30_000 });

        await page.goto('/crm/accounts');
        await expect(page.locator('[data-test="crm-accounts-index"]')).toBeVisible();
        await expect(page.getByText('Smoke Kunde GmbH')).toBeVisible();
        await expect(page.getByText('Smoke Agentur')).toBeVisible();
        await expect(page.getByText('Meridian-Nummer folgt').first()).toBeVisible();

        fs.unlinkSync(tmp);
    });
});
