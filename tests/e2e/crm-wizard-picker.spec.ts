import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { adoptFreitext, selectAccountHit } from './helpers/crm-wizard-picker';

/**
 * CRM-Wizard-Picker Browser-Belege (isoliert Port 8064 / SQLite).
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
        `crm-picker-${Date.now()}-${Math.random()}.csv`,
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
    await saveAndWaitForPickerSync(page);
}

/** Wartet auf abgeschlossenen Save inkl. Picker-Sync (nicht nur URL). */
async function saveAndWaitForPickerSync(page: Page) {
    const responsePromise = page.waitForResponse(
        (response) =>
            (response.request().method() === 'POST' ||
                response.request().method() === 'PUT') &&
            /\/kalkulationen(\/\d+)?$/.test(
                new URL(response.url()).pathname,
            ) &&
            response.status() >= 200 &&
            response.status() < 400,
        { timeout: 30_000 },
    );
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await responsePromise;
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
    await expect(
        page.locator('[data-test="crm-customer-freitext-hint"]'),
    ).toHaveCount(0, { timeout: 15_000 });
    await expect(
        page.getByText('Kalkulation gespeichert').first(),
    ).toBeVisible({ timeout: 15_000 });
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

test.describe('CRM Wizard Picker', () => {
    test('Freitext ohne Domain → Provisional → Reload → Dispo', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(page.locator('[data-test="crm-customer"]')).toBeVisible({
            timeout: 30_000,
        });

        await adoptFreitext(page, 'crm-customer', 'Picker Freitext Kunde');
        await expect(
            page.locator('[data-test="crm-customer-freitext-hint"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="customer-meridian-pending"]'),
        ).toContainText('Meridian-Nummer folgt');
        await page.locator('#invoice-recipient').selectOption('customer');

        await fillMinimalSpotsAndSave(page);
        await page.reload();
        await expect(
            page.locator('[data-test="crm-customer-selected-name"]'),
        ).toHaveText('Picker Freitext Kunde');
        await expect(
            page.locator('[data-test="customer-meridian-pending"]'),
        ).toBeVisible();

        const search = await page.request.get(
            '/crm/accounts/suche?type=customer&q=Picker%20Freitext%20Kunde',
        );
        const accounts = (await search.json()).accounts ?? [];
        expect(accounts.length).toBeGreaterThanOrEqual(1);
        expect(accounts[0].is_provisional).toBeTruthy();

        await createDispoFromCurrentCalc(page);
        await expect(page.getByText('Picker Freitext Kunde')).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-customer-meridian"]'),
        ).toContainText(/folgt|Meridian/i);
    });

    test('Account suchen/wählen → Meridian; Clear → Freitext; Agentur RE', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'admin@example.com');
        const tmp = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Picker SF Kunde;M-PICK-K;001xx00000PICKK;k@picker-kunde.test;Account KUNDE',
            'Picker SF Agentur;M-PICK-A;001xx00000PICKA;a@picker-agentur.test;Account AGENTUR',
            '',
        ]);
        await uploadAndApply(page, tmp);
        fs.unlinkSync(tmp);

        const customerSearch = await page.request.get(
            '/crm/accounts/suche?type=customer&q=Picker%20SF%20Kunde',
        );
        const agencySearch = await page.request.get(
            '/crm/accounts/suche?type=agency&q=Picker%20SF%20Agentur',
        );
        const customerId = Number(
            (await customerSearch.json()).accounts?.[0]?.id,
        );
        const agencyId = Number((await agencySearch.json()).accounts?.[0]?.id);
        expect(customerId).toBeGreaterThan(0);
        expect(agencyId).toBeGreaterThan(0);

        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await selectAccountHit(
            page,
            'crm-customer',
            'Picker SF Kunde',
            customerId,
        );
        await expect(
            page.locator('[data-test="customer-meridian-number"]'),
        ).toContainText('M-PICK-K');

        await page.locator('[data-test="crm-customer-clear"]').click();
        await adoptFreitext(page, 'crm-customer', 'Picker Wechsel Freitext');
        await selectAccountHit(
            page,
            'crm-agency',
            'Picker SF Agentur',
            agencyId,
        );
        await page.locator('#invoice-recipient').selectOption('agency');
        await expect(page.locator('#invoice-recipient')).toHaveValue('agency');

        await fillMinimalSpotsAndSave(page);
        await page.reload();
        await expect(
            page.locator('[data-test="crm-customer-selected-name"]'),
        ).toHaveText('Picker Wechsel Freitext');
        await expect(
            page.locator('[data-test="crm-agency-selected-name"]'),
        ).toHaveText('Picker SF Agentur');
        await expect(page.locator('#invoice-recipient')).toHaveValue('agency');
        await expect(
            page.locator('[data-test="agency-meridian-number"]'),
        ).toContainText('M-PICK-A');

        await page.locator('[data-test="crm-agency-clear"]').click();
        await expect(page.locator('#invoice-recipient')).not.toHaveValue(
            'agency',
        );
        await page.locator('#invoice-recipient').selectOption('customer');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
        await page.reload();
        await expect(
            page.locator('[data-test="crm-agency-search"]'),
        ).toBeVisible();
        await expect(page.locator('#invoice-recipient')).toHaveValue('customer');
    });

    test('Wiederholter Save ohne Reload erzeugt keinen zweiten Provisional', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await adoptFreitext(page, 'crm-customer', 'Picker Idempotent GmbH');
        await adoptFreitext(page, 'crm-agency', 'Picker Idempotent Agentur');
        await page.locator('#invoice-recipient').selectOption('agency');

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-spots-0-0"]').fill('5');
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.waitForTimeout(1500);

        const beforePreview = await page.request.get(
            '/crm/accounts/suche?type=customer&q=Picker%20Idempotent%20GmbH',
        );
        expect((await beforePreview.json()).accounts?.length ?? 0).toBe(0);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await saveAndWaitForPickerSync(page);

        const afterFirstCustomer = await page.request.get(
            '/crm/accounts/suche?type=customer&q=Picker%20Idempotent%20GmbH',
        );
        const afterFirstAgency = await page.request.get(
            '/crm/accounts/suche?type=agency&q=Picker%20Idempotent%20Agentur',
        );
        const customerList = (await afterFirstCustomer.json()).accounts ?? [];
        const agencyList = (await afterFirstAgency.json()).accounts ?? [];
        expect(customerList.length).toBe(1);
        expect(agencyList.length).toBe(1);
        const customerId = customerList[0].id;
        const agencyId = agencyList[0].id;

        // Ohne Reload: Picker muss Account-Mode sein (Sync nach Save).
        await expect(
            page.locator('[data-test="crm-customer-freitext-hint"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="crm-agency-freitext-hint"]'),
        ).toHaveCount(0);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await saveAndWaitForPickerSync(page);

        const afterSecondCustomer = await (
            await page.request.get(
                '/crm/accounts/suche?type=customer&q=Picker%20Idempotent%20GmbH',
            )
        ).json();
        const afterSecondAgency = await (
            await page.request.get(
                '/crm/accounts/suche?type=agency&q=Picker%20Idempotent%20Agentur',
            )
        ).json();
        expect(afterSecondCustomer.accounts?.length ?? 0).toBe(1);
        expect(afterSecondAgency.accounts?.length ?? 0).toBe(1);
        expect(afterSecondCustomer.accounts[0].id).toBe(customerId);
        expect(afterSecondAgency.accounts[0].id).toBe(agencyId);
    });

    test('Veraltete Suchantworten überschreiben neuere Eingaben nicht', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'admin@example.com');
        const tmp = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            'Firma Alpha Suche;M-A;001xx00000ALPH1;a@alpha-suche.test;Account KUNDE',
            'Firma Beta Suche;M-B;001xx00000BETA1;b@beta-suche.test;Account KUNDE',
            '',
        ]);
        await uploadAndApply(page, tmp);
        fs.unlinkSync(tmp);

        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');

        let releaseAlpha: (() => void) | undefined;
        const alphaGate = new Promise<void>((resolve) => {
            releaseAlpha = resolve;
        });

        await page.route('**/crm/accounts/suche**', async (route) => {
            const url = new URL(route.request().url());
            const q = url.searchParams.get('q') ?? '';
            if (q.includes('Alpha')) {
                await alphaGate;
            }
            await route.continue();
        });

        await page.locator('[data-test="crm-customer-search"]').fill('Firma Alpha Suche');
        await page.locator('[data-test="crm-customer-search-btn"]').click();
        await expect(page.locator('[data-test="crm-customer-loading"]')).toBeVisible();

        await page.locator('[data-test="crm-customer-search"]').fill('Firma Beta Suche');
        await expect(page.locator('[data-test="crm-customer-hits"]')).toHaveCount(0);
        await page.locator('[data-test="crm-customer-search-btn"]').click();
        await expect(
            page.locator('[data-test="crm-customer-hits"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-test="crm-customer-hits"]')).toContainText(
            'Firma Beta Suche',
        );
        await expect(page.locator('[data-test="crm-customer-hits"]')).not.toContainText(
            'Firma Alpha Suche',
        );

        releaseAlpha?.();
        await page.waitForTimeout(500);
        await expect(page.locator('[data-test="crm-customer-hits"]')).toContainText(
            'Firma Beta Suche',
        );
        await expect(page.locator('[data-test="crm-customer-hits"]')).not.toContainText(
            'Firma Alpha Suche',
        );

        await page.locator('[data-test="crm-customer-search"]').fill('Firma Alpha Suche');
        await expect(page.locator('[data-test="crm-customer-hits"]')).toHaveCount(0);
        await expect(page.locator('[data-test="crm-customer-loading"]')).toHaveCount(0);
    });

    test('Historischer Freitext bleibt ohne Auto-Migration speicherbar', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen');
        // Index zeigt Kampagne, nicht Kundenname (Seed: campaign „Legacy-Freitext“).
        const row = page.locator('tr', { hasText: 'Legacy-Freitext' });
        await expect(row).toBeVisible({ timeout: 30_000 });
        await row.locator('a[href*="/kalkulationen/"]').first().click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="crm-customer-legacy-hint"]'),
        ).toBeVisible({ timeout: 30_000 });
        await expect(
            page.locator('[data-test="crm-customer-selected-name"]'),
        ).toHaveText('E2E Legacy Freitext GmbH');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
        await page.reload();
        await expect(
            page.locator('[data-test="crm-customer-legacy-hint"]'),
        ).toBeVisible();
        const search = await page.request.get(
            '/crm/accounts/suche?type=customer&q=E2E%20Legacy%20Freitext%20GmbH',
        );
        expect((await search.json()).accounts?.length ?? 0).toBe(0);
    });
});
