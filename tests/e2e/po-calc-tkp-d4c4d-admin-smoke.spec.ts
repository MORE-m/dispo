import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * PO-CALC-TKP-1D4c-4d Browser-Smoke + Abnahme-Screenshots.
 * Nur über playwright.po-calc-tkp-d4c4d.config.ts (SQLite, Port 8077).
 */

const artifactDir = path.resolve(
    'docs/readiness/artifacts/po-calc-tkp-1d4c4d',
);

async function login(page: Page, email: string, password = 'password') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

async function shot(page: Page, name: string) {
    fs.mkdirSync(artifactDir, { recursive: true });
    const file = path.join(artifactDir, name);
    await page.screenshot({ path: file, fullPage: true });
    expect(fs.existsSync(file)).toBeTruthy();
    expect(fs.statSync(file).size).toBeGreaterThan(5_000);

    return file;
}

test.describe.serial('PO-CALC-TKP-1D4c-4d Admin UI Smoke', () => {
    test('Admin-Workflow, Screenshots, Management gesperrt', async ({
        page,
        browser,
    }) => {
        test.setTimeout(240_000);

        await login(page, 'admin@example.com');

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Online Audio' }),
        ).toBeVisible();
        await page.locator('a[href="/administration/online-audio"]').click();
        await expect(page.locator('[data-test="oa-admin-hub"]')).toBeVisible();
        await shot(page, '01-oa-admin-hub.png');

        await page.goto('/administration/online-audio/preislisten');
        await expect(
            page.locator('[data-test="oa-price-lists-index"]'),
        ).toBeVisible();
        await shot(page, '02-oa-price-lists-index.png');

        await page.locator('[data-test="oa-price-list-create-link"]').click();
        await expect(
            page.locator('[data-test="oa-price-list-create-form"]'),
        ).toBeVisible();
        await page.locator('#year').fill('2027');
        await page.locator('#name').fill('E2E D4c4d Draft 2027');
        await page.locator('[data-test="oa-fill-test-prices"]').check();
        await expect(
            page.locator('[data-test="oa-price-list-matrix"] input').first(),
        ).not.toHaveValue('');
        const matrixRows = page.locator(
            '[data-test="oa-price-list-matrix"] tbody tr',
        );
        // Gruppenzeilen + Preiszeilen; 41 Preispaare + 5 Gruppen ≈ ≥46
        await expect(matrixRows).toHaveCount(await matrixRows.count());
        expect(await matrixRows.count()).toBeGreaterThanOrEqual(46);
        await shot(page, '03-oa-price-matrix-create.png');

        await page.locator('[data-test="oa-price-list-create-submit"]').click();
        await expect(page.locator('[data-test="oa-price-list-show"]')).toBeVisible({
            timeout: 60_000,
        });
        await expect(
            page.locator('span').filter({ hasText: /^Entwurf$/ }),
        ).toBeVisible();

        // Ungültiger Mindest-TKP > Liste
        const firstMin = page
            .locator('[data-test="oa-price-list-matrix"] input')
            .nth(1);
        await firstMin.fill('999.0000');
        await expect(page.locator('[data-test="oa-unsaved-banner"]')).toBeVisible();
        await page.locator('[data-test="oa-price-list-save"]').click();
        await expect(
            page
                .getByText(/überschreit|ungültig|fehlgeschlagen|minimum_tkp/i)
                .first(),
        ).toBeVisible({ timeout: 20_000 });
        await shot(page, '06-oa-validation-error.png');

        // Feld wieder gültig setzen (Mindest ≤ Liste) und speichern
        const firstList = page
            .locator('[data-test="oa-price-list-matrix"] input')
            .nth(0);
        const listVal = await firstList.inputValue();
        const restoredMin = listVal.startsWith('80')
            ? '20.0000'
            : listVal.startsWith('44')
              ? '8.0000'
              : '14.0000';
        await firstMin.fill(restoredMin);
        await page.locator('[data-test="oa-price-list-save"]').click();
        await expect(
            page.getByText(/gespeichert|Entwurf gespeichert/i),
        ).toBeVisible({ timeout: 30_000 });

        await page.locator('[data-test="oa-price-list-validate"]').click();
        await expect(
            page.getByRole('heading', { name: 'Prüfergebnis' }),
        ).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('Vollständig: Ja · 41/41 Paare')).toBeVisible();
        await shot(page, '03b-oa-price-matrix-show.png');

        await page.locator('[data-test="oa-price-list-activate"]').click();
        await expect(
            page.locator('[data-test="oa-activate-confirm"]'),
        ).toBeVisible({ timeout: 30_000 });
        await shot(page, '07-oa-activate-confirm.png');
        await page.locator('[data-test="oa-activate-confirm-cancel"]').click();
        await expect(
            page.locator('[data-test="oa-activate-confirm"]'),
        ).toHaveCount(0);

        await page.locator('[data-test="oa-price-list-activate"]').click();
        await expect(
            page.locator('[data-test="oa-activate-confirm"]'),
        ).toBeVisible();
        await page.locator('[data-test="oa-activate-confirm-submit"]').click();
        await expect(
            page.locator('span').filter({ hasText: /^Aktiv$/ }),
        ).toBeVisible({ timeout: 30_000 });
        await expect(
            page.locator('[data-test="oa-price-list-matrix"] input'),
        ).toHaveCount(0);

        await page.goto('/administration/online-audio/targetings');
        await expect(
            page.locator('[data-test="oa-targetings-index"]'),
        ).toBeVisible();
        const dmpRow = page.getByRole('row', { name: /^dmp\b/i });
        await expect(dmpRow).toBeVisible();
        await expect(dmpRow).toContainText('25');
        await expect(dmpRow).toContainText('5');
        const geoRow = page.getByRole('row', { name: /^geo\b/i });
        await expect(geoRow).toBeVisible();
        await expect(geoRow).toContainText('15');
        await expect(geoRow).toContainText('3');
        await expect(geoRow).toContainText(/technisch/i);
        await shot(page, '04-oa-targetings.png');

        await page.goto('/administration/online-audio/plattformen');
        await expect(
            page.locator('[data-test="oa-platforms-index"]'),
        ).toBeVisible();
        await expect(
            page.getByRole('cell', { name: 'Spotify', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('cell', { name: 'YouTube', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('cell', { name: 'Deezer', exact: true }),
        ).toBeVisible();
        await shot(page, '05-oa-platforms.png');

        const mgmt = await browser.newContext();
        const mgmtPage = await mgmt.newPage();
        try {
            await login(mgmtPage, 'management@example.com');
            await mgmtPage.goto('/administration');
            await expect(
                mgmtPage.getByRole('heading', { name: 'Online Audio' }),
            ).toBeVisible();
            await expect(
                mgmtPage.locator('a[href="/administration/online-audio"]'),
            ).toHaveCount(0);
            const forbidden = await mgmtPage.goto(
                '/administration/online-audio',
                { waitUntil: 'domcontentloaded' },
            );
            // Laravel liefert 403; Playwright folgt ggf. Error-Page.
            expect(
                forbidden?.status() === 403 ||
                    (await mgmtPage.getByText(/403|Forbidden|nicht berechtigt/i).count()) >
                        0 ||
                    !mgmtPage.url().includes('/administration/online-audio'),
            ).toBeTruthy();
        } finally {
            await mgmt.close();
        }

        const files = fs
            .readdirSync(artifactDir)
            .filter((f) => f.endsWith('.png'));
        expect(files.length).toBeGreaterThanOrEqual(5);
    });
});
