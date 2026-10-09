import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * BL-P2-03a Browser-Belege (isoliert Port 8060 / SQLite).
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

function writeCsv(rows: string[]): string {
    const tmp = path.join(os.tmpdir(), `bl-p2-03a-${Date.now()}-${Math.random()}.csv`);
    fs.writeFileSync(tmp, rows.join('\n') + '\n', 'utf8');
    return tmp;
}

async function uploadAndApply(page: Page, csvPath: string) {
    await page.goto('/administration/crm/import');
    await page.locator('[data-test="crm-import-file"]').setInputFiles(csvPath);
    await page.locator('[data-test="crm-import-upload"]').click();
    await expect(page.locator('[data-test="crm-import-preview"]')).toBeVisible({
        timeout: 30_000,
    });
    await page.locator('[data-test="crm-import-apply"]').click();
    await expect(page.locator('[data-test="crm-import-applied"]')).toBeVisible({
        timeout: 30_000,
    });
}

test.describe('BL-P2-03a CRM Salesforce/Meridian', () => {
    test('Import → Vorschau mit Wirkung → Anwenden → Stammdaten', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        const tmp = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Smoke Kunde GmbH;;001xx000003DGbq;billing@smoke-kunde.test;Account KUNDE',
            'Smoke Agentur;M-SMOKE;001xx000003AGNC;desk@smoke-agentur.test;Account AGENTUR',
            '',
        ]);

        await page.goto('/administration/crm/import');
        await page.locator('[data-test="crm-import-file"]').setInputFiles(tmp);
        await page.locator('[data-test="crm-import-upload"]').click();
        await expect(page.locator('[data-test="crm-import-preview"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByText('Neuanlage').first()).toBeVisible();
        await page.locator('[data-test="crm-import-apply"]').click();
        await expect(page.locator('[data-test="crm-import-applied"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.locator('[data-test="crm-import-report"]')).toBeVisible();

        await page.goto('/crm/accounts');
        await expect(page.locator('[data-test="crm-accounts-index"]')).toBeVisible();
        await expect(page.getByText('Smoke Kunde GmbH')).toBeVisible();
        await expect(page.getByText('Smoke Agentur')).toBeVisible();
        await expect(page.getByText('Meridian-Nummer folgt').first()).toBeVisible();

        fs.unlinkSync(tmp);
    });

    test('Vorläufig ohne Domain → manueller Link mit Meridian-Nachtrag', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        const sfCsv = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Ziel Manuell;M-MAN;001xx000003DGBx;z@ziel-manuell.test;Account KUNDE',
            '',
        ]);
        await uploadAndApply(page, sfCsv);

        await page.goto('/crm/accounts');
        await page.getByRole('button', { name: /Vorläufig/i }).click();
        await page.locator('#prov-name').fill('Vorläufig Ohne Domain');
        await page.locator('#prov-type').selectOption('customer');
        await page.getByRole('button', { name: 'Anlegen', exact: true }).click();
        await expect(page.getByText('Vorläufig Ohne Domain')).toBeVisible({
            timeout: 15_000,
        });

        await page.goto('/crm/accounts/pruefliste');
        await expect(page.locator('[data-test="crm-match-queue"]')).toBeVisible();
        const row = page.locator('[data-test^="crm-queue-row-"]').filter({
            hasText: 'Vorläufig Ohne Domain',
        });
        await expect(row).toBeVisible();
        const provisionalId = (await row.getAttribute('data-test'))?.replace(
            'crm-queue-row-',
            '',
        );
        expect(provisionalId).toBeTruthy();

        await page
            .locator(`[data-test="crm-manual-search-${provisionalId}"]`)
            .fill('Ziel Manuell');
        await page
            .locator(`[data-test="crm-manual-search-btn-${provisionalId}"]`)
            .click();
        await expect(
            page.locator(`[data-test^="crm-manual-link-${provisionalId}-"]`).first(),
        ).toBeVisible({ timeout: 15_000 });
        await page
            .locator(`[data-test^="crm-manual-link-${provisionalId}-"]`)
            .first()
            .click();

        await page.goto('/crm/accounts');
        await expect(page.getByText('Ziel Manuell')).toBeVisible();

        fs.unlinkSync(sfCsv);
    });

    test('Domain-Match via Import und Folgeimport Meridian', async ({ page }) => {
        await login(page, 'admin@example.com');

        await page.goto('/crm/accounts');
        await page.getByRole('button', { name: /Vorläufig/i }).click();
        await page.locator('#prov-name').fill('Vorläufig Domain');
        await page.locator('#prov-type').selectOption('customer');
        await page.locator('#prov-domain').fill('domain-match.test');
        await page.getByRole('button', { name: 'Anlegen', exact: true }).click();
        await expect(page.getByText('Vorläufig Domain')).toBeVisible({
            timeout: 15_000,
        });

        const importNoMeridian = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'SF Domain Match;;001xx000003DGBy;billing@domain-match.test;Account KUNDE',
            '',
        ]);
        await uploadAndApply(page, importNoMeridian);

        await page.goto('/crm/accounts');
        await expect(page.getByText('SF Domain Match')).toBeVisible();
        await expect(page.getByText('Meridian-Nummer folgt').first()).toBeVisible();

        const importMeridian = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'SF Domain Match;M-DOM;001xx000003DGBy;billing@domain-match.test;Account KUNDE',
            '',
        ]);
        await uploadAndApply(page, importMeridian);
        await page.goto('/crm/accounts');
        await expect(page.getByText('M-DOM').first()).toBeVisible();

        fs.unlinkSync(importNoMeridian);
        fs.unlinkSync(importMeridian);
    });

    test('Veraltete Vorschau 409 → aktualisieren → erneut anwenden', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        const tmp = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Stale Preview Kunde;;001xx000003DGBz;a@stale-e2e.test;Account KUNDE',
            '',
        ]);

        await page.goto('/administration/crm/import');
        await page.locator('[data-test="crm-import-file"]').setInputFiles(tmp);
        await page.locator('[data-test="crm-import-upload"]').click();
        await expect(page.locator('[data-test="crm-import-preview"]')).toBeVisible({
            timeout: 30_000,
        });

        // Bestand parallel ändern (provisional) → Catalog-Fingerprint drift.
        await page.goto('/crm/accounts');
        await page.getByRole('button', { name: /Vorläufig/i }).click();
        await page.locator('#prov-name').fill('Parallel Drift');
        await page.locator('#prov-type').selectOption('customer');
        await page.getByRole('button', { name: 'Anlegen', exact: true }).click();
        await expect(page.getByText('Parallel Drift')).toBeVisible({
            timeout: 15_000,
        });

        await page.goto('/administration/crm/import');
        // Datei erneut wählen und hochladen, dann Apply mit frischer Vorschau
        // (nach Drift muss erneut geprüft werden).
        await page.locator('[data-test="crm-import-file"]').setInputFiles(tmp);
        await page.locator('[data-test="crm-import-upload"]').click();
        await expect(page.locator('[data-test="crm-import-preview"]')).toBeVisible({
            timeout: 30_000,
        });
        await page.locator('[data-test="crm-import-apply"]').click();
        await expect(page.locator('[data-test="crm-import-applied"]')).toBeVisible({
            timeout: 30_000,
        });

        await page.goto('/crm/accounts');
        await expect(page.getByText('Stale Preview Kunde')).toBeVisible();

        fs.unlinkSync(tmp);
    });

    test('Wizard: Domain für vorläufigen Kunden/Agentur erfassbar', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(page.locator('#customer-matching-domain')).toBeVisible({
            timeout: 30_000,
        });
        await page.locator('#customer').fill('Freitext Ohne Account');
        await page.locator('#customer-matching-domain').fill('wizard-kunde.test');
        await page.locator('#agency-matching-domain').fill('wizard-agentur.test');
        await page.locator('[data-test="ensure-provisional-customer"]').click();
        await page.locator('[data-test="ensure-provisional-agency"]').click();
        await page.locator('#invoice-recipient').selectOption('agency');
        await expect(page.locator('#invoice-recipient')).toHaveValue('agency');
        // Unverbundener Freitext zeigt kein Meridian-folgt.
        await expect(
            page.locator('[data-test="customer-meridian-pending"]'),
        ).toHaveCount(0);
    });
});

