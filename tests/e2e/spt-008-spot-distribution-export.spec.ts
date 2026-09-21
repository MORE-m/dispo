import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(root, 'database/e2e-spt008-orders.json');
const readerPath = path.join(
    root,
    'tests/e2e/helpers/read-spot-distribution-xlsx.php',
);

type OrdersFixture = {
    calendar: { id: number; number: string };
    tandem: { id: number; number: string };
    mixed: { id: number; number: string };
    average: { id: number; number: string };
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function readXlsx(filePath: string): {
    sheet_names: string[];
    active_title: string;
    headers: string[];
    row_count: number;
    rows: string[][];
} {
    const raw = execFileSync('php', [readerPath, filePath], {
        encoding: 'utf8',
    });

    return JSON.parse(raw) as {
        sheet_names: string[];
        active_title: string;
        headers: string[];
        row_count: number;
        rows: string[][];
    };
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

test.describe('SPT-008 Spotverteilungs-Export', () => {
    test('calendar order downloads xlsx without commercial columns', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.calendar.id}`);

        const button = page.locator(
            '[data-test="dispo-spot-distribution-export-button"]',
        );
        await expect(button).toBeEnabled();

        const downloadPromise = page.waitForEvent('download');
        await button.click();
        const download = await downloadPromise;
        const suggested = download.suggestedFilename();
        expect(suggested).toMatch(/_Spotverteilung\.xlsx$/);
        expect(suggested).toContain(orders.calendar.number.replace(/\//g, '_'));

        const target = path.join(
            root,
            'database',
            `e2e-spt008-download-${Date.now()}.xlsx`,
        );
        await download.saveAs(target);
        const workbook = readXlsx(target);

        expect(workbook.sheet_names).toEqual(['Spotverteilung']);
        expect(workbook.active_title).toBe('Spotverteilung');
        expect(workbook.headers).toEqual([
            'Dispoauftrag',
            'Kunde',
            'Position',
            'Inventar',
            'Werbemittel',
            'Datum',
            'Wochentag',
            'Stunde',
            'Tagesgruppe',
            'Menge',
            'Mengeneinheit',
            'Gesamtlänge in Sekunden',
            'Komponenten',
            'Bestandteilausstrahlungen',
        ]);
        expect(workbook.row_count).toBe(2);
        const joinedHeaders = workbook.headers.join('|').toLowerCase();
        expect(joinedHeaders).not.toContain('preis');
        expect(joinedHeaders).not.toContain('rabatt');
        expect(joinedHeaders).not.toContain('festpreis');
        expect(joinedHeaders).not.toContain('ae');
    });

    test('tandem exports units without price multiplication', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'disposition@example.com');
        await page.goto(`/dispoauftraege/${orders.tandem.id}`);

        const downloadPromise = page.waitForEvent('download');
        await page
            .locator('[data-test="dispo-spot-distribution-export-button"]')
            .click();
        const download = await downloadPromise;
        const target = path.join(
            root,
            'database',
            `e2e-spt008-tandem-${Date.now()}.xlsx`,
        );
        await download.saveAs(target);
        const workbook = readXlsx(target);

        expect(workbook.row_count).toBe(1);
        expect(workbook.rows[0][10]).toBe('Tandem-Einheiten');
        expect(workbook.rows[0][9]).toBe('4');
        expect(workbook.rows[0][13]).toBe('8');
        expect(workbook.rows[0][12]).toContain('Hauptspot');
        expect(workbook.rows[0][12]).toContain('Reminder');
    });

    test('mixed order exports only calendar and shows hint', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.mixed.id}`);

        await expect(
            page.locator(
                '[data-test="dispo-spot-distribution-export-mixed-hint"]',
            ),
        ).toHaveText('Der Export enthält nur kalendergeplante Positionen.');

        const downloadPromise = page.waitForEvent('download');
        await page
            .locator('[data-test="dispo-spot-distribution-export-button"]')
            .click();
        const download = await downloadPromise;
        const target = path.join(
            root,
            'database',
            `e2e-spt008-mixed-${Date.now()}.xlsx`,
        );
        await download.saveAs(target);
        const workbook = readXlsx(target);
        expect(workbook.row_count).toBe(1);
    });

    test('average-only order disables export button', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.average.id}`);

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
