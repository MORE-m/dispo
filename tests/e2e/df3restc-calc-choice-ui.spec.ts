import { expect, test } from '@playwright/test';
import {
    activateCurrentDraft,
    addCustomFieldToDraft,
    calculationIdFromUrl,
    createChoiceDefinition,
    e2eChoiceValue,
    e2eSetFieldVisible,
    e2eSetOptionActive,
    fillMinimalCalcSpots,
    login,
    openOrCreateDraft,
    saveCalculationDraft,
} from './df3restc-helpers';

/**
 * DF-3-REST-C2 isolierte Calc-Choice-UI-Suite.
 * Nur über playwright.df3restc.config.ts (Port 8014, eigene SQLite).
 * C3 in df3restc-dispo-choice-ui.spec.ts
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

    test('11 historically inactive header select keeps freeze value', async ({
        page,
    }) => {
        test.setTimeout(240_000);
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
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('vor inactive');
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        const inactiveCalcUrl = page.url();
        const calcId = calculationIdFromUrl(inactiveCalcUrl);

        await e2eSetOptionActive(page, calcId, headerSelectKey, 'alpha', false);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        const trigger = page.locator(
            `[data-test="calc-choice-${headerSelectKey}"]`,
        );
        await expect(trigger).toContainText('Alpha', { timeout: 20_000 });
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerSelectKey}-inactive-hint"]`,
            ),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerSelectKey}-inactive-hint"]`,
            ),
        ).toContainText(/inaktiv|nicht erneut auswählbar/i);

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(trigger).toContainText('Alpha');

        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('andere fachliche aenderung');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Alpha');
        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBe('alpha');

        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}"]`)
            .click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerSelectKey}-option-alpha"]`,
            ),
        ).toBeVisible();
        await expect(
            page
                .locator(
                    `[data-test="calc-choice-${headerSelectKey}-option-alpha"] [data-test="calc-choice-${headerSelectKey}-inactive-badge"]`,
                )
                .first(),
        ).toContainText(/Nicht mehr auswählbar|Inaktiv/i);
        await page
            .locator(
                `[data-test="calc-choice-${headerSelectKey}-option-beta"]`,
            )
            .click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Beta');
        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}"]`)
            .click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerSelectKey}-option-alpha"]`,
            ),
        ).toHaveCount(0);
        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}-clear"]`)
            .click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Keine Auswahl');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBeNull();
        calcUrl = inactiveCalcUrl;
    });

    test('12 historically inactive header multi keeps selection until removed', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-choice-${headerMultiKey}"]`),
        ).toBeVisible({ timeout: 20_000 });
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
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        const calcId = calculationIdFromUrl(page.url());

        await e2eSetOptionActive(page, calcId, headerMultiKey, 'tag_a', false);
        await e2eSetOptionActive(page, calcId, headerMultiKey, 'tag_c', false);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            ),
        ).toBeChecked({ timeout: 20_000 });
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-inactive-badge-tag_a"]`,
            ),
        ).toContainText(/Nicht mehr auswählbar|Inaktiv/i);
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_c"]`,
            ),
        ).toBeDisabled();

        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-search"]`,
            )
            .fill('Tag A');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            ),
        ).toBeChecked();
        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-search"]`,
            )
            .fill('');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            ),
        ).toBeChecked();

        await page
            .locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            )
            .click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            ),
        ).toBeDisabled();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_b"]`,
            ),
        ).toBeChecked();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('1 ausgewählt');
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_b"]`,
            ),
        ).toBeChecked();
        await expect(
            page.locator(
                `[data-test="calc-choice-${headerMultiKey}-check-tag_a"]`,
            ),
        ).toBeDisabled();
        expect(
            (await e2eChoiceValue(page, calcId, headerMultiKey)).value_json,
        ).toEqual(['tag_b']);
    });

    test('13 visible via snapshot keeps value and does not auto-touch', async ({
        page,
    }) => {
        test.setTimeout(240_000);
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
                `[data-test="calc-choice-${headerSelectKey}-option-beta"]`,
            )
            .click();
        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('sichtbar-baseline');
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);
        const calcId = calculationIdFromUrl(page.url());

        // Echter Snapshot-Pfad: visible=false serverseitig, dann Feldschema neu laden.
        await e2eSetFieldVisible(page, calcId, headerSelectKey, false);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toHaveCount(0);
        await expect(
            page.locator(`[data-test="e2e-choice-visible-controls"]`),
        ).toHaveCount(0);

        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('nach-hide');
        const hideSaveRequestPromise = page.waitForRequest(
            (request) =>
                request.url().includes(`/kalkulationen/${calcId}`) &&
                ['PUT', 'POST'].includes(request.method()),
        );
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        const hideSaveRequest = await hideSaveRequestPromise;
        const hidePayload = hideSaveRequest.postData() ?? '';
        expect(hidePayload).not.toContain(`"${headerSelectKey}"`);
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBe('beta');

        await e2eSetFieldVisible(page, calcId, headerSelectKey, true);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Beta');

        // Bewusst ändern und speichern; Hide/Show über Snapshot darf den Wert nicht verlieren.
        await page
            .locator(`[data-test="calc-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${headerSelectKey}-option-gamma"]`,
            )
            .click();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBe('gamma');

        await e2eSetFieldVisible(page, calcId, headerSelectKey, false);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toHaveCount(0);
        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('nach-gamma-hide');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBe('gamma');

        await e2eSetFieldVisible(page, calcId, headerSelectKey, true);
        await page.reload();
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-choice-${headerSelectKey}"]`),
        ).toContainText('Gamma');
    });

    test('14 server 422 maps to position choice field without persist', async ({
        page,
    }) => {
        test.setTimeout(240_000);
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
                `[data-test="calc-choice-${headerSelectKey}-option-beta"]`,
            )
            .click();
        await page
            .locator(`[data-test="calc-custom-${textKey}"]`)
            .fill('422-baseline');
        await fillMinimalCalcSpots(page);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        let posSelect = page
            .locator(
                `[data-test*="calc-pos-choice-"][data-test$="-${posSelectKey}"]`,
            )
            .first();
        await expect(posSelect).toBeVisible({ timeout: 20_000 });
        await posSelect.click();
        await page
            .locator(`[data-test$="-${posSelectKey}-option-pos_beta"]`)
            .first()
            .click();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await saveCalculationDraft(page);
        const calcId = calculationIdFromUrl(page.url());
        const editUrl = page.url();

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        posSelect = page
            .locator(
                `[data-test*="calc-pos-choice-"][data-test$="-${posSelectKey}"]`,
            )
            .first();
        await expect(posSelect).toContainText('Pos Beta', { timeout: 20_000 });
        // Touch: gültige Alternativoption, Payload wird nur im Test-XHR manipuliert.
        await posSelect.click();
        await page
            .locator(`[data-test$="-${posSelectKey}-option-pos_alpha"]`)
            .first()
            .click();

        // Ausschließlich Testcode: Body-Injection eines unbekannten Keys (gleiche Länge),
        // damit der echte Choice-Vertrag serverseitig ablehnt — kein produktiver Testschalter.
        await page.evaluate((fieldKey) => {
            // oxlint-disable-next-line typescript/unbound-method
            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (
                body?: Document | XMLHttpRequestBodyInit | null,
            ) {
                if (typeof body === 'string' && body.includes(`"${fieldKey}"`)) {
                    const positionsIdx = body.indexOf('"positions"');
                    if (positionsIdx >= 0) {
                        const head = body.slice(0, positionsIdx);
                        let tail = body.slice(positionsIdx);
                        for (const from of [
                            `"${fieldKey}":"pos_alpha"`,
                            `"${fieldKey}":"pos_beta"`,
                        ]) {
                            if (tail.includes(from)) {
                                // gleiche Länge wie pos_alpha/pos_beta → Content-Length bleibt stimmig
                                tail = tail.replace(
                                    from,
                                    `"${fieldKey}":"ghost_xxx"`,
                                );
                                break;
                            }
                        }
                        body = head + tail;
                    }
                }
                return originalSend.call(this, body);
            };
        }, posSelectKey);

        const saveResponsePromise = page.waitForResponse(
            (response) =>
                response.url().includes(`/kalkulationen/${calcId}`) &&
                !response.url().includes('e2e_invalid') &&
                ['PUT', 'POST'].includes(response.request().method()) &&
                response.status() !== 0,
        );
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        const saveResponse = await saveResponsePromise;
        // Inertia-PUT: ValidationException → Redirect zurück, Middleware setzt 303.
        expect(saveResponse.status()).toBe(303);
        await expect(page).toHaveURL(editUrl);
        await expect(
            page.locator('[data-test="preview-error"]'),
        ).toContainText(/unbekannten Optionsschlüssel|nicht mehr auswählbar|Speichern nicht möglich/i, {
            timeout: 15_000,
        });
        await expect(
            page.getByText(/Exception|Stack trace|SQLSTATE/i),
        ).toHaveCount(0);
        await expect(page.getByText(/erfolgreich gespeichert/i)).toHaveCount(0);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page
                .locator(
                    `[data-test*="calc-pos-choice-"][data-test$="-${posSelectKey}"]`,
                )
                .first(),
        ).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page
                .getByText(
                    /unbekannten Optionsschlüssel|nicht mehr auswählbar/i,
                )
                .first(),
        ).toBeVisible({ timeout: 15_000 });

        expect(
            (await e2eChoiceValue(page, calcId, headerSelectKey)).value_json,
        ).toBe('beta');

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page
                .locator(
                    `[data-test*="calc-pos-choice-"][data-test$="-${posSelectKey}"]`,
                )
                .first(),
        ).toContainText('Pos Beta', { timeout: 20_000 });
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator(`[data-test="calc-custom-${textKey}"]`),
        ).toHaveValue('422-baseline');
    });
});
