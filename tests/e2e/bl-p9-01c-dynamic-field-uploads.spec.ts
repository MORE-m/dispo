import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(root, 'database/e2e-bl-p9-01c-orders.json');
const e2eDb = path.join(root, 'database/e2e-bl-p9-01c.sqlite');
const samplePdf = path.join(
    root,
    'tests/fixtures/customer-confirmation-sample.pdf',
);

type OrderRef = { id: number; number: string };

type OrdersFixture = {
    draft: OrderRef;
    atDisposition: OrderRef;
    field_key: string;
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function uploadRows(orderId: number): number {
    return Number(
        execFileSync(
            'php',
            [
                '-r',
                [
                    '$pdo = new PDO("sqlite:" . getenv("BLP901C_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901C_E2E_ORDER_ID");',
                    '$st = $pdo->prepare("select count(*) from dispo_order_uploads where dispo_order_id=?");',
                    '$st->execute([$orderId]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901C_E2E_DB: e2eDb,
                    BLP901C_E2E_ORDER_ID: String(orderId),
                },
            },
        ).trim(),
    );
}

function lockVersion(orderId: number): number {
    return Number(
        execFileSync(
            'php',
            [
                '-r',
                [
                    '$pdo = new PDO("sqlite:" . getenv("BLP901C_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901C_E2E_ORDER_ID");',
                    '$st = $pdo->prepare("select lock_version from dispo_orders where id=?");',
                    '$st->execute([$orderId]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901C_E2E_DB: e2eDb,
                    BLP901C_E2E_ORDER_ID: String(orderId),
                },
            },
        ).trim(),
    );
}

function activeUploadId(orderId: number, fieldKey: string): number {
    return Number(
        execFileSync(
            'php',
            [
                '-r',
                [
                    '$pdo = new PDO("sqlite:" . getenv("BLP901C_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901C_E2E_ORDER_ID");',
                    '$fieldKey = getenv("BLP901C_E2E_FIELD_KEY");',
                    '$st = $pdo->prepare("select id from dispo_order_uploads where dispo_order_id=? and field_key=? and archived_at is null order by id desc limit 1");',
                    '$st->execute([$orderId, $fieldKey]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901C_E2E_DB: e2eDb,
                    BLP901C_E2E_ORDER_ID: String(orderId),
                    BLP901C_E2E_FIELD_KEY: fieldKey,
                },
            },
        ).trim(),
    );
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

async function openOrder(page: Page, orderId: number) {
    await page.goto(`/dispoauftraege/${orderId}`);
    await expect(page).toHaveURL(new RegExp(`dispoauftraege/${orderId}`), {
        timeout: 15_000,
    });
}

async function uploadDynamicField(
    page: Page,
    fieldKey: string,
    filePath: string,
) {
    await page
        .locator(`[data-test="schema-file-input-${fieldKey}"]`)
        .setInputFiles(filePath);

    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().includes('/uploads/dynamisches-feld') &&
                response.request().method() === 'POST' &&
                response.ok(),
            { timeout: 30_000 },
        ),
        page.locator(`[data-test="schema-file-upload-${fieldKey}"]`).click(),
    ]);
}

test.describe.configure({ mode: 'serial' });

test.describe('BL-P9-01c Dynamische Datei-Felder', () => {
    test('A) Sales Draft: Upload, zentrale Liste Dynamisches Feld + Label', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const fieldKey = orders.field_key;

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draft.id);

        await expect(
            page.locator(`[data-test="schema-file-field-${fieldKey}"]`),
        ).toBeVisible();

        await uploadDynamicField(page, fieldKey, samplePdf);

        await expect(page.locator('[data-test="dispo-order-uploads"]')).toContainText(
            'Dynamisches Feld',
        );
        await expect(page.locator('[data-test="dispo-order-uploads"]')).toContainText(
            'E2E Anhang Dispo',
        );
        await expect(page.locator('[data-test="dispo-order-uploads"]')).toContainText(
            'customer-confirmation-sample.pdf',
        );
    });

    test('B) Ersetzen: zwei Einträge, einer archiviert', async ({ page }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const fieldKey = orders.field_key;
        const before = uploadRows(orders.draft.id);

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draft.id);

        await uploadDynamicField(page, fieldKey, samplePdf);

        expect(uploadRows(orders.draft.id)).toBe(before + 1);

        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('Archiviert');
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('Aktiv');
    });

    test('C) Admin archiviert aktiv; Feld leer; Liste zeigt archiviert', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const fieldKey = orders.field_key;
        const uploadId = activeUploadId(orders.draft.id, fieldKey);
        expect(uploadId).toBeGreaterThan(0);

        await login(page, 'admin@example.com');
        await openOrder(page, orders.draft.id);

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/archivieren') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
                { timeout: 30_000 },
            ),
            page
                .locator(
                    `[data-test="dispo-order-upload-${uploadId}"] [data-test="dispo-order-upload-archive"]`,
                )
                .click(),
        ]);

        await expect(
            page.locator(`[data-test="dispo-order-upload-status-${uploadId}"]`),
        ).toContainText('Archiviert');
        await expect(
            page.locator(`[data-test="schema-file-field-${fieldKey}"]`),
        ).toContainText('Noch keine Datei hinterlegt');
    });

    test('D) PM: kein Upload-Control', async ({ page, request }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const fieldKey = orders.field_key;

        await login(page, 'pm@example.com');
        await openOrder(page, orders.atDisposition.id);

        await expect(
            page.locator(`[data-test="schema-file-input-${fieldKey}"]`),
        ).toHaveCount(0);

        const cookies = await page.context().cookies();
        const cookieHeader = cookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

        const denied = await request.post(
            `/dispoauftraege/${orders.atDisposition.id}/uploads/dynamisches-feld`,
            {
                headers: {
                    Cookie: cookieHeader,
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                multipart: {
                    lock_version: String(lockVersion(orders.atDisposition.id)),
                    field_key: fieldKey,
                    file: {
                        name: 'blocked.pdf',
                        mimeType: 'application/pdf',
                        buffer: readFileSync(samplePdf),
                    },
                },
            },
        );
        expect(denied.status()).toBe(403);
    });
});
