import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ordersPath = path.join(root, 'database/e2e-bl-p9-01b-orders.json');
const e2eDb = path.join(root, 'database/e2e-bl-p9-01b.sqlite');
const samplePdf = path.join(
    root,
    'tests/fixtures/customer-confirmation-sample.pdf',
);
const sampleMp3 = path.join(root, 'tests/fixtures/audio-motif-sample.mp3');
const sampleWav = path.join(root, 'tests/fixtures/audio-motif-sample.wav');

type OrderRef = { id: number; number: string };

type OrdersFixture = {
    draft: OrderRef;
    materialMissing: OrderRef;
    audioMulti: OrderRef;
    playback: OrderRef;
    archive: OrderRef;
    disposed: OrderRef;
    reopenedInProgress: OrderRef;
};

function loadOrders(): OrdersFixture {
    return JSON.parse(readFileSync(ordersPath, 'utf8')) as OrdersFixture;
}

function orderStatus(orderId: number): string {
    return execFileSync(
        'php',
        [
            '-r',
            [
                '$pdo = new PDO("sqlite:" . getenv("BLP901B_E2E_DB"));',
                '$orderId = (int) getenv("BLP901B_E2E_ORDER_ID");',
                '$st = $pdo->prepare("select status from dispo_orders where id=?");',
                '$st->execute([$orderId]);',
                'echo (string) $st->fetchColumn();',
            ].join(''),
        ],
        {
            encoding: 'utf8',
            env: {
                ...process.env,
                BLP901B_E2E_DB: e2eDb,
                BLP901B_E2E_ORDER_ID: String(orderId),
            },
        },
    ).trim();
}

function uploadCount(orderId: number): number {
    return Number(
        execFileSync(
            'php',
            [
                '-r',
                [
                    '$pdo = new PDO("sqlite:" . getenv("BLP901B_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901B_E2E_ORDER_ID");',
                    '$st = $pdo->prepare("select count(*) from dispo_order_uploads where dispo_order_id=?");',
                    '$st->execute([$orderId]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901B_E2E_DB: e2eDb,
                    BLP901B_E2E_ORDER_ID: String(orderId),
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
                    '$pdo = new PDO("sqlite:" . getenv("BLP901B_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901B_E2E_ORDER_ID");',
                    '$st = $pdo->prepare("select lock_version from dispo_orders where id=?");',
                    '$st->execute([$orderId]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901B_E2E_DB: e2eDb,
                    BLP901B_E2E_ORDER_ID: String(orderId),
                },
            },
        ).trim(),
    );
}

