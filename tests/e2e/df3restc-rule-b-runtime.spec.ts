import { expect, test } from '@playwright/test';
import {
    activateCurrentDraft,
    addCustomFieldToDraft,
    calculationIdFromUrl,
    createChoiceDefinition,
    createDispoFromCurrentCalc,
    dispoOrderIdFromUrl,
    e2eCorruptSnapshotRules,
    e2eDispoSetFieldVisible,
    e2eSetFieldVisible,
    e2eTextValue,
    e2eUpsertSnapshotRule,
    fillMinimalCalcSpots,
    login,
    openOrCreateDraft,
    saveCalculationDraft,
} from './df3restc-helpers';

/**
 * DF-3-RULE-B Runtime Visible/Required – isolierte Suite Port 8014.
 */

test.describe.configure({ mode: 'serial' });

test.describe('DF-3-RULE-B runtime UI', () => {
    const stamp = Date.now();
    const triggerKey = `rb_trig_${stamp}`;
    const hiddenTextKey = `rb_hid_txt_${stamp}`;
    const dispoOnlyKey = `rb_dispo_txt_${stamp}`;
    let calcUrl = '';
    let dispoUrl = '';

    test('01 setup definitions and activate sets', async ({ page }) => {
        test.setTimeout(240_000);
        await login(page, 'admin@example.com');

        await createChoiceDefinition(
            page,
            `RULE-B Trigger ${stamp}`,
            triggerKey,
            'select',
            'header',
            [
                { key: 'show', label: 'Show' },
                { key: 'hide', label: 'Hide' },
            ],
            'calculation',
        );

        await page.goto('/administration/dynamische-felder/definitionen/neu');
        await page.locator('#label').fill(`RULE-B Hidden Text ${stamp}`);
        await page.locator('#key').fill(hiddenTextKey);
        await page.locator('#applies_to').selectOption('both');
        await page.locator('#scope').selectOption('header');
        await page.locator('[data-test="custom-field-definition-submit"]').click();
        await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 15_000 });

        await page.goto('/administration/dynamische-felder/definitionen/neu');
        await page.locator('#label').fill(`RULE-B Dispo Only ${stamp}`);
        await page.locator('#key').fill(dispoOnlyKey);
        await page.locator('#applies_to').selectOption('dispo_order');
        await page.locator('#scope').selectOption('header');
        await page.locator('[data-test="custom-field-definition-submit"]').click();
        await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 15_000 });

        await openOrCreateDraft(page, 'system_calculation_core');
        await addCustomFieldToDraft(page, triggerKey);
        await addCustomFieldToDraft(page, hiddenTextKey);
        await activateCurrentDraft(page);

        await openOrCreateDraft(page, 'system_dispo_order_core');
        await addCustomFieldToDraft(page, hiddenTextKey);
        await addCustomFieldToDraft(page, dispoOnlyKey);
        await activateCurrentDraft(page);
    });

    test('02 calc set_visible keep value required and campaign_period', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-choice-${triggerKey}"]`),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator('[data-test="campaign-period-start"]'),
        ).toBeVisible();

        await page.locator(`[data-test="calc-choice-${triggerKey}"]`).click();
        await page
            .locator(`[data-test="calc-choice-${triggerKey}-option-show"]`)
            .click();
        await page
            .locator(`[data-test="calc-custom-${hiddenTextKey}"]`)
            .fill('regel-wert');
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        const calcId = calculationIdFromUrl(page.url());
        calcUrl = page.url();

        await e2eSetFieldVisible(page, calcId, hiddenTextKey, false);
        await e2eUpsertSnapshotRule(
            page,
            { calculationId: calcId },
            {
                op: 'field_equals',
                field_key: triggerKey,
                value: 'show',
            },
            {
                op: 'set_visible',
                field_key: hiddenTextKey,
                value: true,
            },
        );
        await e2eUpsertSnapshotRule(
            page,
            { calculationId: calcId },
            {
                op: 'field_equals',
                field_key: triggerKey,
                value: 'show',
            },
            { op: 'require_field', field_key: hiddenTextKey },
            105,
        );
        await e2eUpsertSnapshotRule(
            page,
            { calculationId: calcId },
            {
                op: 'field_equals',
                field_key: triggerKey,
                value: 'show',
            },
            { op: 'require_field', field_key: 'campaign_period' },
            110,
        );
        await e2eUpsertSnapshotRule(
            page,
            { calculationId: calcId },
            {
                op: 'field_equals',
                field_key: triggerKey,
                value: 'hide',
            },
            {
                op: 'set_visible',
                field_key: 'campaign_period',
                value: false,
            },
            120,
        );

        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-custom-${hiddenTextKey}"]`),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator(`[data-test="calc-custom-${hiddenTextKey}"]`),
        ).toHaveValue('regel-wert');
        await expect(page.getByText(/RULE-B Hidden Text.*\*/)).toBeVisible();
        await expect(page.getByText(/Kampagnenzeitraum \*/)).toBeVisible();

        await page.locator(`[data-test="calc-choice-${triggerKey}"]`).click();
        await page
            .locator(`[data-test="calc-choice-${triggerKey}-option-hide"]`)
            .click();
        await expect(
            page.locator(`[data-test="calc-custom-${hiddenTextKey}"]`),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="campaign-period-start"]'),
        ).toHaveCount(0);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        expect(
            (await e2eTextValue(page, { calculationId: calcId }, hiddenTextKey))
                .value,
        ).toBe('regel-wert');

        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await page.locator(`[data-test="calc-choice-${triggerKey}"]`).click();
        await page
            .locator(`[data-test="calc-choice-${triggerKey}-option-show"]`)
            .click();
        await expect(
            page.locator(`[data-test="calc-custom-${hiddenTextKey}"]`),
        ).toHaveValue('regel-wert');
        await expect(
            page.locator('[data-test="campaign-period-start"]'),
        ).toBeVisible();
    });

    test('03 calc integrity blocks save and shows banner', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        const calcId = calculationIdFromUrl(page.url());
        await e2eCorruptSnapshotRules(page, { calculationId: calcId });
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator('[data-test="calculation-rules-integrity"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-test="wizard-save"]')).toBeDisabled();
    });

    test('04 dispo system/custom runtime and integrity locks mutations', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-choice-${triggerKey}"]`),
        ).toBeVisible({ timeout: 20_000 });
        await page.locator(`[data-test="calc-choice-${triggerKey}"]`).click();
        await page
            .locator(`[data-test="calc-choice-${triggerKey}-option-show"]`)
            .click();
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        await createDispoFromCurrentCalc(page);
        dispoUrl = page.url();
        const dispoOrderId = dispoOrderIdFromUrl(dispoUrl);

        await e2eDispoSetFieldVisible(page, dispoOrderId, dispoOnlyKey, false);
        await e2eUpsertSnapshotRule(
            page,
            { dispoOrderId },
            { op: 'field_not_empty', field_key: 'disposition_notes' },
            {
                op: 'set_visible',
                field_key: dispoOnlyKey,
                value: true,
            },
        );
        await e2eUpsertSnapshotRule(
            page,
            { dispoOrderId },
            { op: 'field_not_empty', field_key: 'disposition_notes' },
            { op: 'require_field', field_key: 'disposition_notes' },
            105,
        );
        await e2eUpsertSnapshotRule(
            page,
            { dispoOrderId },
            { op: 'field_empty', field_key: 'disposition_notes' },
            {
                op: 'set_visible',
                field_key: 'billing_special_features',
                value: false,
            },
            110,
        );

        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-billing-special-features"]'),
        ).toHaveCount(0);
        await page
            .locator('[data-test="dispo-order-disposition-notes"]')
            .fill('Hinweis');
        await expect(
            page.locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.getByText(/Wichtige Informationen an die Disposition \*/),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-billing-special-features"]'),
        ).toBeVisible();

        await page
            .locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`)
            .fill('dispo-keep');
        await page
            .locator('[data-test="dispo-order-billing-special-features"]')
            .fill('rechnung-keep');
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        await page
            .locator('[data-test="dispo-order-save-system-notes"]')
            .click();

        await page
            .locator('[data-test="dispo-order-disposition-notes"]')
            .fill('');
        await expect(
            page.locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-billing-special-features"]'),
        ).toHaveCount(0);

        await page.reload();

        expect(
            (
                await e2eTextValue(page, { dispoOrderId }, dispoOnlyKey)
            ).value,
        ).toBe('dispo-keep');
        expect(
            (
                await e2eTextValue(
                    page,
                    { dispoOrderId },
                    'billing_special_features',
                )
            ).value,
        ).toBe('rechnung-keep');

        await page
            .locator('[data-test="dispo-order-disposition-notes"]')
            .fill('wieder da');
        await expect(
            page.locator(`[data-test="dispo-custom-${dispoOnlyKey}"]`),
        ).toHaveValue('dispo-keep');
        await expect(
            page.locator('[data-test="dispo-order-billing-special-features"]'),
        ).toHaveValue('rechnung-keep');

        await e2eCorruptSnapshotRules(page, { dispoOrderId });
        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-rules-integrity"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="dispo-order-save-system-notes"]'),
        ).toBeDisabled();
        await expect(
            page.locator('[data-test="dispo-order-save-custom-headers"]'),
        ).toBeDisabled();
        const positionSave = page.locator(
            '[data-test="dispo-order-save-position-customs"]',
        );
        if ((await positionSave.count()) > 0) {
            await expect(positionSave.first()).toBeDisabled();
        }
        const sync = page.locator(
            '[data-test="dispo-order-sync-calc-dynamic-fields"]',
        );
        if ((await sync.count()) > 0) {
            await expect(sync).toBeDisabled();
        }
    });
});
