import { expect, test, type Page } from '@playwright/test';

/**
 * DF-3.2b isolierte Mehrpositions-Smoke-Suite.
 * Läuft nur über playwright.df32b.config.ts (eigene DB database/e2e-df32b.sqlite,
 * Port 8002). Die Hauptsuite ignoriert diese Specs (testIgnore).
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
    await expect(draftOpen.or(createDraft)).toBeVisible({ timeout: 15_000 });
    if (await draftOpen.isVisible()) {
        await draftOpen.click();
    } else {
        await createDraft.click();
    }
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
    }
}

test('DF-3.2b Position-Custom: zwei Positionen, Capture und native Dispo-Felder', async ({
    page,
}) => {
    test.setTimeout(240_000);

    const stamp = Date.now();
    const bothLabel = `E2E Pos Beide ${stamp}`;
    const bothKey = `e2e_pos_beide_${stamp}`;
    const dispoOnlyLabel = `E2E Pos Dispo ${stamp}`;
    const dispoOnlyKey = `e2e_pos_dispo_${stamp}`;
    const valueA = `Wert A ${stamp}`;
    const valueB = `Wert B ${stamp}`;
    const dispoValue = `Dispo Pos ${stamp}`;

    await login(page, 'admin@example.com');

    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(bothLabel);
    await page.locator('#key').fill(bothKey);
    await page.locator('#scope').selectOption('position');
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+/, { timeout: 15_000 });

    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(dispoOnlyLabel);
    await page.locator('#key').fill(dispoOnlyKey);
    await page.locator('#scope').selectOption('position');
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
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();

    await expect(
        page.locator('[data-test^="calculation-custom-position-fields-"]').first(),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await expect(page.getByText('Werbeelement 2')).toBeVisible({
        timeout: 15_000,
    });

    const posSections = page.locator(
        '[data-test^="calculation-custom-position-fields-"]',
    );
    await expect(posSections).toHaveCount(2);

    const keyA = await posSections.nth(0).getAttribute('data-test');
    const keyB = await posSections.nth(1).getAttribute('data-test');
    expect(keyA).toBeTruthy();
    expect(keyB).toBeTruthy();
    const clientKeyA = keyA!.replace('calculation-custom-position-fields-', '');
    const clientKeyB = keyB!.replace('calculation-custom-position-fields-', '');

    await page.locator(`[data-test="calc-pos-custom-${clientKeyA}-${bothKey}"]`).fill(valueA);
    await page.locator(`[data-test="calc-pos-custom-${clientKeyB}-${bothKey}"]`).fill(valueB);

    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.locator('[data-test="range-spots-1-0"]').fill('5');

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });

    await page.reload();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    const reloadedFields = page.locator(
        `[data-test^="calc-pos-custom-"][data-test$="-${bothKey}"]`,
    );
    await expect(reloadedFields).toHaveCount(2, { timeout: 15_000 });
    await expect(reloadedFields.nth(0)).toHaveValue(valueA);
    await expect(reloadedFields.nth(1)).toHaveValue(valueB);

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 30_000 });

    await expect(
        page.locator('[data-test^="dispo-order-calc-origin-position-fields-"]').first(),
    ).toContainText(valueA);
    await expect(
        page.locator('[data-test^="dispo-order-calc-origin-position-fields-"]').nth(1),
    ).toContainText(valueB);

    const nativeField = page.locator(`[data-test^="dispo-pos-custom-"][data-test$="-${dispoOnlyKey}"]`).first();
    await nativeField.fill(dispoValue);
    await page.locator('[data-test="dispo-order-save-position-customs"]').click();
    await expect(page.getByText('Positionsangaben gespeichert.')).toBeVisible({
        timeout: 15_000,
    });
    await page.reload();
    await expect(
        page.locator(`[data-test^="dispo-pos-custom-"][data-test$="-${dispoOnlyKey}"]`).first(),
    ).toHaveValue(dispoValue);
});
