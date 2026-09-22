import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(
    root,
    'database/e2e-derived-campaign-period-orders.json',
);

type OrderFixture = {
    id: number;
    number: string;
    status: string;
    expected_start?: string;
    expected_end?: string;
    calc_start?: string;
    calc_end?: string;
};

type OrdersFixture = {
    year: number;
    complete: OrderFixture;
    partial: OrderFixture;
    open: OrderFixture;
    conflict: OrderFixture;
    identical: OrderFixture;
    legacy: OrderFixture;
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function formatDe(iso: string): string {
    const [y, m, d] = iso.split('-');
    return `${d}.${m}.${y}`;
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

test.describe('DSP-DCP-001 abgeleiteter Kampagnenzeitraum', () => {
    test('complete: calendar + closed average shows Vollständig', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.complete.id}`);

        const derived = page.locator(
            '[data-test="dispo-order-derived-campaign-period"]',
        );
        await expect(derived).toBeVisible();
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-status"]',
            ),
        ).toContainText('Vollständig');
        await expect(derived).toContainText(
            `${formatDe(orders.complete.expected_start!)}–${formatDe(orders.complete.expected_end!)}`,
        );
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-conflict"]',
            ),
        ).toHaveCount(0);
    });

    test('partial: shows Teilweise and unresolved hint', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.partial.id}`);

        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-status"]',
            ),
        ).toContainText('Teilweise');
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period"]',
            ),
        ).toContainText(
            `${formatDe(orders.partial.expected_start!)}–${formatDe(orders.partial.expected_end!)}`,
        );
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-explanation"]',
            ),
        ).toContainText('nur Positionen mit konkreten Datumsangaben');
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-unresolved"]',
            ),
        ).toBeVisible();
    });

    test('open: no date and status Offen', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'disposition@example.com');
        await page.goto(`/dispoauftraege/${orders.open.id}`);

        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-status"]',
            ),
        ).toContainText('Offen');
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-explanation"]',
            ),
        ).toContainText('einen konkreten Zeitraum');
    });

    test('conflict: both periods visible with non-blocking hint', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.conflict.id}`);

        await expect(
            page.locator('[data-test="dispo-order-campaign-period"]'),
        ).toContainText(formatDe(orders.conflict.calc_start!));
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period"]',
            ),
        ).toContainText(
            `${formatDe(orders.conflict.expected_start!)}–${formatDe(orders.conflict.expected_end!)}`,
        );
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-conflict"]',
            ),
        ).toContainText('weicht vom abgeleiteten');
    });

    test('identical: no conflict warning', async ({ page }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.identical.id}`);

        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-status"]',
            ),
        ).toContainText('Vollständig');
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-conflict"]',
            ),
        ).toHaveCount(0);
    });

    test('legacy: historical hint without derivation claim', async ({
        page,
    }) => {
        const orders = loadOrders();
        await login(page, 'sales@example.com');
        await page.goto(`/dispoauftraege/${orders.legacy.id}`);

        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-status"]',
            ),
        ).toContainText('Historischer Auftrag');
        await expect(
            page.locator(
                '[data-test="dispo-order-derived-campaign-period-explanation"]',
            ),
        ).toContainText('historischen Auftrag');
    });
});
