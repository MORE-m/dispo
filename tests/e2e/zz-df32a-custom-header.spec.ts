import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3.2a Browser-Smoke auf der separaten E2E-SQLite (database/e2e.sqlite).
 * Setzt die lokale DB `dispo` nicht zurück.
 *
 * Isolation (bewusst kombiniert, nicht nur zz-):
 * 1. playwright.config: fullyParallel=false, workers=1 → Suite seriell.
 * 2. webServer löscht/neu-migriert database/e2e.sqlite pro Suite-Lauf.
 * 3. Dateiname zz-* → läuft nach den übrigen Specs (stabile lexikografische
 *    Discovery-Reihenfolge unter workers=1).
 * 4. Allein ausführbar: `npx playwright test tests/e2e/zz-df32a-custom-header.spec.ts`
 *    startet eigenen frischen webServer (reuseExistingServer: false).
 *
 * Der Test mutiert aktive Feldsets; deshalb zz-* und serielle Config. Feature-
 * Tests decken Required/Submit-Guards unabhängig ab.
 *
 * Helfer warten auf Response/Enabled-State (kein reines isVisible-Rennen),
 * damit CI-Retries mit vorhandenem Entwurf und langsame Inertia-Visits stabil sind.
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

async function openOrCreateDraft(page: Page, fieldSetPathSegment: string) {
    await page.goto(`/administration/dynamische-felder/feldsets`);
    await page
        .getByRole('link', { name: fieldSetPathSegment, exact: true })
        .click();

    const draftOpen = page.getByRole('link', { name: 'Entwurf öffnen' });
    const createDraft = page.getByRole('button', {
        name: 'Entwurf aus aktiver Version',
    });
    // Race vermeiden: nach Retry kann ein Entwurf schon existieren.
    await expect(draftOpen.or(createDraft)).toBeVisible({ timeout: 15_000 });
    if (await draftOpen.isVisible()) {
        await draftOpen.click();
    } else {
        await createDraft.click();
    }
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
        timeout: 15_000,
    });
}

async function activateCurrentDraft(page: Page) {
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
        timeout: 15_000,
    });
    await page.locator('a[href*="/vorschau"]').first().click();
    await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
    const activate = page.locator('[data-test="fieldset-version-activate"]');
    await expect(activate).toBeVisible({ timeout: 15_000 });

    page.once('dialog', (dialog) => dialog.accept());
    await activate.click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
}

async function addCustomFieldToDraft(
    page: Page,
    fieldKey: string,
    makeRequired: boolean,
) {
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
        timeout: 15_000,
    });
    await page.locator('[data-test="fieldset-add-custom-field"]').click();
    const definitionSelect = page.locator(
        '[data-test="fieldset-add-definition-select"]',
    );
    await expect(definitionSelect).toBeVisible({ timeout: 15_000 });
    const optionValue = await definitionSelect
        .locator('option')
        .filter({ hasText: fieldKey })
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
    await expect(page.getByText(fieldKey).first()).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
        timeout: 15_000,
    });

    if (makeRequired) {
        await page
            .locator(`[data-test="membership-required-${fieldKey}"]`)
            .selectOption('1');
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/versionen/') &&
                    response.request().method() === 'PUT' &&
                    response.ok(),
            ),
            page.locator('[data-test="fieldset-draft-save"]').click(),
        ]);
        await expect(
            page.locator(`[data-test="membership-required-${fieldKey}"]`),
        ).toHaveValue('1', { timeout: 15_000 });
        await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled({
            timeout: 15_000,
        });
    }
}

test('DF-3.2a Custom-Header: Admin → Calc Pflicht → Dispo Capture read-only', async ({
    page,
}) => {
    test.setTimeout(180_000);

    const stamp = Date.now();
    const bothLabel = `E2E Beide ${stamp}`;
    const bothKey = `e2e_beide_${stamp}`;
    const dispoOnlyLabel = `E2E Dispo Only ${stamp}`;
    const dispoOnlyKey = `e2e_dispo_only_${stamp}`;
    const bothValue = `Calc-Wert ${stamp}`;
    const dispoOnlyValue = `Dispo-Wert ${stamp}`;

    await login(page, 'admin@example.com');

    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(bothLabel);
    await page.locator('#key').fill(bothKey);
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+/, { timeout: 15_000 });

    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(dispoOnlyLabel);
    await page.locator('#key').fill(dispoOnlyKey);
    await page.locator('#applies_to').selectOption('dispo_order');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+/, { timeout: 15_000 });

    await openOrCreateDraft(page, 'system_calculation_core');
    await addCustomFieldToDraft(page, bothKey, true);
    await activateCurrentDraft(page);

    await openOrCreateDraft(page, 'system_dispo_order_core');
    await addCustomFieldToDraft(page, bothKey, false);
    await addCustomFieldToDraft(page, dispoOnlyKey, false);
    await activateCurrentDraft(page);

    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
        timeout: 15_000,
    });
    await login(page, 'sales@example.com');

    await page.goto('/kalkulationen/neu');
    await expect(
        page.locator('[data-test="calculation-custom-header-fields"]'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(`${bothLabel} *`)).toBeVisible();

    await page.locator(`[data-test="calc-custom-${bothKey}"]`).fill(bothValue);
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    // PO-32b-1: Calc-Create wird nicht durch leere Custom-Pflichtfelder blockiert
    // (Feature-Tests); hier Capture-Pfad mit gesetztem Wert.
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });

    await page.reload();
    await expect(page.locator(`[data-test="calc-custom-${bothKey}"]`)).toHaveValue(
        bothValue,
    );

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });

    await expect(
        page.locator('[data-test="dispo-order-calc-origin-custom-fields"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="dispo-order-calc-origin-custom-fields"]'),
    ).toContainText(bothValue);
    await expect(page.locator(`[data-test="dispo-custom-${bothKey}"]`)).toHaveCount(
        0,
    );

    await expect(
        page.locator('[data-test="dispo-order-custom-header-card"]'),
    ).toBeVisible();
    await page.locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`).fill(dispoOnlyValue);
    await page.locator('[data-test="dispo-order-save-custom-headers"]').click();
    await expect(page.getByText('Dispoauftrag gespeichert.')).toBeVisible({
        timeout: 15_000,
    });
    await page.reload();
    await expect(
        page.locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`),
    ).toHaveValue(dispoOnlyValue);
    await expect(
        page.locator('[data-test="dispo-order-calc-origin-custom-fields"]'),
    ).toContainText(bothValue);
});
