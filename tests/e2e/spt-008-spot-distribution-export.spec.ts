import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import {
    diagSnapshot,
    lastServerLogLines,
    logExportFile,
    logFixtureMatrix,
    portOpen,
    readServerExit,
    spt008Port,
} from './helpers/spt008-diag';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(root, 'database/e2e-spt008-orders.json');
const readerPath = path.join(
    root,
    'tests/e2e/helpers/read-spot-distribution-xlsx.php',
);

type SheetSummary = {
    title: string;
    notice: string | null;
    empty_message: string | null;
    headers: string[];
    row_count: number;
    rows: string[][];
};

type Workbook = {
    sheet_names: string[];
    active_title: string;
    headers: string[];
    row_count: number;
    rows: string[][];
    sheets: SheetSummary[];
};

type OrdersFixture = {
    calendar: { id: number; number: string };
    tandem: { id: number; number: string };
    mixed: { id: number; number: string };
    average: { id: number; number: string };
    multi: {
        id: number;
        number: string;
        expected: {
            total_positions: number;
            calendar_positions: number;
            average_positions: number;
            xlsx_calendar_rows: number;
            xlsx_average_rows: number;
            qty_by_position_label: Record<string, number>;
        };
    };
    empty: { id: number; number: string };
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function readXlsx(filePath: string): Workbook {
    const raw = execFileSync('php', [readerPath, filePath], {
        encoding: 'utf8',
    });

    return JSON.parse(raw) as Workbook;
}

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

/**
 * UI-Export auslösen und XLSX über den echten Browser-Download speichern.
 *
 * Produkt-UI: fetch() → Blob → object-URL → <a download>.
 * Kein page.route()/route.fetch()-Proxy (CI-Crash auf streamDownload).
 * Content-Disposition kommt aus dem beobachteten Netzwerk-Response;
 * Dateiinhalt aus dem Download-Event (Blob-Downloads mit acceptDownloads).
 */
async function exportViaUi(
    page: Page,
    targetName: string,
    label: string,
): Promise<{
    target: string;
    contentDisposition: string | null;
}> {
    const button = page.locator(
        '[data-test="dispo-spot-distribution-export-button"]',
    );
    await expect(button).toBeEnabled();

    const target = path.join(root, 'database', targetName);

    console.log(`START EXPORT: ${label}`);
    diagSnapshot(`before-export:${label}`);

    const responsePromise = page.waitForResponse(
        (response) =>
            response.url().includes('/spotverteilung.xlsx') &&
            response.request().method() === 'GET',
        { timeout: 90_000 },
    );
    const downloadPromise = page.waitForEvent('download', {
        timeout: 90_000,
    });

    const clickAt = new Date().toISOString();
    console.log(`SPT008_EXPORT_CLICK label=${label} at=${clickAt}`);
    await button.click();

    let response;
    let download;
    try {
        response = await responsePromise;
        console.log(
            `SPT008_EXPORT_RESPONSE label=${label} status=${response.status()} at=${new Date().toISOString()}`,
        );
        download = await downloadPromise;
        console.log(
            `SPT008_EXPORT_DOWNLOAD label=${label} at=${new Date().toISOString()}`,
        );
    } catch (error) {
        console.log(
            `SPT008_EXPORT_TIMEOUT_OR_ERROR label=${label} at=${new Date().toISOString()} error=${String(error)}`,
        );
        diagSnapshot(`after-export-fail:${label}`);
        console.log(
            `SPT008_PORT_AFTER_FAIL open=${portOpen(spt008Port())} port=${spt008Port()}`,
        );
        console.log(
            `SPT008_SERVER_EXIT_FILE value=${readServerExit() ?? 'none'}`,
        );
        console.log('SPT008_SERVER_LOG_TAIL\n' + lastServerLogLines(60));
        throw error;
    }

    expect(response.ok()).toBe(true);

    const contentDisposition =
        response.headers()['content-disposition'] ?? null;
    await download.saveAs(target);
    logExportFile(label, target);

    await expect(button).toHaveText('Spotplanung exportieren (XLSX)', {
        timeout: 90_000,
    });
    await expect(
        page.locator('[data-test="dispo-spot-distribution-export-error"]'),
    ).toHaveCount(0);

    diagSnapshot(`after-export:${label}`);
    console.log(`END EXPORT: ${label}`);

    return { target, contentDisposition };
}

function assertNoCommercial(headers: string[]) {
    const joined = headers.join('|').toLowerCase();
    expect(joined).not.toContain('preis');
    expect(joined).not.toContain('rabatt');
    expect(joined).not.toContain('festpreis');
    expect(joined).not.toContain('mediabrutto');
}

test.describe('SPT-008 Spotplanungs-Export', () => {
    test.beforeAll(() => {
        const orders = loadOrders();
        logFixtureMatrix(orders);
        diagSnapshot('suite-start');
    });

    test('calendar-only download has two sheets and average empty hint', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.calendar.id}`);

        await expect(
            page.locator('[data-test="dispo-spot-distribution-export-hint"]'),
        ).toHaveText('Der Export enthält die konkrete Spotverteilung.');

        const button = page.locator(
            '[data-test="dispo-spot-distribution-export-button"]',
        );
        await expect(button).toBeEnabled();
        await expect(button).toHaveText('Spotplanung exportieren (XLSX)');

        const { target, contentDisposition } = await exportViaUi(
            page,
            `e2e-spt008-download-${Date.now()}.xlsx`,
            'calendar',
        );
        expect(contentDisposition ?? '').toMatch(/_Spotplanung\.xlsx/);
        const workbook = readXlsx(target);

        expect(workbook.sheet_names).toEqual([
            'Spotverteilung',
            'Planungsvorschlag',
        ]);
        expect(workbook.sheets[0].row_count).toBe(2);
        assertNoCommercial(workbook.sheets[0].headers);
        expect(workbook.sheets[1].empty_message).toContain(
            'keine Average-Planung',
        );
    });

    test('tandem calendar sheet keeps units without price multiplication', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'disposition@example.com');
        await page.goto(`/dispoauftraege/${orders.tandem.id}`);

        const { target } = await exportViaUi(
            page,
            `e2e-spt008-tandem-${Date.now()}.xlsx`,
            'tandem',
        );
        const workbook = readXlsx(target);
        const calendar = workbook.sheets[0];

        expect(calendar.row_count).toBe(1);
        expect(calendar.rows[0][10]).toBe('Tandem-Einheiten');
        expect(calendar.rows[0][9]).toBe('4');
        expect(calendar.rows[0][13]).toBe('8');
    });

    test('average-only download shows proposal notice and rows', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.average.id}`);

        await expect(
            page.locator('[data-test="dispo-spot-distribution-export-button"]'),
        ).toBeEnabled();
        await expect(
            page.locator('[data-test="dispo-spot-distribution-export-hint"]'),
        ).toContainText('unverbindlichen Planungsvorschlag');

        const { target } = await exportViaUi(
            page,
            `e2e-spt008-average-${Date.now()}.xlsx`,
            'average',
        );
        const workbook = readXlsx(target);

        expect(workbook.sheet_names).toEqual([
            'Spotverteilung',
            'Planungsvorschlag',
        ]);
        expect(workbook.sheets[0].empty_message).toContain(
            'keine konkrete Calendar-Spotverteilung',
        );
        const proposal = workbook.sheets[1];
        expect(proposal.notice).toContain('Unverbindlicher Planungsvorschlag');
        expect(proposal.row_count).toBe(1);
        expect(proposal.rows[0][14]).toBe('Vorschlag');
        expect(proposal.rows[0][9]).toBe('10');
        expect(proposal.headers.join('|').toLowerCase()).not.toContain('preis');
        // keine erfundenen Kalenderzeilen
        expect(proposal.headers).not.toContain('Datum');
        expect(proposal.headers).not.toContain('Wochentag');
    });

    test('mixed order exports calendar and average separately', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.mixed.id}`);

        await expect(
            page.locator('[data-test="dispo-spot-distribution-export-hint"]'),
        ).toContainText('separaten Planungsvorschlag');

        const { target } = await exportViaUi(
            page,
            `e2e-spt008-mixed-${Date.now()}.xlsx`,
            'mixed',
        );
        const workbook = readXlsx(target);

        expect(workbook.sheets[0].row_count).toBe(1);
        expect(workbook.sheets[1].row_count).toBe(1);
        expect(workbook.sheets[1].notice).toContain(
            'Unverbindlicher Planungsvorschlag',
        );
        expect(workbook.sheets[1].rows[0][14]).toBe('Vorschlag');
    });

    test('multi-calendar order keeps all calendar positions and average proposal', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.multi.id}`);

        const { target } = await exportViaUi(
            page,
            `e2e-spt008-multi-${Date.now()}.xlsx`,
            'multi',
        );
        const workbook = readXlsx(target);
        const calendar = workbook.sheets[0];
        const proposal = workbook.sheets[1];

        expect(calendar.row_count).toBe(
            orders.multi.expected.xlsx_calendar_rows,
        );
        expect(proposal.row_count).toBe(
            orders.multi.expected.xlsx_average_rows,
        );

        const labels = calendar.rows.map((row) => row[2]);
        expect(new Set(labels)).toEqual(
            new Set(['Position 1', 'Position 2', 'Position 3']),
        );

        const qtyByLabel: Record<string, number> = {};
        for (const row of calendar.rows) {
            qtyByLabel[row[2]] = (qtyByLabel[row[2]] ?? 0) + Number(row[9]);
        }
        expect(qtyByLabel).toEqual(
            orders.multi.expected.qty_by_position_label,
        );

        const overlap = calendar.rows.filter((row) => row[7] === '08:00');
        expect(overlap.length).toBe(3);
        expect(proposal.rows[0][14]).toBe('Vorschlag');
        assertNoCommercial(calendar.headers);
        assertNoCommercial(proposal.headers);
    });

    test('fully empty order disables export button', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.empty.id}`);

        await expect(
            page.locator('[data-test="dispo-spot-distribution-export-button"]'),
        ).toBeDisabled();
        await expect(
            page.locator(
                '[data-test="dispo-spot-distribution-export-disabled-hint"]',
            ),
        ).toBeVisible();
    });

    test('product management has no access', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'pm@example.com');
        const response = await page.goto(
            `/dispoauftraege/${orders.calendar.id}`,
        );
        expect(response?.status()).toBe(403);
    });
});
