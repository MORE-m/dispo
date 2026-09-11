import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3-REST-B isolierte Suite: Options-Admin-UI.
 * Läuft nur über playwright.df3restb.config.ts (eigene DB, Port 8013).
 *
 * Serial: gemeinsames Choice-Feld aus dem ersten Test; unabhängige Diagnose
 * je Szenario ohne einen monolithischen Sammeltest.
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

test.describe.configure({ mode: 'serial' });

test.describe('DF-3-REST-B options admin', () => {
    let choiceUrl = '';
    let choiceId = '';

    test('01 create select field and open options section', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto(
            '/administration/dynamische-felder/definitionen/neu',
        );
        await page.locator('#label').fill('REST-B Auswahl');
        await page
            .locator('[data-test="custom-field-type-select"]')
            .selectOption('select');
        await expect(page.locator('#max_length')).toHaveCount(0);
        await page
            .locator('[data-test="custom-field-definition-submit"]')
            .click();
        await page.waitForURL(/\/definitionen\/\d+$/);
        choiceUrl = page.url();
        choiceId = choiceUrl.match(/definitionen\/(\d+)/)?.[1] ?? '';
        expect(choiceId).toBeTruthy();

        await expect(
            page.locator('[data-test="field-definition-options-section"]'),
        ).toBeVisible();
        await expect(
            page.locator(
                '[data-test="field-definition-options-boundary-note"]',
            ),
        ).toContainText('gepinnten');
        await expect(
            page.locator('[data-test="field-definition-active-badge"]'),
        ).toContainText('Feld');
    });

    test('02 add options, preview and apply', async ({ page }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);

        await page.locator('[data-test="field-definition-options-add"]').click();
        await page.locator('[data-test="field-definition-options-add"]').click();

        const labels = page.locator(
            '[data-test^="field-definition-option-label-"]',
        );
        await labels.nth(0).fill('Alpha Option');
        await labels.nth(1).fill('Beta Option');

        const firstKey = page
            .locator('[data-test^="field-definition-option-key-"]')
            .first();
        await expect(firstKey).toHaveValue(/alpha/);
        await firstKey.fill('alpha_manual');
        await labels.nth(0).fill('Alpha Option Neu');
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
            page.locator(
                '[data-test="field-definition-options-preview-changes"]',
            ),
        ).toContainText('Neu');
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Auswahloptionen übernommen.'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('(aktuell)')).toBeVisible();
    });

    test('03 persisted key is read-only', async ({ page }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);
        const alphaKey = page.locator(
            '[data-test="field-definition-option-key-alpha_manual"]',
        );
        await expect(alphaKey).toHaveAttribute('readonly', '');
        await expect(
            page.getByText('Schlüssel unveränderlich').first(),
        ).toBeVisible();
    });

    test('04 change label/sort and deactivate/reactivate', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);

        await page
            .locator(
                '[data-test="field-definition-option-label-alpha_manual"]',
            )
            .fill('Alpha Geändert');
        await page
            .locator('[data-test="field-definition-option-sort-alpha_manual"]')
            .fill('30');

        const betaDeactivate = page.locator(
            '[data-test^="field-definition-option-deactivate-beta"]',
        );
        await expect(betaDeactivate.first()).toBeVisible();
        await betaDeactivate.first().click();

        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Auswahloptionen übernommen.'),
        ).toBeVisible({ timeout: 15_000 });

        const betaReactivate = page.locator(
            '[data-test^="field-definition-option-reactivate-beta"]',
        );
        await expect(betaReactivate.first()).toBeVisible();
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
    });

    test('05 remove unsaved draft row', async ({ page }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);
        await page.locator('[data-test="field-definition-options-add"]').click();
        const remove = page
            .locator('[data-test^="field-definition-option-remove-"]')
            .last();
        await expect(remove).toBeVisible();
        await remove.click();
        await expect(
            page.locator('[data-test^="field-definition-option-remove-"]'),
        ).toHaveCount(0);
    });

    test('06 zero-active warning is visible but non-blocking', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);

        while (
            (await page
                .locator('[data-test^="field-definition-option-deactivate-"]')
                .count()) > 0
        ) {
            await page
                .locator('[data-test^="field-definition-option-deactivate-"]')
                .first()
                .click();
        }
        await expect(
            page.locator(
                '[data-test="field-definition-options-zero-active-warning"]',
            ),
        ).toBeVisible();

        await page
            .locator('[data-test^="field-definition-option-reactivate-"]')
            .first()
            .click();
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Auswahloptionen übernommen.'),
        ).toBeVisible({ timeout: 15_000 });
    });

    test('07 noop shows keine aenderungen', async ({ page }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await expect(
            page.locator(
                '[data-test="field-definition-options-preview-changes"]',
            ),
        ).toContainText('Keine Änderung');
        await page
            .locator('[data-test="field-definition-options-preview-noop"]')
            .click();
        await expect(page.getByText('Keine Änderungen')).toBeVisible({
            timeout: 15_000,
        });
    });

    test('08 concurrent stale lock returns 409 german message', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);
        const conflict = await csrfJson(
            page,
            'PUT',
            `/administration/dynamische-felder/definitionen/${choiceId}/optionen`,
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
        expect(
            String((conflict.data as { message?: string }).message),
        ).toMatch(/parallel|veraltet|neu laden/i);
    });

    test('09 non-choice has no options section', async ({ page }) => {
        await login(page, 'admin@example.com');
        await page.goto(
            '/administration/dynamische-felder/definitionen/neu',
        );
        await page.locator('#label').fill('REST-B Text');
        await page
            .locator('[data-test="custom-field-type-select"]')
            .selectOption('short_text');
        await page
            .locator('[data-test="custom-field-definition-submit"]')
            .click();
        await page.waitForURL(/\/definitionen\/\d+$/);
        await expect(
            page.locator('[data-test="field-definition-options-section"]'),
        ).toHaveCount(0);
    });

    test('10 fieldset pin revision stays unchanged after options apply', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        await page.goto('/administration/dynamische-felder/feldsets');
        await page.locator('[data-test="fieldset-create-link"]').click();
        await page
            .locator('[data-test="fieldset-name-input"]')
            .fill('REST-B Pin Set');
        await page
            .locator('[data-test="fieldset-key-input"]')
            .fill(`restb_pin_${Date.now()}`);
        await page
            .locator('[data-test="fieldset-applies-to-select"]')
            .selectOption('both');
        await page.locator('[data-test="fieldset-create-submit"]').click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        await page.locator('[data-test="fieldset-open-draft"]').click();
        await expect(
            page.locator('[data-test="fieldset-draft-save"]'),
        ).toBeEnabled({ timeout: 15_000 });
        await page.locator('[data-test="fieldset-add-custom-field"]').click();
        const definitionSelect = page.locator(
            '[data-test="fieldset-add-definition-select"]',
        );
        await expect(definitionSelect).toBeVisible({ timeout: 15_000 });
        const optionValue = await definitionSelect
            .locator('option')
            .filter({ hasText: 'REST-B Auswahl' })
            .or(
                definitionSelect
                    .locator('option')
                    .filter({ hasText: 'rest_b_auswahl' }),
            )
            .first()
            .getAttribute('value');
        expect(optionValue).toBeTruthy();
        await definitionSelect.selectOption(optionValue!);
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/felder') &&
                    response.request().method() === 'POST' &&
                    response.ok(),
            ),
            page.locator('[data-test="fieldset-add-membership-submit"]').click(),
        ]);

        await page.goto(choiceUrl);
        const pinCell = page
            .locator('[data-test^="field-definition-membership-pin-"]')
            .first();
        await expect(pinCell).toBeVisible();
        const pinnedRevisionId = (await pinCell.innerText()).trim();
        expect(pinnedRevisionId).toMatch(/^\d+$/);

        await page
            .locator(
                '[data-test="field-definition-option-label-alpha_manual"]',
            )
            .fill('Alpha Pin Check');
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await page
            .locator('[data-test="field-definition-options-preview-confirm"]')
            .click();
        await expect(
            page.getByText('Auswahloptionen übernommen.'),
        ).toBeVisible({ timeout: 15_000 });

        await expect(
            page
                .locator('[data-test^="field-definition-membership-pin-"]')
                .first(),
        ).toHaveText(pinnedRevisionId);
    });

    test('11 validation error and keyboard focus on preview', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        await page.goto(choiceUrl);

        await page.locator('[data-test="field-definition-options-add"]').click();
        await page
            .locator('[data-test^="field-definition-option-label-"]')
            .last()
            .fill('Bad');
        await page
            .locator('[data-test^="field-definition-option-key-"]')
            .last()
            .fill('BAD');
        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .click();
        await expect(page.locator('text=Muster')).toBeVisible();
        await page
            .locator('[data-test^="field-definition-option-remove-"]')
            .last()
            .click();

        await page
            .locator('[data-test="field-definition-options-preview-button"]')
            .focus();
        await expect(
            page.locator(
                '[data-test="field-definition-options-preview-button"]',
            ),
        ).toBeFocused();
        await page.keyboard.press('Enter');
        const preview = page.locator(
            '[data-test="field-definition-options-preview"]',
        );
        await expect(preview).toBeVisible();
        await expect(preview).toBeFocused();
    });
});
