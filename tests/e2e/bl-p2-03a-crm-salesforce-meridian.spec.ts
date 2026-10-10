import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * BL-P2-03a Browser-Belege (isoliert Port 8060 / SQLite).
 */

async function login(page: Page, email: string) {
    await page.goto('/login');
    if (!page.url().includes('/login')) {
        await page.locator('[data-test="sidebar-menu-button"]').click();
        await page.locator('[data-test="logout-button"]').click();
        await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
            timeout: 15_000,
        });
        await page.goto('/login');
    }
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
    const tmp = path.join(
        os.tmpdir(),
        `bl-p2-03a-${Date.now()}-${Math.random()}.csv`,
    );
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

async function fillMinimalSpotsAndSave(page: Page) {
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
}

async function createDispoFromCurrentCalc(page: Page) {
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(
        page.locator('[data-test="dispo-order-create-open"]'),
    ).toBeVisible({ timeout: 20_000 });
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await expect(
        page.locator('[data-test="dispo-order-create-dialog"]'),
    ).toBeVisible({ timeout: 15_000 });
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 30_000 });
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

    test('Vertikal: vorläufig → Calc → Dispo → Import → Meridian-Nachtrag', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');

        await page.goto('/kalkulationen/neu');
        await expect(page.locator('#customer')).toBeVisible({ timeout: 30_000 });

        // Zuerst übrige Auftragskopf-/Positionsfelder, Domain/Agentur zuletzt (Payload-Regression).
        await page.locator('#customer').fill('Vertikal Kunde Historisch');
        await page.locator('#agency').fill('Vertikal Agentur Historisch');
        await page.locator('#invoice-recipient').selectOption('agency');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await page.getByRole('button', { name: '1. Grunddaten' }).click();

        await page.locator('#customer-matching-domain').fill('vertikal-kunde.test');
        await page
            .locator('[data-test="customer-billing-email"]')
            .fill('billing@vertikal-kunde.test');
        await page.locator('#agency-matching-domain').fill('vertikal-agentur.test');
        await page
            .locator('[data-test="agency-billing-email"]')
            .fill('desk@vertikal-agentur.test');
        await page.locator('[data-test="ensure-provisional-customer"]').click();
        await page.locator('[data-test="ensure-provisional-agency"]').click();

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
        const calcUrl = page.url();

        await page.reload();
        await expect(page.locator('#customer')).toHaveValue(
            'Vertikal Kunde Historisch',
        );
        await expect(page.locator('#agency')).toHaveValue(
            'Vertikal Agentur Historisch',
        );
        await expect(page.locator('#invoice-recipient')).toHaveValue('agency');
        await expect(page.locator('#customer-account-id')).not.toHaveValue('');
        await expect(page.locator('#agency-account-id')).not.toHaveValue('');

        await createDispoFromCurrentCalc(page);
        const dispoUrl = page.url();
        await expect(page.getByText('Agentur').first()).toBeVisible();
        await expect(page.locator('[data-test="dispo-customer-meridian"]')).toContainText(
            'Meridian-Nummer folgt',
        );
        const statusBefore = await page
            .locator('[data-test="dispo-order-status-badge"]')
            .textContent();
        const netBefore = await page
            .locator('[data-test="dispo-order-net-total"]')
            .textContent();

        await login(page, 'admin@example.com');

        const importNoMeridian = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'SF Vertikal Kunde;;001xx000003DGVk;x@vertikal-kunde.test;Account KUNDE',
            'SF Vertikal Agentur;;001xx000003DGVa;y@vertikal-agentur.test;Account AGENTUR',
            '',
        ]);
        await uploadAndApply(page, importNoMeridian);

        await page.goto(calcUrl);
        await expect(page.locator('[data-test="customer-meridian-pending"]')).toBeVisible({
            timeout: 20_000,
        });
        await expect(page.locator('#customer')).toHaveValue(
            'Vertikal Kunde Historisch',
        );

        const importMeridian = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'SF Vertikal Kunde;M-VK;001xx000003DGVk;x@vertikal-kunde.test;Account KUNDE',
            'SF Vertikal Agentur;M-VA;001xx000003DGVa;y@vertikal-agentur.test;Account AGENTUR',
            '',
        ]);
        await uploadAndApply(page, importMeridian);

        await page.goto(calcUrl);
        await page.reload();
        await expect(page.locator('[data-test="customer-meridian-number"]')).toContainText(
            'M-VK',
        );
        await expect(page.locator('[data-test="agency-meridian-number"]')).toContainText(
            'M-VA',
        );
        await expect(page.locator('#customer')).toHaveValue(
            'Vertikal Kunde Historisch',
        );
        await expect(page.locator('#agency')).toHaveValue(
            'Vertikal Agentur Historisch',
        );
        await expect(page.locator('#invoice-recipient')).toHaveValue('agency');
        const linkedCustomerId = await page
            .locator('#customer-account-id')
            .inputValue();

        await page.goto(dispoUrl);
        await page.reload();
        await expect(page.locator('[data-test="dispo-customer-meridian"]')).toContainText(
            'M-VK',
        );
        await expect(page.locator('[data-test="dispo-agency-meridian"]')).toContainText(
            'M-VA',
        );
        await expect(page.getByText('Vertikal Kunde Historisch')).toBeVisible();
        await expect(page.getByText('Vertikal Agentur Historisch')).toBeVisible();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            statusBefore ?? '',
        );
        await expect(page.locator('[data-test="dispo-order-net-total"]')).toHaveText(
            netBefore ?? '',
        );

        await page.goto(`/crm/accounts/${linkedCustomerId}`);
        await expect(
            page.getByRole('heading', { name: 'SF Vertikal Kunde' }),
        ).toBeVisible();
        await expect(page.getByText('M-VK', { exact: true }).first()).toBeVisible();
        await expect(
            page.getByText('vertikal-kunde.test', { exact: true }),
        ).toBeVisible();

        fs.unlinkSync(importNoMeridian);
        fs.unlinkSync(importMeridian);
    });

    test('Manueller Link ohne Domain → Meridian-Nachtrag in Calc/Dispo', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'admin@example.com');

        const sfCsv = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Ziel Manuell;M-MAN;001xx000003DGBx;z@ziel-manuell.test;Account KUNDE',
            '',
        ]);
        await uploadAndApply(page, sfCsv);

        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await page.locator('#customer').fill('Auftrag Ohne Domain');
        await page.locator('[data-test="ensure-provisional-customer"]').click();
        await fillMinimalSpotsAndSave(page);
        const calcUrl = page.url();
        await createDispoFromCurrentCalc(page);
        const dispoUrl = page.url();

        await login(page, 'admin@example.com');
        await page.goto('/crm/accounts/pruefliste');
        await expect(page.locator('[data-test="crm-match-queue"]')).toBeVisible();
        const row = page.locator('[data-test^="crm-queue-row-"]').filter({
            hasText: 'Auftrag Ohne Domain',
        });
        await expect(row).toBeVisible({ timeout: 20_000 });
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

        await page.goto(calcUrl);
        await page.reload();
        await expect(page.locator('[data-test="customer-meridian-number"]')).toContainText(
            'M-MAN',
        );
        await expect(page.locator('#customer')).toHaveValue('Auftrag Ohne Domain');

        await page.goto(dispoUrl);
        await page.reload();
        await expect(page.locator('[data-test="dispo-customer-meridian"]')).toContainText(
            'M-MAN',
        );
        await expect(page.getByText('Auftrag Ohne Domain')).toBeVisible();

        fs.unlinkSync(sfCsv);
    });

    test('Veraltete Vorschau: Tab behalten → 409 → aktualisiert erneut anwenden', async ({
        browser,
    }) => {
        test.setTimeout(180_000);
        const context = await browser.newContext();
        const pageA = await context.newPage();
        const pageB = await context.newPage();

        await login(pageA, 'admin@example.com');
        // Gleicher Browser-Kontext → Seite B ist bereits authentifiziert.

        const tmp = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Stale Preview Kunde;;001xx000003DGBz;a@stale-e2e.test;Account KUNDE',
            '',
        ]);

        await pageA.goto('/administration/crm/import');
        await pageA.locator('[data-test="crm-import-file"]').setInputFiles(tmp);
        await pageA.locator('[data-test="crm-import-upload"]').click();
        await expect(
            pageA.locator('[data-test="crm-import-preview"]'),
        ).toBeVisible({ timeout: 30_000 });

        // Bestand in zweitem Tab ändern → Catalog-Fingerprint drift.
        await pageB.goto('/crm/accounts');
        await pageB.getByRole('button', { name: /Vorläufig/i }).click();
        await pageB.locator('#prov-name').fill('Parallel Drift');
        await pageB.locator('#prov-type').selectOption('customer');
        await pageB.getByRole('button', { name: 'Anlegen', exact: true }).click();
        await expect(pageB.getByText('Parallel Drift')).toBeVisible({
            timeout: 15_000,
        });

        const applyResponsePromise = pageA.waitForResponse(
            (response) =>
                response.url().includes('/administration/crm/import/') &&
                response.url().includes('/anwenden') &&
                response.request().method() === 'POST',
        );
        await pageA.locator('[data-test="crm-import-apply"]').click();
        const applyResponse = await applyResponsePromise;
        expect(applyResponse.status()).toBe(409);
        await expect(pageA.locator('[data-test="crm-import-error"]')).toBeVisible({
            timeout: 15_000,
        });
        await expect(pageA.locator('[data-test="crm-import-preview"]')).toBeVisible();

        // Aktualisierte Vorschau (gleiche Import-ID) erneut anwenden — kein Re-Upload.
        await pageA.locator('[data-test="crm-import-apply"]').click();
        await expect(pageA.locator('[data-test="crm-import-applied"]')).toBeVisible({
            timeout: 30_000,
        });

        await pageA.goto('/crm/accounts');
        await expect(pageA.getByText('Stale Preview Kunde')).toBeVisible();

        fs.unlinkSync(tmp);
        await context.close();
    });

    test('Wizard: Domain zuletzt setzen → gespeicherte Provisional-Domains', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(page.locator('#customer-matching-domain')).toBeVisible({
            timeout: 30_000,
        });

        await page.locator('#customer').fill('Wizard Domain Kunde');
        await page.locator('#agency').fill('Wizard Domain Agentur');
        await page.locator('#invoice-recipient').selectOption('agency');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-spots-0-0"]').fill('5');
        await page.getByRole('button', { name: '1. Grunddaten' }).click();

        // Domain/E-Mail und Vorläufig-Agentur als letzte Aktion.
        await page.locator('#customer-matching-domain').fill('wizard-kunde.test');
        await page
            .locator('[data-test="customer-billing-email"]')
            .fill('c@wizard-kunde.test');
        await page.locator('#agency-matching-domain').fill('wizard-agentur.test');
        await page
            .locator('[data-test="agency-billing-email"]')
            .fill('a@wizard-agentur.test');
        await page.locator('[data-test="ensure-provisional-customer"]').click();
        await page.locator('[data-test="ensure-provisional-agency"]').click();

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });

        await page.reload();
        await expect(page.locator('#customer')).toHaveValue('Wizard Domain Kunde');
        await expect(page.locator('#agency')).toHaveValue('Wizard Domain Agentur');
        await expect(page.locator('#customer-account-id')).not.toHaveValue('');
        await expect(page.locator('#agency-account-id')).not.toHaveValue('');

        const customerAccountId = await page.locator('#customer-account-id').inputValue();
        const agencyAccountId = await page.locator('#agency-account-id').inputValue();

        await login(page, 'admin@example.com');
        await page.goto(`/crm/accounts/${customerAccountId}`);
        await expect(
            page.getByRole('heading', { name: 'Wizard Domain Kunde' }),
        ).toBeVisible();
        await expect(
            page.getByText('wizard-kunde.test', { exact: true }),
        ).toBeVisible();
        await page.goto(`/crm/accounts/${agencyAccountId}`);
        await expect(
            page.getByRole('heading', { name: 'Wizard Domain Agentur' }),
        ).toBeVisible();
        await expect(
            page.getByText('wizard-agentur.test', { exact: true }),
        ).toBeVisible();
    });
});
