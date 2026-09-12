import { expect, type Page } from '@playwright/test';

export async function login(page: Page, email: string) {
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

export async function openOrCreateDraft(page: Page, fieldSetPathSegment: string) {
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
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeVisible({
        timeout: 15_000,
    });
}

export async function activateCurrentDraft(page: Page) {
    await page.locator('a[href*="/vorschau"]').first().click();
    await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
    const activate = page.locator('[data-test="fieldset-version-activate"]');
    await expect(activate).toBeVisible({ timeout: 15_000 });
    page.once('dialog', (dialog) => dialog.accept());
    await activate.click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
}

export async function addCustomFieldToDraft(page: Page, fieldKey: string) {
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
}

export async function createChoiceDefinition(
    page: Page,
    label: string,
    key: string,
    fieldType: 'select' | 'multi_select',
    scope: 'header' | 'position',
    options: Array<{ key: string; label: string }>,
) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#key').fill(key);
    await page.locator('#applies_to').selectOption('calculation');
    await page.locator('#scope').selectOption(scope);
    await page
        .locator('[data-test="custom-field-type-select"]')
        .selectOption(fieldType);
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 15_000 });

    for (let i = 0; i < options.length; i++) {
        await page.locator('[data-test="field-definition-options-add"]').click();
    }
    const labelInputs = page.locator(
        '[data-test^="field-definition-option-label-"]',
    );
    const keyInputs = page.locator(
        '[data-test^="field-definition-option-key-"]',
    );
    for (let i = 0; i < options.length; i++) {
        await labelInputs.nth(i).fill(options[i]!.label);
        await keyInputs.nth(i).fill(options[i]!.key);
    }
    await page
        .locator('[data-test="field-definition-options-preview-button"]')
        .click();
    await page
        .locator('[data-test="field-definition-options-preview-confirm"]')
        .click();
    await expect(page.getByText('Auswahloptionen übernommen.')).toBeVisible({
        timeout: 15_000,
    });
}

export async function fillMinimalCalcSpots(page: Page) {
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
}

export async function saveCalculationDraft(page: Page) {
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
}
