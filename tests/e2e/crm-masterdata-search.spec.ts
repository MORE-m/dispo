import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { selectAccountHit } from './helpers/crm-wizard-picker';

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../database/e2e-crm-masterdata-search.sqlite',
);

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

async function xsrfToken(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const raw = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;
    expect(raw).toBeTruthy();
    return decodeURIComponent(raw!);
}

async function createProvisionalViaApi(
    page: Page,
    name: string,
    type: 'customer' | 'agency' = 'customer',
    matchingDomain: string | null = null,
) {
    const response = await page.request.post('/crm/accounts/vorlaeufig', {
        data: {
            name,
            type,
            billing_email: null,
            matching_domain: matchingDomain,
        },
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': await xsrfToken(page),
        },
    });
    expect(
        response.ok(),
        `provisional create failed: ${response.status()} ${await response.text()}`,
    ).toBeTruthy();
    const body = (await response.json()) as {
        account: { id: number; name: string | null };
    };
    return body.account;
}

function writeCsv(rows: string[]): string {
    const tmp = path.join(
        os.tmpdir(),
        `crm-masterdata-${Date.now()}-${Math.random()}.csv`,
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

function setCalcCustomerMeridian(calcId: number, meridian: string) {
    execFileSync(
        'php',
        [
            '-r',
            [
                '$pdo = new PDO("sqlite:" . getenv("CRM_MD_E2E_DB"));',
                '$st = $pdo->prepare("update calculations set customer_meridian_number=? where id=?");',
                '$st->execute([getenv("CRM_MD_E2E_MERIDIAN"), (int) getenv("CRM_MD_E2E_CALC_ID")]);',
                'echo $st->rowCount();',
            ].join(''),
        ],
        {
            encoding: 'utf8',
            env: {
                ...process.env,
                CRM_MD_E2E_DB: e2eDb,
                CRM_MD_E2E_CALC_ID: String(calcId),
                CRM_MD_E2E_MERIDIAN: meridian,
            },
        },
    );
}

test.describe('CRM Stammdaten-Suche und Prüflisten-Labels', () => {
    test('Suche, Filter, Reset, Reload und verständliche Labels', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'admin@example.com');

        const unique = `Masterdata Suche ${Date.now()}`;
        const twinA = await createProvisionalViaApi(
            page,
            unique,
            'customer',
            'masterdata-a.test',
        );
        const twinB = await createProvisionalViaApi(
            page,
            unique,
            'customer',
            'masterdata-b.test',
        );
        await createProvisionalViaApi(page, 'Andere Agentur Suche', 'agency');

        for (let i = 0; i < 52; i++) {
            await createProvisionalViaApi(
                page,
                `Pagination Filler ${String(i).padStart(2, '0')}`,
                'agency',
            );
        }

        await page.goto('/crm/accounts');
        await expect(
            page.locator('[data-test="crm-accounts-index"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von');

        await page.locator('[data-test="crm-accounts-search"]').fill(unique);
        await page.locator('[data-test="crm-accounts-search-submit"]').click();
        await expect(page).toHaveURL(/q=/);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="crm-account-row-${twinB.id}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toContainText('masterdata-a.test');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinB.id}"]`),
        ).toContainText('masterdata-b.test');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toContainText('Meridian-Nummer folgt');

        await page.reload();
        await expect(
            page.locator('[data-test="crm-accounts-search"]'),
        ).toHaveValue(unique);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');

        await page.goto(
            `/crm/accounts?q=${encodeURIComponent(unique)}&type=customer&provisional=1`,
        );
        await expect(page).toHaveURL(/type=customer/);
        await expect(page).toHaveURL(/provisional=1/);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');
        await expect(
            page.locator('[data-test="crm-accounts-type-filter"]'),
        ).toHaveValue('customer');
        await expect(
            page.locator('[data-test="crm-accounts-provisional-filter"]'),
        ).toBeChecked();

        await page.locator('[data-test="crm-accounts-search-reset"]').click();
        await expect(page).not.toHaveURL(/[?&]q=/);
        await expect(page).toHaveURL(/type=customer/);
        await expect(page).toHaveURL(/provisional=1/);
        await expect(
            page.locator('[data-test="crm-accounts-search"]'),
        ).toHaveValue('');

        await page.goto('/crm/accounts?type=agency');
        await expect(
            page.locator('[data-test="crm-accounts-pagination"]'),
        ).toBeVisible({ timeout: 15_000 });
        await page.locator('[data-test="crm-accounts-page-next"]').click();
        await expect(page).toHaveURL(/page=2/);
        await expect(
            page.locator('[data-test="crm-accounts-pagination"]'),
        ).toContainText('Seite 2');

        await page.goto('/crm/accounts/pruefliste');
        await expect(page.locator('[data-test="crm-match-queue"]')).toBeVisible();
        const source = page.locator(
            `[data-test="crm-account-label-source-${twinA.id}"]`,
        );
        await expect(source).toBeVisible();
        await expect(source).toContainText(unique);
        await expect(source).toContainText('Kunde · vorläufig');
        await expect(source).toContainText('masterdata-a.test');
        await expect(source).toContainText('Meridian-Nummer folgt');
        await expect(source).not.toContainText(`ID ${twinA.id}`);

        await page
            .locator(`[data-test="crm-manual-search-${twinA.id}"]`)
            .fill('GibtsNichtXYZ');
        await page
            .locator(`[data-test="crm-manual-search-btn-${twinA.id}"]`)
            .click();
        await expect(
            page.locator(`[data-test^="crm-manual-link-${twinA.id}-"]`),
        ).toHaveCount(0);
    });

    test('Auftrags-Meridian-Konflikt zeigt Vorgangsnummer, Seite und Nummern', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await login(page, 'admin@example.com');

        const stamp = Date.now();
        const domain = `order-mm-${stamp}.test`;
        const sfId = `001xx000003${String(stamp).slice(-4)}`;
        const csv = writeCsv([
            'Accountname;Meridian-ID;Account-ID;Rechnungs-E-Mail;Account-Datensatztyp',
            `Order MM Browser;M-KEEP-BR;${sfId};billing@${domain};Account KUNDE`,
            '',
        ]);
        await uploadAndApply(page, csv);

        const search = await page.request.get(
            `/crm/accounts/suche?type=customer&q=${encodeURIComponent('Order MM Browser')}`,
        );
        expect(search.ok()).toBeTruthy();
        const searchJson = (await search.json()) as {
            accounts?: { id: number; name: string | null }[];
        };
        const accountId = searchJson.accounts?.[0]?.id;
        expect(accountId).toBeTruthy();

        await page.goto('/kalkulationen/neu');
        await expect(page.locator('[data-test="crm-customer"]')).toBeVisible({
            timeout: 30_000,
        });
        await selectAccountHit(
            page,
            'crm-customer',
            'Order MM Browser',
            accountId!,
        );
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });

        const calcMatch = page.url().match(/kalkulationen\/(\d+)/);
        expect(calcMatch).toBeTruthy();
        const calcId = Number(calcMatch![1]);

        setCalcCustomerMeridian(calcId, 'M-OTHER-BR');
        await uploadAndApply(page, csv);

        await page.goto('/crm/accounts/pruefliste');
        await expect(page.locator('[data-test="crm-match-queue"]')).toBeVisible();
        await expect(page.locator('[data-test="crm-open-conflicts"]')).toBeVisible({
            timeout: 15_000,
        });

        const conflictCard = page.locator('[data-test^="crm-conflict-"]').filter({
            hasText: 'Abweichende Meridian-Nummer (Auftrag)',
        });
        await expect(conflictCard.first()).toBeVisible();
        const summary = conflictCard
            .first()
            .locator('[data-test^="crm-conflict-summary-"]');
        await expect(summary).toContainText('Betroffene Seite: Kunde');
        await expect(summary).toContainText('Bestehende Meridian-Nummer: M-OTHER-BR');
        await expect(summary).toContainText('Eingehende Meridian-Nummer: M-KEEP-BR');
        await expect(summary).toContainText('Betroffener Vorgang: Kalkulation');
        await expect(summary).toContainText(/K-\d{4}-\d+/);

        await page.reload();
        await expect(summary).toContainText('Bestehende Meridian-Nummer: M-OTHER-BR');
        await expect(summary).toContainText('Eingehende Meridian-Nummer: M-KEEP-BR');
        await expect(summary).toContainText('Betroffene Seite: Kunde');

        fs.unlinkSync(csv);
    });
});
