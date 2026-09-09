import {
    expect,
    test,
    type Page,
} from '@playwright/test';

/**
 * ADV-001b isolierte Suite: Katalog-Admin Oberkategorien/Werbemittel.
 * Läuft nur über playwright.adv001b.config.ts (eigene DB, Port 8007).
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

test.describe.serial('ADV-001b Katalog-Admin', () => {
    test('Admin öffnet Katalog-Hub und legt Oberkategorie sowie Spot-Werbemittel an', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration');
        await expect(
            page.getByRole('heading', { name: 'Administration' }),
        ).toBeVisible();
        await page.getByRole('link', { name: 'Öffnen' }).nth(1).click();
        // Hub has multiple Öffnen - go directly
        await page.goto('/administration/katalog');
        await expect(
            page.locator('[data-test="catalog-boundary-note"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="catalog-tile-categories"]'),
        ).toBeVisible();

        await page.goto('/administration/katalog/oberkategorien/neu');
        await page
            .locator('[data-test="category-name-input"]')
            .fill(`E2E Kategorie ${suffix}`);
        await page
            .locator('[data-test="category-key-input"]')
            .fill(`e2e_cat_${suffix}`);
        await page.locator('[data-test="category-sort-input"]').fill('300');
        await page.locator('[data-test="category-create-submit"]').click();
        await expect(page).toHaveURL(/oberkategorien\/\d+$/, {
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-test="category-key-readonly"]'),
        ).toHaveValue(`e2e_cat_${suffix}`);

        await page.goto('/administration/katalog/werbemittel/neu');
        await expect(page.locator('[data-test="medium-kind-note"]')).toBeVisible();
        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`E2E Spot ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`e2e_spot_${suffix}`);
        await page
            .locator('[data-test="medium-category-select"]')
            .selectOption({ label: 'Spots (spots)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="medium-code-readonly"]'),
        ).toHaveValue(`e2e_spot_${suffix}`);
    });

    test('Medium bearbeiten, Deaktivieren und Kategorie-Blocker', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration/katalog/werbemittel/neu');
        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`Lifecycle Spot ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`life_spot_${suffix}`);
        await page
            .locator('[data-test="medium-category-select"]')
            .selectOption({ label: 'Spots (spots)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });

        const mediumUrl = page.url();
        const mediumId = mediumUrl.match(/werbemittel\/(\d+)/)?.[1];
        expect(mediumId).toBeTruthy();

        await page.locator('[data-test="medium-name-input"]').fill(
            `Lifecycle Spot ${suffix} Edit`,
        );
        await page.locator('[data-test="medium-save-button"]').click();
        await expect(
            page.locator('[data-test="medium-show-success"]'),
        ).toBeVisible({ timeout: 15_000 });

        await page
            .locator('[data-test="medium-deactivate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="medium-deactivate-preview"]'),
        ).toBeVisible();
        await page.locator('[data-test="medium-deactivate-confirm"]').click();
        await expect(page.locator('[data-test="medium-status-badge"]')).toHaveText(
            'Inaktiv',
            { timeout: 15_000 },
        );

        // Spots-Kategorie mit aktivem Seed-Medium spot_classic darf nicht deaktiviert werden
        await page.goto('/administration/katalog/oberkategorien');
        const spotsLink = page.locator('a').filter({ hasText: 'Spots' }).first();
        await spotsLink.click();
        await expect(page).toHaveURL(/oberkategorien\/\d+$/);
        await page
            .locator('[data-test="category-deactivate-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="category-deactivate-blocker"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="category-deactivate-confirm"]'),
        ).toBeDisabled();

        await page.goto(mediumUrl);
        await page.locator('[data-test="medium-reactivate-button"]').click();
        await expect(page.locator('[data-test="medium-status-badge"]')).toHaveText(
            'Aktiv',
            { timeout: 15_000 },
        );

        // Kategoriewechsel auf inkompatible Kategorie → Blocker
        const onlineOption = page
            .locator('[data-test="medium-target-category-select"] option')
            .filter({ hasText: /Online Audio/ });
        const onlineValue = await onlineOption.first().getAttribute('value');
        expect(onlineValue).toBeTruthy();
        await page
            .locator('[data-test="medium-target-category-select"]')
            .selectOption(onlineValue!);
        await page
            .locator('[data-test="medium-category-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="medium-category-blocker"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-category-confirm"]'),
        ).toBeDisabled();

        // 409 nach paralleler Änderung sichtbar in der UI
        const lockText = await page
            .locator('text=Sperrversion')
            .first()
            .textContent();
        const lockMatch = lockText?.match(/Sperrversion\s+(\d+)/);
        const lockVersion = Number(lockMatch?.[1] ?? 1);

        await putJson(page, `/administration/katalog/werbemittel/${mediumId}`, {
            name: `Lifecycle Spot ${suffix} Parallel`,
            default_length_seconds: 30,
            is_discountable: true,
            is_ae_eligible: true,
            sort: 0,
            lock_version: lockVersion,
        });

        await page.locator('[data-test="medium-name-input"]').fill(
            `Lifecycle Spot ${suffix} Stale`,
        );
        await page.locator('[data-test="medium-save-button"]').click();
        await expect(page.locator('[data-test="medium-show-error"]')).toContainText(
            /parallel|veraltet|neu laden/i,
            { timeout: 15_000 },
        );
    });

    test('Nicht-Admin erhält 403', async ({ page }) => {
        await login(page, 'sales@example.com');
        const response = await page.goto('/administration/katalog');
        expect(response?.status()).toBe(403);
    });
});
