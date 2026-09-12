import { expect, test } from '@playwright/test';
import {
    activateCurrentDraft,
    addCustomFieldToDraft,
    createChoiceDefinition,
    fillMinimalCalcSpots,
    login,
    openOrCreateDraft,
    saveCalculationDraft,
} from './df3restc-helpers';

/**
 * DF-3-REST-C2 isolierte Calc-Choice-UI-Suite.
 * Nur über playwright.df3restc.config.ts (Port 8014, eigene SQLite).
 * Keine C3-Dispo-Choice-UI.
 */

test.describe.configure({ mode: 'serial' });

test.describe('DF-3-REST-C2 calc choice UI', () => {
    const stamp = Date.now();
    const headerSelectKey = `c2_hdr_sel_${stamp}`;
    const headerMultiKey = `c2_hdr_mul_${stamp}`;
    const posSelectKey = `c2_pos_sel_${stamp}`;
    const posMultiKey = `c2_pos_mul_${stamp}`;
    const textKey = `c2_hdr_txt_${stamp}`;
    let calcUrl = '';

    test('01 setup choice definitions and activate calc fieldset', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await login(page, 'admin@example.com');

        await createChoiceDefinition(
            page,
            `C2 Header Select ${stamp}`,
            headerSelectKey,
            'select',
            'header',
            [
                { key: 'alpha', label: 'Alpha' },
                { key: 'beta', label: 'Beta' },
                { key: 'gamma', label: 'Gamma' },
            ],
        );
        await createChoiceDefinition(
            page,
            `C2 Header Multi ${stamp}`,
            headerMultiKey,
            'multi_select',
            'header',
            [
                { key: 'tag_a', label: 'Tag A' },
                { key: 'tag_b', label: 'Tag B' },
                { key: 'tag_c', label: 'Tag C' },
                { key: 'tag_d', label: 'Tag D' },
                { key: 'tag_e', label: 'Tag E' },
                { key: 'tag_f', label: 'Tag F' },
                { key: 'tag_g', label: 'Tag G' },
                { key: 'tag_h', label: 'Tag H' },
                { key: 'tag_i', label: 'Tag I' },
                { key: 'tag_j', label: 'Tag J' },
                { key: 'tag_k', label: 'Tag K' },
            ],
        );
        await createChoiceDefinition(
            page,
            `C2 Pos Select ${stamp}`,
            posSelectKey,
            'select',
            'position',
            [
                { key: 'pos_alpha', label: 'Pos Alpha' },
                { key: 'pos_beta', label: 'Pos Beta' },
            ],
        );
        await createChoiceDefinition(
            page,
            `C2 Pos Multi ${stamp}`,
            posMultiKey,
            'multi_select',
            'position',
            [
                { key: 'pos_tag_1', label: 'Pos Tag 1' },
                { key: 'pos_tag_2', label: 'Pos Tag 2' },
            ],
        );

        await page.goto('/administration/dynamische-felder/definitionen/neu');
        await page.locator('#label').fill(`C2 Text ${stamp}`);
        await page.locator('#key').fill(textKey);
        await page.locator('#applies_to').selectOption('calculation');
        await page.locator('#scope').selectOption('header');
        await page.locator('[data-test="custom-field-definition-submit"]').click();
        await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 15_000 });

        await openOrCreateDraft(page, 'system_calculation_core');
        await addCustomFieldToDraft(page, headerSelectKey);
        await addCustomFieldToDraft(page, headerMultiKey);
        await addCustomFieldToDraft(page, posSelectKey);
        await addCustomFieldToDraft(page, posMultiKey);
        await addCustomFieldToDraft(page, textKey);
        await activateCurrentDraft(page);
    });

    test('02 header select and multi render from snapshot', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator('[data-test="calculation-custom-header-fields"]'),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="calc-choice-${headerMultiKey}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="calc-custom-${textKey}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Keine Auswahl');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('0 ausgewählt');
    });

    test('03 save select/multi and restore after reload', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toBeVisible({ timeout: 20_000 });

        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${headerSelectKey}-option-alpha"]`,
            )
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            )
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_b"]`,
            )
            .click();
        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('Text bleibt');

        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        calcUrl = page.url();

        await page.reload();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Alpha');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('2 ausgewählt');
        await expect(
            page.locator(`[data-test="calc-custom-${textKey}"]`),
        ).toHaveValue('Text bleibt');
    });

    test('04 wizard navigation keeps choice state', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Alpha', { timeout: 20_000 });

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Alpha');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('2 ausgewählt');
    });

    test('05 position choice uses position effective fields', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator(`[data-test^="calc-pos-choice-"]`).first(),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator(`[data-test$="-${posSelectKey}"]`).first(),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test$="-${posMultiKey}"]`).first(),
        ).toBeVisible();
    });

    test('06 two positions keep independent choice values', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        const addButton = page.getByRole('button', {
            name: 'Werbeelement hinzufügen',
        });
        await expect(addButton).toBeVisible({ timeout: 15_000 });
        await addButton.click();

        const posSelectTriggers = page.locator(
            `[data-test*="calc-pos-choice-"][data-test$="-${posSelectKey}"]`,
        );
        await expect(posSelectTriggers).toHaveCount(2, { timeout: 20_000 });

        await posSelectTriggers.first().click();
        await page
            .locator(
                `[data-test$="-${posSelectKey}-option-pos_alpha"]`,
            )
            .first()
            .click();

        await expect(posSelectTriggers.nth(1)).not.toContainText('Pos Alpha');
    });

    test('07 clear optional select and multi explicitly', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        await page.getByRole('button', { name: '1. Grunddaten' }).click();

        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}-clear"]`)
            .click();

        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            )
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_b"]`,
            )
            .click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('0 ausgewählt');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await saveCalculationDraft(page);
        await page.reload();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Keine Auswahl');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('0 ausgewählt');
    });

    test('08 multi search and limit hint', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl);
        await page.getByRole('button', { name: '1. Grunddaten' }).click();

        const search = page.locator(
            `[data-test="calc-choice-${headerMultiKey}-search"]`,
        );
        await expect(search).toBeVisible({ timeout: 20_000 });
        await search.fill('zzz-no-match');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-empty"]`,
            ),
        ).toBeVisible();
        await search.fill('');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-limit"]`,
            ),
        ).toContainText('Maximal 50');
    });

    test('09 required marker without draft block and text regression', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-custom-${textKey}"]`),
        ).toBeVisible({ timeout: 20_000 });
        await page.locator(`[data-test="calc-custom-${textKey}"]`).fill('ok');
        // Select bleibt leer → Draft trotzdem speicherbar (C1 Pflicht erst Dispo).
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        await expect(page).toHaveURL(/kalkulationen\/\d+/);
    });

    test('10 keyboard focus on select trigger', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto(calcUrl || '/kalkulationen/neu');
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        const trigger = page.locator(
            `[data-test="calc-choice-${headerSelectKey}"]`,
        );
        await expect(trigger).toBeVisible({ timeout: 20_000 });
        await trigger.focus();
        await expect(trigger).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerSelectKey}-content"]`,
            ),
        ).toBeVisible();
    });
});