function latestUploadId(orderId: number): number {
    return Number(
        execFileSync(
            'php',
            [
                '-r',
                [
                    '$pdo = new PDO("sqlite:" . getenv("BLP901B_E2E_DB"));',
                    '$orderId = (int) getenv("BLP901B_E2E_ORDER_ID");',
                    '$st = $pdo->prepare("select id from dispo_order_uploads where dispo_order_id=? order by id desc limit 1");',
                    '$st->execute([$orderId]);',
                    'echo (string) $st->fetchColumn();',
                ].join(''),
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    BLP901B_E2E_DB: e2eDb,
                    BLP901B_E2E_ORDER_ID: String(orderId),
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
}

async function uploadMaterial(
    page: Page,
    category: string,
    files: string | string[],
) {
    await page
        .locator('[data-test="dispo-order-material-upload-category"]')
        .selectOption(category);
    await page
        .locator('[data-test="dispo-order-material-upload-file"]')
        .setInputFiles(files);

    const fileCount = Array.isArray(files) ? files.length : 1;
    const responsePromise = page.waitForResponse(
        (response) =>
            /\/dispoauftraege\/\d+\/uploads$/.test(
                new URL(response.url()).pathname,
            ) &&
            response.request().method() === 'POST' &&
            response.ok(),
        { timeout: 30_000 },
    );

    await page.locator('[data-test="dispo-order-material-upload-submit"]').click();
    await responsePromise;

    if (fileCount > 1) {
        await expect(
            page.locator('[data-test^="dispo-order-upload-audio-"]'),
        ).toHaveCount(fileCount, { timeout: 30_000 });
    } else {
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).not.toContainText('Noch keine Dateien', { timeout: 30_000 });
    }
}

test.describe.configure({ mode: 'serial' });

test.describe('BL-P9-01b Material- und Audio-Uploads', () => {
    test('A) Sales Draft: Briefing hochladen, Liste, Download, kein Statuswechsel', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const beforeStatus = orderStatus(orders.draft.id);

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draft.id);
        await expect(
            page.locator('[data-test="dispo-order-material-upload"]'),
        ).toBeVisible();

        const category = page.locator(
            '[data-test="dispo-order-material-upload-category"]',
        );
        const options = await category.locator('option').allTextContents();
        expect(options.join(' ')).not.toMatch(/Kundenbestätigung/i);

        await uploadMaterial(page, 'briefing', samplePdf);
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('Briefing');
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('customer-confirmation-sample.pdf');
        await expect(
            page.locator('[data-test^="dispo-order-upload-download-"]').first(),
        ).toBeVisible();

        expect(orderStatus(orders.draft.id)).toBe(beforeStatus);
    });

    test('B) Disposition material_missing: Audio, Status unverändert, Playback', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        expect(orderStatus(orders.materialMissing.id)).toBe('material_missing');

        await login(page, 'disposition@example.com');
        await openOrder(page, orders.materialMissing.id);
        await expect(
            page.locator('[data-test="dispo-order-material-upload"]'),
        ).toBeVisible();

        await uploadMaterial(page, 'audio_motif', sampleMp3);
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).toContainText('Audio-Motiv');
        await expect(
            page.locator('[data-test^="dispo-order-upload-audio-"]').first(),
        ).toBeVisible();

        expect(orderStatus(orders.materialMissing.id)).toBe('material_missing');
    });

    test('C) Multi Audio MP3+WAV ohne Motivname/Primary', async ({ page }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'disposition@example.com');
        await openOrder(page, orders.audioMulti.id);

        await uploadMaterial(page, 'audio_motif', [sampleMp3, sampleWav]);
        await expect(
            page.locator('[data-test^="dispo-order-upload-audio-"]'),
        ).toHaveCount(2);
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).not.toContainText('Motivname');
        await expect(
            page.locator('[data-test="dispo-order-uploads"]'),
        ).not.toContainText('aktive Audio');
    });

    test('D) Fake MP3 wird abgelehnt ohne Upload-Zeile', async ({ page }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();
        const before = uploadCount(orders.draft.id);

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draft.id);

        await page
            .locator('[data-test="dispo-order-material-upload-category"]')
            .selectOption('audio_motif');
        await page
            .locator('[data-test="dispo-order-material-upload-file"]')
            .setInputFiles({
                name: 'spoof.mp3',
                mimeType: 'audio/mpeg',
                buffer: Buffer.from('%PDF-1.4\nfake\n'),
            });

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    /\/dispoauftraege\/\d+\/uploads$/.test(
                        new URL(response.url()).pathname,
                    ) &&
                    response.request().method() === 'POST' &&
                    response.status() === 422,
                { timeout: 30_000 },
            ),
            page
                .locator('[data-test="dispo-order-material-upload-submit"]')
                .click(),
        ]);

        await expect(
            page.locator('[data-test="dispo-order-material-upload-error"]'),
        ).toBeVisible();
        expect(uploadCount(orders.draft.id)).toBe(before);
    });

    test('E) Playback Auth + Range 206; PM 403; Fremdorder 404', async ({
        page,
        request,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'disposition@example.com');
        await openOrder(page, orders.playback.id);
        await uploadMaterial(page, 'audio_motif', sampleMp3);
        const uploadId = latestUploadId(orders.playback.id);

        const cookies = await page.context().cookies();
        const cookieHeader = cookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');

        const streamOk = await request.get(
            `/dispoauftraege/${orders.playback.id}/uploads/${uploadId}/stream`,
            { headers: { Cookie: cookieHeader } },
        );
        expect([200, 206]).toContain(streamOk.status());

        const range = await request.get(
            `/dispoauftraege/${orders.playback.id}/uploads/${uploadId}/stream`,
            {
                headers: {
                    Cookie: cookieHeader,
                    Range: 'bytes=0-10',
                },
            },
        );
        expect(range.status()).toBe(206);
        expect(range.headers()['content-range'] ?? '').toMatch(/^bytes 0-10\//);

        await logout(page);
        await login(page, 'pm@example.com');
        const pmCookies = await page.context().cookies();
        const pmHeader = pmCookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const pmDenied = await request.get(
            `/dispoauftraege/${orders.playback.id}/uploads/${uploadId}/stream`,
            { headers: { Cookie: pmHeader } },
        );
        expect(pmDenied.status()).toBe(403);

        await logout(page);
        await login(page, 'sales@example.com');
        const salesCookies = await page.context().cookies();
        const salesHeader = salesCookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const wrongOrder = await request.get(
            `/dispoauftraege/${orders.draft.id}/uploads/${uploadId}/stream`,
            { headers: { Cookie: salesHeader } },
        );
        expect(wrongOrder.status()).toBe(404);
    });

    test('F) Admin archiviert; Sales/Disposition ohne Archiv-Button', async ({
        page,
        request,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'disposition@example.com');
        await openOrder(page, orders.archive.id);
        await uploadMaterial(page, 'audio_motif', sampleMp3);
        await expect(
            page.locator('[data-test="dispo-order-upload-archive"]'),
        ).toHaveCount(0);

        const uploadId = latestUploadId(orders.archive.id);
        const lock = lockVersion(orders.archive.id);
        const cookies = await page.context().cookies();
        const cookieHeader = cookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
        const denied = await request.post(
            `/dispoauftraege/${orders.archive.id}/uploads/${uploadId}/archivieren`,
            {
                headers: {
                    Cookie: cookieHeader,
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf
                        ? decodeURIComponent(xsrf.value)
                        : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                multipart: { lock_version: String(lock) },
            },
        );
        expect(denied.status()).toBe(403);

        await logout(page);
        await login(page, 'admin@example.com');
        await openOrder(page, orders.archive.id);
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/archivieren') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
                { timeout: 30_000 },
            ),
            page.locator('[data-test="dispo-order-upload-archive"]').click(),
        ]);
        await expect(
            page.locator(`[data-test="dispo-order-upload-status-${uploadId}"]`),
        ).toContainText('Archiviert');
        await expect(
            page.locator(`[data-test="dispo-order-upload-download-${uploadId}"]`),
        ).toBeVisible();
    });

    test('G) Terminal Status ohne Upload-CTA; Reopen-In-Progress wieder erlaubt', async ({
        page,
        request,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'sales@example.com');
        await openOrder(page, orders.disposed.id);
        await expect(
            page.locator('[data-test="dispo-order-material-upload"]'),
        ).toHaveCount(0);

        const lock = lockVersion(orders.disposed.id);
        const cookies = await page.context().cookies();
        const cookieHeader = cookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
        const blocked = await request.post(
            `/dispoauftraege/${orders.disposed.id}/uploads`,
            {
                headers: {
                    Cookie: cookieHeader,
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf
                        ? decodeURIComponent(xsrf.value)
                        : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                multipart: {
                    lock_version: String(lock),
                    category: 'briefing',
                    file: {
                        name: 'x.pdf',
                        mimeType: 'application/pdf',
                        buffer: readFileSync(samplePdf),
                    },
                },
            },
        );
        expect([403, 422]).toContain(blocked.status());

        await openOrder(page, orders.reopenedInProgress.id);
        await expect(
            page.locator('[data-test="dispo-order-material-upload"]'),
        ).toBeVisible();
    });

    test('H) CC-Regression: spezielle UI bleibt, Generic blockiert CC', async ({
        page,
        request,
    }) => {
        test.setTimeout(120_000);
        const orders = loadOrders();

        await login(page, 'sales@example.com');
        await openOrder(page, orders.draft.id);
        await expect(
            page.locator('[data-test="dispo-order-customer-confirmation"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-material-upload-category"]'),
        ).not.toContainText('Kundenbestätigung');

        const lock = lockVersion(orders.draft.id);
        const cookies = await page.context().cookies();
        const cookieHeader = cookies
            .map((cookie) => `${cookie.name}=${cookie.value}`)
            .join('; ');
        const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
        const blocked = await request.post(
            `/dispoauftraege/${orders.draft.id}/uploads`,
            {
                headers: {
                    Cookie: cookieHeader,
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf
                        ? decodeURIComponent(xsrf.value)
                        : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                multipart: {
                    lock_version: String(lock),
                    category: 'customer_confirmation',
                    file: {
                        name: 'cc.pdf',
                        mimeType: 'application/pdf',
                        buffer: readFileSync(samplePdf),
                    },
                },
            },
        );
        expect(blocked.status()).toBe(422);
    });
});
