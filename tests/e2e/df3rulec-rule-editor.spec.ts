import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3-RULE-C isolierte Admin-Suite.
 * playwright.df3rulec.config.ts (eigene DB, Port 8015).
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

async function createTextDefinition(
    page: Page,
    label: string,
    scope: 'header' | 'position',
) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('[data-test="custom-field-scope-select"]').selectOption(scope);
    await page.locator('#applies_to').selectOption('calculation');
    await page
        .locator('[data-test="custom-field-type-select"]')
        .selectOption('short_text');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

async function addMembershipByLabel(page: Page, label: string) {
    await page.locator('[data-test="fieldset-add-custom-field"]').click();
    const select = page.locator('[data-test="fieldset-add-definition-select"]');
    await expect(select).toBeVisible({ timeout: 15_000 });
    const option = select.locator('option', { hasText: label }).first();
    const value = await option.getAttribute('value');
    expect(value).toBeTruthy();
    await select.selectOption(value!);
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().includes('/felder'),
        ),
        page.locator('[data-test="fieldset-add-membership-submit"]').click(),
    ]);
}

test.describe('DF-3-RULE-C Regel-Editor', () => {
    test('System-Kern: Seed sichtbar, Vorschau und No-op-Apply', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await login(page, 'admin@example.com');

        await page.goto('/administration/dynamische-felder/feldsets');
        await page
            .getByRole('link', {
                name: /system_calculation_core|Kalkulations-Kern/i,
            })
            .first()
            .click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        const openDraft = page.locator('[data-test="fieldset-open-draft"]');
        const createDraft = page.locator('[data-test="fieldset-create-draft"]');
        if (await openDraft.isVisible()) {
            await openDraft.click();
        } else if (await createDraft.isVisible()) {
            await createDraft.click();
        } else {
            await page.getByRole('button', { name: /Entwurf/i }).first().click();
        }

        await expect(page).toHaveURL(/versionen\/\d+/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="fieldset-rules-editor"]'),
        ).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByText('Systemregel').first()).toBeVisible();
        await expect(page.locator('[data-test="fieldset-rule-0"]')).toBeVisible();
        await expect(
            page.locator('[data-test="fieldset-rules-example-values"]'),
        ).toBeVisible();

        const previewResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln-vorschau') &&
                response.request().method() === 'POST',
        );
        await page.locator('[data-test="fieldset-rules-preview"]').click();
        const preview = await previewResponse;
        expect(preview.ok()).toBeTruthy();
        await expect(
            page.locator('[data-test="fieldset-rules-preview-result"]'),
        ).toBeVisible();

        const applyResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln') &&
                !response.url().includes('vorschau') &&
                response.request().method() === 'PUT',
        );
        await page.locator('[data-test="fieldset-rules-apply"]').click();
        const apply = await applyResponse;
        expect(apply.ok()).toBeTruthy();
        const body = (await apply.json()) as { has_changes?: boolean };
        expect(body.has_changes).toBe(false);
    });

    test('Freier Entwurf: Regel-CRUD, Beispielwerte, Duplikat, Read-only Active', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'admin@example.com');

        const suffix = Date.now().toString().slice(-6);
        const headerLabel = `RULEC Header ${suffix}`;
        const positionLabel = `RULEC Position ${suffix}`;
        const fieldSetName = `RULEC Free ${suffix}`;
        const fieldSetKey = `rule_c_free_${suffix}`;

        await createTextDefinition(page, headerLabel, 'header');
        await createTextDefinition(page, positionLabel, 'position');

        await page.goto('/administration/dynamische-felder/feldsets');
        await page.locator('[data-test="fieldset-create-link"]').click();
        await page.locator('[data-test="fieldset-name-input"]').fill(fieldSetName);
        await page.locator('[data-test="fieldset-key-input"]').fill(fieldSetKey);
        await page
            .locator('[data-test="fieldset-applies-to-select"]')
            .selectOption('calculation');
        await page.locator('[data-test="fieldset-create-submit"]').click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        await page.locator('[data-test="fieldset-open-draft"]').click();
        await expect(page).toHaveURL(/versionen\/\d+/, { timeout: 30_000 });
        await addMembershipByLabel(page, headerLabel);
        await addMembershipByLabel(page, positionLabel);

        await expect(
            page.locator('[data-test="fieldset-rules-editor"]'),
        ).toBeVisible();
        await page.locator('[data-test="fieldset-rules-add"]').click();
        const rule0 = page.locator('[data-test="fieldset-rule-0"]');
        await expect(rule0).toBeVisible();
        await expect(rule0.getByRole('button', { name: 'Schließen' })).toBeVisible();

        await rule0.getByRole('combobox', { name: /^Feld$/ }).selectOption({ index: 0 });
        const headerKey = await rule0
            .getByRole('combobox', { name: /^Feld$/ })
            .inputValue();
        expect(headerKey).toContain('rulec_header');
        await rule0.getByRole('textbox').first().fill('aktiv');
        await rule0.getByRole('combobox', { name: /^Zielfeld$/ }).selectOption({ index: 1 });
        const positionKey = await rule0
            .getByRole('combobox', { name: /^Zielfeld$/ })
            .inputValue();
        expect(positionKey).toContain('rulec_position');

        const headerExample = page
            .locator('[data-test="fieldset-example-header"]')
            .getByRole('textbox')
            .first();
        await headerExample.fill('aktiv');

        const positionExample = page
            .locator('[data-test="fieldset-example-position"]')
            .getByRole('textbox')
            .first();
        await positionExample.fill('x');

        const previewMatched = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln-vorschau') &&
                response.request().method() === 'POST',
        );
        await page.locator('[data-test="fieldset-rules-preview"]').click();
        const matchedPreview = await previewMatched;
        expect(matchedPreview.ok()).toBeTruthy();
        const matchedBody = (await matchedPreview.json()) as {
            matched: boolean[];
            fingerprint: string;
        };
        expect(matchedBody.matched[0]).toBe(true);
        await expect(rule0.getByText('Beispiel trifft zu')).toBeVisible();

        await headerExample.fill('inaktiv');
        await expect(
            page.locator('[data-test="fieldset-rules-preview-result"]'),
        ).toHaveCount(0);

        const previewNotMatched = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln-vorschau') &&
                response.request().method() === 'POST',
        );
        await page.locator('[data-test="fieldset-rules-preview"]').click();
        const notMatched = await previewNotMatched;
        expect(notMatched.ok()).toBeTruthy();
        const notMatchedBody = (await notMatched.json()) as {
            matched: boolean[];
            fingerprint: string;
        };
        expect(notMatchedBody.matched[0]).toBe(false);
        await expect(rule0.getByText('Beispiel trifft nicht zu')).toBeVisible();
        expect(notMatchedBody.fingerprint).toBe(matchedBody.fingerprint);

        await headerExample.fill('aktiv');
        const saveResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln') &&
                !response.url().includes('vorschau') &&
                response.request().method() === 'PUT',
        );
        await page.locator('[data-test="fieldset-rules-apply"]').click();
        const saved = await saveResponse;
        expect(saved.ok()).toBeTruthy();

        await page.reload();
        await expect(page.locator('[data-test="fieldset-rule-0"]')).toBeVisible({
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-test="fieldset-rule-0"]'),
        ).toContainText(/pflicht|require/i);

        await page
            .locator('[data-test="fieldset-rule-0"]')
            .getByRole('button', { name: 'Duplizieren' })
            .click();
        await expect(page.locator('[data-test="fieldset-rule-1"]')).toBeVisible();
        await expect(
            page.locator('[data-test="fieldset-rule-1"]').getByRole('button', { name: 'Schließen' }),
        ).toBeVisible();

        const rejectDup = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln-vorschau') &&
                response.request().method() === 'POST',
        );
        await page.locator('[data-test="fieldset-rules-preview"]').click();
        const dupPreview = await rejectDup;
        expect(dupPreview.status()).toBe(422);
        const dupBody = (await dupPreview.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        expect(
            `${dupBody.message ?? ''} ${JSON.stringify(dupBody.errors ?? {})}`,
        ).toMatch(/Identische Regeln|Duplikat/i);
        await expect(
            page
                .locator('[data-test="fieldset-rules-editor"]')
                .getByText(/Identische Regeln|Duplikat/i)
                .first(),
        ).toBeVisible({ timeout: 10_000 });

        await page
            .locator('[data-test="fieldset-rule-1"]')
            .getByRole('textbox')
            .first()
            .fill('anders');

        const saveDup = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln') &&
                !response.url().includes('vorschau') &&
                response.request().method() === 'PUT',
        );
        await page.locator('[data-test="fieldset-rules-apply"]').click();
        expect((await saveDup).ok()).toBeTruthy();
        await expect(page.locator('[data-test="fieldset-rule-1"]')).toBeVisible();

        await page
            .locator('[data-test="fieldset-rule-1"]')
            .getByRole('button', { name: 'Nach oben' })
            .click();
        await expect(
            page.locator('[data-test="fieldset-rule-0"]'),
        ).toContainText('anders');

        await page
            .locator('[data-test="fieldset-rule-0"]')
            .getByRole('button', { name: 'Entfernen' })
            .click();
        const saveDelete = page.waitForResponse(
            (response) =>
                response.url().includes('/regeln') &&
                !response.url().includes('vorschau') &&
                response.request().method() === 'PUT',
        );
        await page.locator('[data-test="fieldset-rules-apply"]').click();
        expect((await saveDelete).ok()).toBeTruthy();
        await expect(page.locator('[data-test="fieldset-rule-1"]')).toHaveCount(0);

        await page.locator('a[href*="/vorschau"]').first().click();
        await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
        const activate = page.locator('[data-test="fieldset-version-activate"]');
        await expect(activate).toBeVisible({ timeout: 15_000 });
        page.once('dialog', (dialog) => dialog.accept());
        await activate.click();
        await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

        await page.getByRole('link', { name: 'Ansehen' }).first().click();
        await expect(page).toHaveURL(/versionen\/\d+/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="fieldset-rules-editor"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="fieldset-rules-add"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="fieldset-rule-0"]'),
        ).toBeVisible();
        await expect(
            page.getByText(/Nur Lesen|Zum Ändern bitte einen Entwurf/i),
        ).toBeVisible();
    });
});
