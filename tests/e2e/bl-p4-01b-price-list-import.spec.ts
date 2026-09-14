import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P4-01b isolierte Suite: Excel-Import.
 * Nur über playwright.blp401b.config.ts (Port 8018, eigene DB).
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

function makeXlsx(mode: 'valid' | 'invalid' | 'sparse'): string {
    const target = path.join(
        os.tmpdir(),
        `bl-p4-01b-${mode}-${Date.now()}.xlsx`,
    );
    execFileSync(
        'php',
        [
            path.resolve('tests/e2e/helpers/write-price-list-xlsx.php'),
            target,
            mode,
        ],
        { cwd: process.cwd() },
    );
    expect(fs.existsSync(target)).toBeTruthy();

    return target;
}

test.describe.serial('BL-P4-01b Preislisten-Import', () => {
    test('gültige XLSX → Preview → Confirm → Draft', async ({ page }) => {
        await login(page, 'admin@example.com');
        const file = makeXlsx('valid');

        await page.goto('/administration/preislisten/import');
        await expect(
            page.getByRole('heading', { name: 'Excel-Import' }),
        ).toBeVisible();
        await page.locator('[data-test="price-list-import-file"]').setInputFiles(file);
        await page.locator('[data-test="price-list-import-validate"]').click();
        await expect(
            page.locator('[data-test="price-list-import-preview"]'),
        ).toBeVisible({ timeout: 30_000 });
        await expect(
            page.locator('[data-test="price-list-import-confirm"]'),
        ).toBeEnabled();
        await page.locator('[data-test="price-list-import-confirm"]').click();
        await expect(
            page.locator('[data-test="price-list-import-result"]'),
        ).toBeVisible({ timeout: 30_000 });
        await page
            .locator('[data-test="price-list-import-result"] a')
            .first()
            .click();
        await expect(page.locator('[data-test="price-list-status"]')).toContainText(
            'Entwurf',
        );
    });

    test('fehlerhafte XLSX blockiert Confirm', async ({ page }) => {
        await login(page, 'admin@example.com');
        const file = makeXlsx('invalid');
        await page.goto('/administration/preislisten/import');
        await page.locator('[data-test="price-list-import-file"]').setInputFiles(file);
        await page.locator('[data-test="price-list-import-validate"]').click();
        await expect(
            page.locator('[data-test="price-list-import-errors"]'),
        ).toBeVisible({ timeout: 30_000 });
        await expect(
            page.locator('[data-test="price-list-import-confirm"]'),
        ).toBeDisabled();
    });

    test('sparse Stunden bleiben sparse und Doppel-Confirm erzeugt keinen zweiten Draft', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const file = makeXlsx('sparse');
        await page.goto('/administration/preislisten/import');
        await page.locator('[data-test="price-list-import-file"]').setInputFiles(file);
        await page.locator('[data-test="price-list-import-validate"]').click();
        await expect(
            page.locator('[data-test="price-list-import-preview"]'),
        ).toContainText(/gültige Zeilen 1/i, { timeout: 30_000 });
        await page.locator('[data-test="price-list-import-confirm"]').click();
        await expect(
            page.locator('[data-test="price-list-import-result"]'),
        ).toBeVisible({ timeout: 30_000 });
        const links = page.locator('[data-test="price-list-import-result"] a');
        await expect(links).toHaveCount(1);
        await page.locator('[data-test="price-list-import-confirm"]').click();
        await expect(
            page.locator('[data-test="price-list-import-error"]'),
        ).toBeVisible();
        await expect(links).toHaveCount(1);
    });
});
