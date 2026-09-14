import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P4-01a isolierte Suite: Preislisten-Admin-Lifecycle.
 * Läuft nur über playwright.blp401a.config.ts (eigene DB, Port 8017).
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

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const token = await page.evaluate(() => {
        const row = document.cookie
            .split('; ')
            .find((part) => part.startsWith('XSRF-TOKEN='));
        return row ? decodeURIComponent(row.slice(11)) : '';
    });

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token,
    };
}

async function putJson(
    page: Page,
    url: string,
    body: Record<string, unknown> = {},
) {
    return page.request.put(url, {
        headers: await csrfHeaders(page),
        data: body,
    });
}

test.describe.serial('BL-P4-01a Preislisten-Admin', () => {
    test('Admin legt Entwurf an, prüft, veröffentlicht und erkennt veraltete lock_version', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await page.goto('/administration/preislisten');
        await expect(
            page.getByRole('heading', { name: 'Preislisten' }),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="price-list-index-table"]'),
        ).toBeVisible();
        await expect(
            page
                .locator('[data-test="price-list-index-table"]')
                .getByRole('cell', { name: 'Sender' })
                .first(),
        ).toBeVisible();

        await page.locator('[data-test="price-list-create-link"]').click();
        await page
            .locator('[data-test="price-list-inventory-input"]')
            .selectOption({ label: 'Radio Hamburg (Sender)' });
        await page
            .locator('[data-test="price-list-name-input"]')
            .fill(`E2E Preisliste ${suffix}`);
        await page.locator('[data-test="price-list-create-submit"]').click();
        await expect(page).toHaveURL(/\/administration\/preislisten\/\d+$/);
        await expect(
            page.locator('[data-test="price-list-status"]'),
        ).toContainText('Entwurf');

        await page.locator('[data-test="price-cell-8-mo_fr"]').fill('1,2500');
        await page.locator('[data-test="price-cell-8-sa"]').fill('1,5000');
        await page.locator('[data-test="price-cell-8-so"]').fill('0,8000');
        await page.locator('[data-test="price-list-inspect-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-success"]'),
        ).toBeVisible();
        await page.locator('[data-test="price-list-save-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-success"]'),
        ).toContainText(/Gespeichert|Entwurf/);

        await page
            .locator('[data-test="price-list-activate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="price-list-impact-preview"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="price-list-activate-confirm"]')
            .click();
        await expect(page.locator('[data-test="price-list-status"]')).toContainText(
            'Aktiv',
        );

        const listUrl = page.url();
        const listId = listUrl.match(/preislisten\/(\d+)/)?.[1];
        expect(listId).toBeTruthy();
        const lockText = await page
            .locator('[data-test="price-list-status"]')
            .textContent();
        const lockVersion = Number(
            lockText?.match(/Sperrversion\s+(\d+)/)?.[1] ?? 1,
        );

        const activeUpdate = await putJson(
            page,
            `/administration/preislisten/${listId}`,
            {
                name: 'Darf nicht überschrieben werden',
                items: [
                    { hour: 8, day_group: 'mo_fr', second_price: '9.0000' },
                    { hour: 8, day_group: 'sa', second_price: '9.0000' },
                    { hour: 8, day_group: 'so', second_price: '9.0000' },
                ],
                lock_version: lockVersion,
            },
        );
        expect(activeUpdate.status()).toBe(422);

        await page.locator('[data-test="price-list-copy-link"]').click();
        await expect(
            page.locator('[data-test="price-list-copy-source"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="price-list-name-input"]')
            .fill(`E2E Kopie ${suffix}`);
        await page.locator('[data-test="price-list-create-submit"]').click();
        await expect(page).toHaveURL(/\/administration\/preislisten\/\d+$/);
        await expect(
            page.locator('[data-test="price-list-status"]'),
        ).toContainText('Entwurf');

        const copyUrl = page.url();
        const copyId = copyUrl.match(/preislisten\/(\d+)/)?.[1];
        expect(copyId).toBeTruthy();
        const copyLockText = await page
            .locator('[data-test="price-list-status"]')
            .textContent();
        const copyLock = Number(
            copyLockText?.match(/Sperrversion\s+(\d+)/)?.[1] ?? 1,
        );

        await putJson(page, `/administration/preislisten/${copyId}`, {
            name: `E2E Kopie ${suffix} parallel`,
            items: [
                { hour: 8, day_group: 'mo_fr', second_price: '1.2500' },
                { hour: 8, day_group: 'sa', second_price: '1.5000' },
                { hour: 8, day_group: 'so', second_price: '0.8000' },
            ],
            lock_version: copyLock,
        });

        await page
            .locator('[data-test="price-list-name-input"]')
            .fill(`E2E Kopie ${suffix} stale`);
        await page.locator('[data-test="price-list-save-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-error"]'),
        ).toContainText(/parallel|veraltet|neu laden/i, { timeout: 15_000 });
    });

    test('Preview ändert Formular-Sperrversion nicht und blockiert ungespeicherte Preise', async ({
        page,
        browser,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration/preislisten/neu');
        await page
            .locator('[data-test="price-list-inventory-input"]')
            .selectOption({ label: 'Radio Hamburg (Sender)' });
        await page
            .locator('[data-test="price-list-name-input"]')
            .fill(`E2E Lock ${suffix}`);
        await page.locator('[data-test="price-list-create-submit"]').click();
        await expect(page).toHaveURL(/\/administration\/preislisten\/\d+$/);

        await page.locator('[data-test="price-cell-8-mo_fr"]').fill('1,2500');
        await page.locator('[data-test="price-cell-8-sa"]').fill('1,5000');
        await page.locator('[data-test="price-cell-8-so"]').fill('0,8000');
        await page.locator('[data-test="price-list-save-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-success"]'),
        ).toBeVisible();

        const lockBefore = await page
            .locator('[data-test="price-list-status"]')
            .textContent();
        const formLock = Number(
            lockBefore?.match(/Sperrversion\s+(\d+)/)?.[1] ?? 0,
        );
        expect(formLock).toBeGreaterThan(0);
        const listUrl = page.url();
        const listId = listUrl.match(/preislisten\/(\d+)/)?.[1];
        expect(listId).toBeTruthy();

        const other = await browser.newContext();
        const pageB = await other.newPage();
        try {
            await login(pageB, 'admin@example.com');
            await pageB.goto(listUrl);
            await pageB
                .locator('[data-test="price-cell-8-sa"]')
                .fill('1,9000');
            await pageB
                .locator('[data-test="price-list-save-button"]')
                .click();
            await expect(
                pageB.locator('[data-test="price-list-show-success"]'),
            ).toBeVisible();

            await page
                .locator('[data-test="price-list-activate-preview-button"]')
                .click();
            await expect(
                page.locator('[data-test="price-list-impact-preview"]'),
            ).toBeVisible();
            await expect(
                page.locator('[data-test="price-list-status"]'),
            ).toContainText(`Sperrversion ${formLock}`);

            await page.locator('[data-test="price-list-save-button"]').click();
            await expect(
                page.locator('[data-test="price-list-show-error"]'),
            ).toContainText(/parallel|veraltet|neu laden/i, { timeout: 15_000 });
        } finally {
            await other.close();
        }

        await page.reload();
        await page.locator('[data-test="price-cell-8-mo_fr"]').fill('2,0000');
        await page
            .locator('[data-test="price-list-activate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="price-list-show-error"]'),
        ).toContainText(/speichern/i);
        await expect(
            page.locator('[data-test="price-list-impact-preview"]'),
        ).toHaveCount(0);

        await page.locator('[data-test="price-cell-8-mo_fr"]').fill('1,2500');
        await page.locator('[data-test="price-cell-8-sa"]').fill('1,9000');
        await page.locator('[data-test="price-cell-8-so"]').fill('0,8000');
        await page.locator('[data-test="price-list-save-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-success"]'),
        ).toContainText(/Gespeichert|Entwurf/);

        const lockAfterSave = Number(
            (
                await page.locator('[data-test="price-list-status"]').textContent()
            )?.match(/Sperrversion\s+(\d+)/)?.[1] ?? 0,
        );
        expect(lockAfterSave).toBeGreaterThan(formLock);

        await page
            .locator('[data-test="price-list-activate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="price-list-impact-preview"]'),
        ).toBeVisible();

        await page.locator('[data-test="price-cell-8-so"]').fill('0,9000');
        await expect(
            page.locator('[data-test="price-list-impact-preview"]'),
        ).toHaveCount(0);

        await page.locator('[data-test="price-list-save-button"]').click();
        await expect(
            page.locator('[data-test="price-list-show-success"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="price-list-activate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="price-list-impact-preview"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="price-list-activate-confirm"]')
            .click();
        await expect(page.locator('[data-test="price-list-status"]')).toContainText(
            'Aktiv',
        );
        await expect(page.locator('[data-test="price-cell-8-sa"]')).toHaveValue(
            /1[,.]9000/,
        );
        await expect(page.locator('[data-test="price-cell-8-so"]')).toHaveValue(
            /0[,.]9000/,
        );
        await expect(page.locator('[data-test="price-cell-8-mo_fr"]')).toHaveValue(
            /1[,.]2500/,
        );
    });

    test('historische Kalkulation bleibt nach neuer Aktivierung sichtbar', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto('/kalkulationen');
        await page.getByRole('link', { name: /K-/ }).first().click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/);
        await expect(
            page.locator('[data-test="calculation-summary"]'),
        ).toContainText(/e2e-RH|Radio Hamburg/);
    });
});
