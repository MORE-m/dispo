import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3-REST-B isolierte Suite: Options-Admin-UI.
 * Läuft nur über playwright.df3restb.config.ts (eigene DB, Port 8013).
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

async function csrfJson(
    page: Page,
    method: string,
    url: string,
    body: unknown,
) {
    return page.evaluate(
        async ({ method, url, body }) => {
            const token = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '',
            );
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
            const text = await response.text();
            let data: unknown = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch {
                data = text;
            }
            return { status: response.status, data };
        },
        { method, url, body },
    );
}

test.describe('DF-3-REST-B options admin', () => {
    test('select create, options CRUD, noop, 409, non-choice, pin', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto(
            '/administration/dynamische-felder/definitionen/neu',
        );
        await page.locator('#label').fill('REST-B Auswahl');
        await page.locator('[data-test="custom-field-type-select"]').selectOption(
            'select',
        );
        await expect(page.locator('#max_length')).toHaveCount(0);
        await page.locator('[data-test="custom-field-definition-submit"]').click();
        await page.waitForURL(/\/definitionen\/\d+$/);

        const section = page.locator(
            '[data-test="field-definition-options-section"]',
        );
        await expect(section).toBeVisible();
        await expect(
            page.locator('[data-test="field-definition-options-boundary-note"]'),
        ).toContainText('gepinnten');
        await expect(
            page.locator('[data-test="field-definition-active-badge"]'),
        ).toContainText('Feld');

        await page.locator('[data-test="field-definition-options-add"]').click();
        await page.locator('[data-test="field-definition-options-add"]').click();

        const firstLabel = page
            .locator('[data-test^="field-definition-option-label-"]')
            .first();
        const secondLabel = page
            .locator('[data-test^="field-definition-option-label-"]')
            .nth(1);
        await firstLabel.fill('Alpha Option');
        await secondLabel.fill('Beta Option');

        const firstKey = page
            .locator('[data-test^="field-definition-option-key-"]')
            .first();
        await expect(firstKey).toHaveValue(/alpha/);
        await firstKey.fill('alpha_manual');
        await firstLabel.fill('Alpha Option Neu');
        await expect(firstKey).toHaveValue('alpha_manual');

        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        const preview = page.locator(
            '[data-test="field-definition-options-preview"]',
        );
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
        await expect(
            page.locator('[data-test="field-definition-options-preview-changes"]'),
        ).toContainText('Neu');
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(page.getByText('Auswahloptionen übernommen.')).toBeVisible({
            timeout: 15_000,
        });

        await expect(page.getByText('(aktuell)')).toBeVisible();
        const alphaKey = page.locator(
            '[data-test="field-definition-option-key-alpha_manual"]',
        );
        await expect(alphaKey).toHaveAttribute('readonly', '');
        await expect(
            page.getByText('Schlüssel unveränderlich').first(),
        ).toBeVisible();

        await page
            .locator('[data-test="field-definition-option-label-alpha_manual"]')
            .fill('Alpha Geändert');
        await page
            .locator('[data-test="field-definition-option-sort-alpha_manual"]')
            .fill('30');
        await page
            .locator(
                '[data-test="field-definition-option-deactivate-beta_option"]',
            )
            .click()
            .catch(async () => {
                // Key may be beta_option from slug
            });

        const betaDeactivate = page.locator(
            '[data-test^="field-definition-option-deactivate-beta"]',
        );
        if ((await betaDeactivate.count()) > 0) {
            await betaDeactivate.first().click();
        }

        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await expect(preview).toBeVisible();
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(page.getByText('Auswahloptionen übernommen.')).toBeVisible({
            timeout: 15_000,
        });

        const betaReactivate = page.locator(
            '[data-test^="field-definition-option-reactivate-beta"]',
        );
        if ((await betaReactivate.count()) > 0) {
            await betaReactivate.first().click();
            await page
                .locator('[data-test="field-definition-options-preview-button"]')
                .click();
            await page
                .locator('[data-test="field-definition-options-preview-confirm"]')
                .click();
            await expect(
                page.getByText('Auswahloptionen übernommen.'),
            ).toBeVisible({ timeout: 15_000 });
        }

        await page.locator('[data-test="field-definition-options-add"]').click();
        const unsavedRemove = page
            .locator('[data-test^="field-definition-option-remove-"]')
            .last();
        await unsavedRemove.click();
        await expect(
            page.locator('[data-test^="field-definition-option-remove-"]'),
        ).toHaveCount(0);

        // Zero-active warning: deactivate all
        const deactivateButtons = page.locator(
            '[data-test^="field-definition-option-deactivate-"]',
        );
        const count = await deactivateButtons.count();
        for (let i = 0; i < count; i++) {
            const btn = page
                .locator('[data-test^="field-definition-option-deactivate-"]')
                .first();
            if ((await btn.count()) > 0) {
                await btn.click();
            }
        }
        await expect(
            page.locator(
                '[data-test="field-definition-options-zero-active-warning"]',
            ),
        ).toBeVisible();

        // Restore one active for further checks
        const reactivate = page
            .locator('[data-test^="field-definition-option-reactivate-"]')
            .first();
        await reactivate.click();
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(page.getByText('Auswahloptionen übernommen.')).toBeVisible({
            timeout: 15_000,
        });

        // No-op
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="field-definition-options-preview-changes"]'),
        ).toContainText('Keine Änderung');
        await page
            .locator('[data-test="field-definition-options-preview-noop"]')
            .click();
        await expect(page.getByText('Keine Änderungen')).toBeVisible({
            timeout: 15_000,
        });

        // Simulated concurrency 409
        const definitionUrl = page.url();
        const definitionId = definitionUrl.match(/definitionen\/(\d+)/)?.[1];
        expect(definitionId).toBeTruthy();
        const conflict = await csrfJson(
            page,
            'PUT',
            `/administration/dynamische-felder/definitionen/${definitionId}/optionen`,
            {
                lock_version: 999999,
                fingerprint: 'a'.repeat(64),
                options: [
                    {
                        key: 'alpha_manual',
                        label: 'Alpha Geändert',
                        sort: 30,
                        is_active: true,
                    },
                ],
            },
        );
        expect(conflict.status).toBe(409);
        expect(String((conflict.data as { message?: string }).message)).toMatch(
            /parallel|veraltet|neu laden/i,
        );

        // Invalid key German message via preview
        await page.locator('[data-test="field-definition-options-add"]').click();
        const newKey = page
            .locator('[data-test^="field-definition-option-key-"]')
            .last();
        const newLabel = page
            .locator('[data-test^="field-definition-option-label-"]')
            .last();
        await newLabel.fill('Bad');
        await newKey.fill('BAD');
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await expect(page.locator('text=Muster')).toBeVisible();
        await page
            .locator('[data-test^="field-definition-option-remove-"]')
            .last()
            .click();

        // Non-choice without options section
        await page.goto(
            '/administration/dynamische-felder/definitionen/neu',
        );
        await page.locator('#label').fill('REST-B Text');
        await page.locator('[data-test="custom-field-type-select"]').selectOption(
            'short_text',
        );
        await page.locator('[data-test="custom-field-definition-submit"]').click();
        await page.waitForURL(/\/definitionen\/\d+$/);
        await expect(
            page.locator('[data-test="field-definition-options-section"]'),
        ).toHaveCount(0);

        // Keyboard focus on options preview of select field
        await page.goto(definitionUrl);
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .focus();
        await expect(
            page.locator('[data-test="field-definition-options-preview-button"]'),
        ).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
    });
});
