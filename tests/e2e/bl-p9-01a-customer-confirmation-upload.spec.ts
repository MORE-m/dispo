import { execFileSync } from 'node:child_process';
import {
    closeSync,
    ftruncateSync,
    mkdirSync,
    openSync,
    readFileSync,
    rmSync,
    statSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import {
    CUSTOMER_CONFIRMATION_REASON,
    setCustomerConfirmationException,
} from './helpers/customer-confirmation';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(root, 'database/e2e-bl-p9-01a-orders.json');
const e2eDb = path.join(root, 'database/e2e-bl-p9-01a.sqlite');
const samplePdf = path.join(
    root,
    'tests/fixtures/customer-confirmation-sample.pdf',
);

/** Matches DispoOrderUploadService::MAX_BYTES (UPL-006). */
const MAX_BYTES = 50 * 1024 * 1024;

type OrderRef = { id: number; number: string };

type OrdersFixture = {
    draft: OrderRef;
    draftUpload: OrderRef;
    draftException: OrderRef;
    draftMaxBytes: OrderRef;
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function activeUploadSizeBytes(orderId: number): number {
    const raw = execFileSync(
        'php',
        [
            '-r',
            [
                '$pdo = new PDO("sqlite:" . getenv("BLP901A_E2E_DB"));',
                '$orderId = (int) getenv("BLP901A_E2E_ORDER_ID");',
                '$st = $pdo->prepare("select size_bytes from dispo_order_uploads where dispo_order_id=? and archived_at is null order by id desc limit 1");',
                '$st->execute([$orderId]);',
                'echo (string) $st->fetchColumn();',
            ].join(''),
        ],
        {
            encoding: 'utf8',
            env: {
                ...process.env,
                BLP901A_E2E_DB: e2eDb,
                BLP901A_E2E_ORDER_ID: String(orderId),
            },
        },
    ).trim();

    return Number(raw);
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

async function logout(page: Page) {
    await page.context().clearCookies();
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible({ timeout: 30_000 });
}

async function openOrder(page: Page, orderId: number) {
    await page.goto(`/dispoauftraege/${orderId}`);
    await expect(page).toHaveURL(new RegExp(`dispoauftraege/${orderId}`), {
        timeout: 15_000,
    });
    await expect(
        page.locator('[data-test="dispo-order-customer-confirmation"]'),
    ).toBeVisible({ timeout: 15_000 });
}

async function uploadCustomerConfirmation(page: Page) {
    await page
        .locator('[data-test="customer-confirmation-upload-input"]')
        .setInputFiles(samplePdf);
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().includes('/uploads/kundenbestaetigung') &&
                response.request().method() === 'POST' &&
                response.ok(),
            { timeout: 30_000 },
        ),
        page.locator('[data-test="customer-confirmation-upload-button"]').click(),
    ]);
    await expect(
        page.locator('[data-test="customer-confirmation-active-file"]'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        page.locator('[data-test="customer-confirmation-active-file"]'),
    ).toContainText('customer-confirmation-sample.pdf');
}

async function expectSubmitBlockedForConfirmation(page: Page) {
    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(
        page.locator('[data-test="dispo-order-submit-error"]'),
    ).toContainText(/Kundenbestätigung/i, { timeout: 15_000 });
    await page
        .locator('[data-test="dispo-order-submit-dialog"]')
        .getByRole('button', { name: 'Abbrechen' })
        .click();
}

test.describe.configure({ mode: 'serial' });

test.describe('BL-P9-01a Kundenbestätigungs-Upload', () => {
    test('E) Disposition ohne Upload-CTA', async ({ page }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'disposition@example.com');
        await openOrder(page, orders.draftUpload.id);
        await expect(
            page.locator('[data-test="customer-confirmation-upload-input"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="customer-confirmation-upload-button"]'),
        ).toHaveCount(0);
    });

    test('A+D+B+E) Upload, Archiv, Submit, Mitfreigabe; PM Download 403', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const orders = loadOrders();

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draftUpload.id);

        await expect(
            page.locator('[data-test="customer-confirmation-status"]'),
        ).toContainText('Noch nicht bestätigt');
        await expectSubmitBlockedForConfirmation(page);

        // A) Upload → aktive Datei + Uploads-Liste
        await uploadCustomerConfirmation(page);
        await expect(page.locator('[data-test="dispo-order-uploads"]')).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-uploads-empty"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="dispo-order-uploads"]')).toContainText(
            'Aktiv',
        );

        const downloadHref = await page
            .locator('[data-test="customer-confirmation-active-download"]')
            .getAttribute('href');
        expect(downloadHref).toBeTruthy();

        // E) PM darf Download nicht
        const detailUrl = page.url();
        await logout(page);
        await login(page, 'pm@example.com');
        const forbidden = await page.goto(downloadHref!);
        expect(forbidden?.status()).toBe(403);

        // D) Admin archiviert vor Submit
        await logout(page);
        await login(page, 'admin@example.com');
        await page.goto(detailUrl);
        await expect(
            page.locator('[data-test="dispo-order-upload-archive"]'),
        ).toBeVisible({ timeout: 15_000 });

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/archivieren') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
                { timeout: 15_000 },
            ),
            page.locator('[data-test="dispo-order-upload-archive"]').click(),
        ]);

        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('Archiviert', { timeout: 15_000 });
        await expect(
            page.locator('[data-test="customer-confirmation-active-file"]'),
        ).toHaveCount(0);

        const archivedHref = await page
            .locator('[data-test^="dispo-order-upload-download-"]')
            .first()
            .getAttribute('href');
        expect(archivedHref).toBeTruthy();
        const archivedDownload = await page.request.get(archivedHref!);
        expect(archivedDownload.status()).toBe(200);

        await logout(page);
        await login(page, 'sales@example.com');
        await page.goto(detailUrl);
        await expectSubmitBlockedForConfirmation(page);

        // A) erneut hochladen und einreichen
        await uploadCustomerConfirmation(page);

        await page.locator('[data-test="dispo-order-submit-open"]').click();
        await page.locator('[data-test="dispo-order-submit-confirm"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Wartet auf Vertriebsfreigabe',
            { timeout: 15_000 },
        );
        await expect(
            page.locator('[data-test="customer-confirmation-active-file"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="four-eyes-creator-hint"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-approve-open"]'),
        ).toHaveCount(0);

        // B) sales-b: Upload-Evidence, kein Ausnahme-Ack
        await logout(page);
        await login(page, 'sales-b@example.com');
        await page.goto(detailUrl);

        await page.locator('[data-test="dispo-order-approve-open"]').click();
        await expect(
            page.locator('[data-test="approval-customer-confirmation-upload"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="approval-customer-confirmation-upload"]'),
        ).toContainText('customer-confirmation-sample.pdf');
        await expect(
            page.locator('[data-test="customer-confirmation-exception-ack"]'),
        ).toHaveCount(0);
        await page.locator('[data-test="dispo-order-approve-confirm"]').click();

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Liegt bei Disposition',
            { timeout: 15_000 },
        );
        await expect(
            page.locator(
                '[data-test="approval-history-customer-confirmation-upload"]',
            ),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="approval-history-upload-filename"]'),
        ).toContainText('customer-confirmation-sample.pdf');
    });

    test('C) Ausnahmeweg am zweiten Draft weiterhin nutzbar', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const orders = loadOrders();

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draftException.id);

        await expectSubmitBlockedForConfirmation(page);
        await setCustomerConfirmationException(page);

        await page.locator('[data-test="dispo-order-submit-open"]').click();
        await page.locator('[data-test="dispo-order-submit-confirm"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Wartet auf Vertriebsfreigabe',
            { timeout: 15_000 },
        );
        await expect(
            page.locator('[data-test="customer-confirmation-readonly"]'),
        ).toBeVisible();
        await expect(
            page.locator(
                '[data-test="customer-confirmation-exception-reason-readonly"]',
            ),
        ).toContainText(CUSTOMER_CONFIRMATION_REASON);
    });

    test('UPL-006) echter multipart HTTP-Upload exakt 50 MB', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const orders = loadOrders();
        expect(orders.draftMaxBytes?.id).toBeTruthy();

        const tempDir = path.join(
            tmpdir(),
            `blp901a-50mb-${Date.now()}-${process.pid}`,
        );
        mkdirSync(tempDir, { recursive: true });
        const tempPath = path.join(tempDir, 'exact-50mb.bin');
        const fd = openSync(tempPath, 'w');
        try {
            ftruncateSync(fd, MAX_BYTES);
        } finally {
            closeSync(fd);
        }

        try {
            expect(statSync(tempPath).size).toBe(MAX_BYTES);

            await login(page, 'sales@example.com');
            await openOrder(page, orders.draftMaxBytes.id);

            // Real filesystem path → Playwright streams multipart (no 50MB Buffer).
            await page
                .locator('[data-test="customer-confirmation-upload-input"]')
                .setInputFiles(tempPath);

            const uploadResponsePromise = page.waitForResponse(
                (response) =>
                    response.url().includes('/uploads/kundenbestaetigung') &&
                    response.request().method() === 'POST',
                { timeout: 180_000 },
            );

            await page
                .locator('[data-test="customer-confirmation-upload-button"]')
                .click();

            const uploadResponse = await uploadResponsePromise;
            const bodyPreview = await uploadResponse.text().catch(() => '');
            expect(
                uploadResponse.status(),
                `HTTP upload failed: ${uploadResponse.status()} ${bodyPreview.slice(0, 500)}`,
            ).toBeGreaterThanOrEqual(200);
            expect(uploadResponse.status()).toBeLessThan(300);

            await expect(
                page.locator('[data-test="customer-confirmation-active-file"]'),
            ).toBeVisible({ timeout: 30_000 });
            await expect(
                page.locator('[data-test="customer-confirmation-active-file"]'),
            ).toContainText('exact-50mb.bin');
            await expect(
                page.locator('[data-test="customer-confirmation-active-file"]'),
            ).toContainText('50.0 MB');

            expect(activeUploadSizeBytes(orders.draftMaxBytes.id)).toBe(
                MAX_BYTES,
            );
        } finally {
            rmSync(tempDir, { recursive: true, force: true });
        }
    });
});
