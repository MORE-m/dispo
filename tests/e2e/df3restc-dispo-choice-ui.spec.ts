import { expect, test } from '@playwright/test';
import {
    activateCurrentDraft,
    addCustomFieldToDraft,
    createChoiceDefinition,
    createDispoFromCurrentCalc,
    csrfJson,
    dispoOrderIdFromUrl,
    e2eDispoChoiceValue,
    e2eDispoPositions,
    e2eDispoReplaceChoiceOptions,
    e2eDispoSetFieldVisible,
    fillMinimalCalcSpots,
    fillTwoCalcSpots,
    login,
    openOrCreateDraft,
    saveCalculationDraft,
} from './df3restc-helpers';

/**
 * DF-3-REST-C3 isolierte Dispo-Choice-UI-Suite.
 * Nur über playwright.df3restc.config.ts (Port 8014, eigene SQLite).
 */

test.describe.configure({ mode: 'serial' });

test.describe('DF-3-REST-C3 dispo choice UI', () => {
    const stamp = Date.now();
    const headerSelectKey = `c3_hdr_sel_${stamp}`;
    const headerMultiKey = `c3_hdr_mul_${stamp}`;
    const bothSelectKey = `c3_both_sel_${stamp}`;
    const posSelectKey = `c3_pos_sel_${stamp}`;
    const posMultiKey = `c3_pos_mul_${stamp}`;
    const posOnlyKey = `c3_pos_only_${stamp}`;
    let dispoUrl = '';
    let multiPosDispoUrl = '';

    test('01 setup dispo_order choice definitions and activate fieldsets', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        await login(page, 'admin@example.com');

        await createChoiceDefinition(
            page,
            `C3 Header Select ${stamp}`,
            headerSelectKey,
            'select',
            'header',
            [
                { key: 'alpha', label: 'Alpha' },
                { key: 'beta', label: 'Beta' },
                { key: 'gamma', label: 'Gamma' },
            ],
            'dispo_order',
        );
        await createChoiceDefinition(
            page,
            `C3 Header Multi ${stamp}`,
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
            'dispo_order',
        );
        await createChoiceDefinition(
            page,
            `C3 Both Select ${stamp}`,
            bothSelectKey,
            'select',
            'header',
            [
                { key: 'origin_a', label: 'Origin A' },
                { key: 'origin_b', label: 'Origin B' },
            ],
            'both',
        );
        await createChoiceDefinition(
            page,
            `C3 Pos Select ${stamp}`,
            posSelectKey,
            'select',
            'position',
            [
                { key: 'pos_alpha', label: 'Pos Alpha' },
                { key: 'pos_beta', label: 'Pos Beta' },
                { key: 'shared_opt', label: 'Shared' },
            ],
            'dispo_order',
        );
        await createChoiceDefinition(
            page,
            `C3 Pos Multi ${stamp}`,
            posMultiKey,
            'multi_select',
            'position',
            [
                { key: 'pm_a', label: 'PM A' },
                { key: 'pm_b', label: 'PM B' },
                { key: 'pm_c', label: 'PM C' },
            ],
            'dispo_order',
        );
        await createChoiceDefinition(
            page,
            `C3 Pos Only A ${stamp}`,
            posOnlyKey,
            'select',
            'position',
            [
                { key: 'only_a', label: 'Nur Position A' },
                { key: 'fallback', label: 'Fallback' },
            ],
            'dispo_order',
        );

        await openOrCreateDraft(page, 'system_dispo_order_core');
        await addCustomFieldToDraft(page, headerSelectKey);
        await addCustomFieldToDraft(page, headerMultiKey);
        await addCustomFieldToDraft(page, bothSelectKey);
        await addCustomFieldToDraft(page, posSelectKey);
        await addCustomFieldToDraft(page, posMultiKey);
        await addCustomFieldToDraft(page, posOnlyKey);
        await activateCurrentDraft(page);

        await openOrCreateDraft(page, 'system_calculation_core');
        await addCustomFieldToDraft(page, bothSelectKey);
        await activateCurrentDraft(page);
    });

    test('02 sales create calc and dispo then save select', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await expect(
            page.locator(`[data-test="calc-choice-${bothSelectKey}"]`),
        ).toBeVisible({ timeout: 20_000 });
        await page
            .locator(`[data-test="calc-choice-${bothSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="calc-choice-${bothSelectKey}-option-origin_a"]`,
            )
            .click();
        await fillMinimalCalcSpots(page);
        await saveCalculationDraft(page);

        await createDispoFromCurrentCalc(page);
        dispoUrl = page.url();
        const dispoOrderId = dispoOrderIdFromUrl(dispoUrl);

        await expect(
            page.locator(
                '[data-test="dispo-order-custom-header-choice-fields"]',
            ),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toBeVisible();

        await page
            .locator(`[data-test="dispo-custom-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}-option-alpha"]`,
            )
            .click();
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toContainText('Alpha');

        const stored = await e2eDispoChoiceValue(
            page,
            dispoOrderId,
            headerSelectKey,
        );
        expect(stored.exists).toBe(true);
        expect(stored.value_json).toBe('alpha');
    });

    test('03 multi select with search save and reload', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await page.goto(dispoUrl);
        await expect(
            page.locator(`[data-test="dispo-custom-choice-${headerMultiKey}"]`),
        ).toBeVisible({ timeout: 20_000 });

        const search = page.locator(
            `[data-test="dispo-custom-choice-${headerMultiKey}-search"]`,
        );
        await expect(search).toBeVisible();
        await search.fill('Tag K');
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerMultiKey}-check-tag_k"]`,
            )
            .click();
        await search.fill('');
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerMultiKey}-check-tag_a"]`,
            )
            .click();
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerMultiKey}-count"]`,
            ),
        ).toContainText('2 ausgewählt');

        const stored = await e2eDispoChoiceValue(
            page,
            dispoOrderIdFromUrl(page.url()),
            headerMultiKey,
        );
        expect(stored.value_json).toEqual(['tag_a', 'tag_k']);
    });

    test('04 clear select and save', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page, 'sales@example.com');
        await page.goto(dispoUrl);
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toBeVisible({ timeout: 20_000 });
        await page
            .locator(`[data-test="dispo-custom-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}-clear"]`,
            )
            .click();
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toContainText('Keine Auswahl');

        const stored = await e2eDispoChoiceValue(
            page,
            dispoOrderIdFromUrl(page.url()),
            headerSelectKey,
        );
        expect(stored.value_json).toBeNull();
    });

    test('05 calc-origin section shows transferred choice', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await login(page, 'sales@example.com');
        await page.goto(dispoUrl);
        await expect(
            page.locator('[data-test="dispo-order-calc-origin-custom-fields"]'),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator('[data-test="dispo-order-calc-origin-custom-fields"]'),
        ).toContainText('Aus Kalkulation übernommen');
        await expect(
            page.locator(
                `[data-test="dispo-calc-origin-choice-${bothSelectKey}"]`,
            ),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="dispo-calc-origin-choice-${bothSelectKey}-value"]`,
            ),
        ).toContainText('Origin A');
    });

    test('06 native position select and multi save via position payload', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await fillTwoCalcSpots(page);
        await saveCalculationDraft(page);
        await createDispoFromCurrentCalc(page);
        multiPosDispoUrl = page.url();
        const dispoOrderId = dispoOrderIdFromUrl(multiPosDispoUrl);
        const { positions } = await e2eDispoPositions(page, dispoOrderId);
        expect(positions.length).toBeGreaterThanOrEqual(2);
        const positionA = positions[0]!;
        const positionB = positions[1]!;
        expect(positionA.effective_configuration_snapshot_id).not.toBeNull();
        expect(positionB.effective_configuration_snapshot_id).not.toBeNull();
        expect(positionA.effective_configuration_snapshot_id).not.toBe(
            positionB.effective_configuration_snapshot_id,
        );

        const selectA = page.locator(
            `[data-test="dispo-pos-custom-choice-${positionA.id}-${posSelectKey}"]`,
        );
        const multiA = page.locator(
            `[data-test="dispo-pos-custom-choice-${positionA.id}-${posMultiKey}"]`,
        );
        await expect(selectA).toBeVisible({ timeout: 20_000 });
        await expect(multiA).toBeVisible();

        await selectA.click();
        await page
            .locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posSelectKey}-option-pos_alpha"]`,
            )
            .click();
        await page
            .locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posMultiKey}-check-pm_a"]`,
            )
            .click();
        await page
            .locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posMultiKey}-check-pm_c"]`,
            )
            .click();

        const savePromise = page.waitForResponse(
            (response) =>
                response.url().includes('/positions-angaben') &&
                response.request().method() === 'PATCH',
        );
        await page
            .locator('[data-test="dispo-order-save-position-customs"]')
            .click();
        const saveResponse = await savePromise;
        const body = saveResponse.request().postData() ?? '';
        expect(body).toContain(`"${positionA.id}"`);
        expect(body).toContain(posSelectKey);
        expect(body).toContain(posMultiKey);

        await page.reload();
        await expect(selectA).toContainText('Pos Alpha', { timeout: 20_000 });
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posMultiKey}-count"]`,
            ),
        ).toContainText('2 ausgewählt');

        expect(
            (
                await e2eDispoChoiceValue(
                    page,
                    dispoOrderId,
                    posSelectKey,
                    positionA.id,
                )
            ).value_json,
        ).toBe('pos_alpha');
        expect(
            (
                await e2eDispoChoiceValue(
                    page,
                    dispoOrderId,
                    posMultiKey,
                    positionA.id,
                )
            ).value_json,
        ).toEqual(['pm_a', 'pm_c']);
    });

    test('07 per-position effective options and visibility', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(multiPosDispoUrl);
        const dispoOrderId = dispoOrderIdFromUrl(multiPosDispoUrl);
        const { positions } = await e2eDispoPositions(page, dispoOrderId);
        const positionA = positions[0]!;
        const positionB = positions[1]!;

        await e2eDispoReplaceChoiceOptions(
            page,
            dispoOrderId,
            positionA.id,
            posSelectKey,
            [
                {
                    key: 'only_a',
                    label: 'Nur A',
                    sort: 1,
                    is_active: true,
                },
                {
                    key: 'shared_opt',
                    label: 'Shared A',
                    sort: 2,
                    is_active: true,
                },
            ],
        );
        await e2eDispoReplaceChoiceOptions(
            page,
            dispoOrderId,
            positionB.id,
            posSelectKey,
            [
                {
                    key: 'only_b',
                    label: 'Nur B',
                    sort: 1,
                    is_active: true,
                },
                {
                    key: 'shared_opt',
                    label: 'Shared B',
                    sort: 2,
                    is_active: true,
                },
            ],
        );
        await e2eDispoSetFieldVisible(
            page,
            dispoOrderId,
            posOnlyKey,
            true,
            positionA.id,
        );
        await e2eDispoSetFieldVisible(
            page,
            dispoOrderId,
            posOnlyKey,
            false,
            positionB.id,
        );

        await page.reload();

        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posOnlyKey}"]`,
            ),
        ).toBeVisible({ timeout: 20_000 });
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionB.id}-${posOnlyKey}"]`,
            ),
        ).toHaveCount(0);

        const selectA = page.locator(
            `[data-test="dispo-pos-custom-choice-${positionA.id}-${posSelectKey}"]`,
        );
        const selectB = page.locator(
            `[data-test="dispo-pos-custom-choice-${positionB.id}-${posSelectKey}"]`,
        );
        await selectA.click();
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posSelectKey}-option-only_a"]`,
            ),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionA.id}-${posSelectKey}-option-only_b"]`,
            ),
        ).toHaveCount(0);
        await page.keyboard.press('Escape');

        await selectB.click();
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionB.id}-${posSelectKey}-option-only_b"]`,
            ),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="dispo-pos-custom-choice-${positionB.id}-${posSelectKey}-option-only_a"]`,
            ),
        ).toHaveCount(0);
        await page
            .locator(
                `[data-test="dispo-pos-custom-choice-${positionB.id}-${posSelectKey}-option-only_b"]`,
            )
            .click();
        await page
            .locator('[data-test="dispo-order-save-position-customs"]')
            .click();
        await page.reload();
        await expect(selectB).toContainText('Nur B', { timeout: 20_000 });
        expect(
            (
                await e2eDispoChoiceValue(
                    page,
                    dispoOrderId,
                    posSelectKey,
                    positionB.id,
                )
            ).value_json,
        ).toBe('only_b');
    });

    test('08 stale lock_version shows german 409 without success', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(dispoUrl);
        const dispoOrderId = dispoOrderIdFromUrl(dispoUrl);

        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toBeVisible({ timeout: 20_000 });
        await page
            .locator(`[data-test="dispo-custom-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}-option-alpha"]`,
            )
            .click();
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toContainText('Alpha', { timeout: 20_000 });
        // Flash der Erfolgsmeldung verbrauchen, sonst stört er die 409-Assertion.
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toContainText('Alpha', { timeout: 20_000 });
        await expect(page.getByText(/Dispoauftrag gespeichert/i)).toHaveCount(
            0,
        );

        const meta = await e2eDispoPositions(page, dispoOrderId);
        const lockVersion = meta.lock_version;
        expect(lockVersion).toBeTruthy();

        // Winner: paralleler Save mit aktuellem Lock → Gamma.
        const winner = await csrfJson(
            page,
            'PATCH',
            `/dispoauftraege/${dispoOrderId}`,
            {
                lock_version: lockVersion,
                dynamic_field_values: {
                    [headerSelectKey]: 'gamma',
                },
            },
        );
        expect(winner.status).toBeGreaterThanOrEqual(200);
        expect(winner.status).toBeLessThan(400);

        // UI hat noch den alten Lock: berühren und speichern → 409.
        await page
            .locator(`[data-test="dispo-custom-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}-option-beta"]`,
            )
            .click();

        const conflictPromise = page.waitForResponse(
            (response) =>
                response.url().includes(`/dispoauftraege/${dispoOrderId}`) &&
                !response.url().includes('positions-angaben') &&
                response.request().method() === 'PATCH',
        );
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        const conflictResponse = await conflictPromise;
        expect(conflictResponse.status()).toBe(409);

        await expect(page.locator('body')).toContainText(
            /zwischenzeitlich geändert|Seite neu laden/i,
            { timeout: 15_000 },
        );
        await expect(
            page.getByText(/Exception|Stack trace|SQLSTATE/i),
        ).toHaveCount(0);
        await expect(page.getByText(/Dispoauftrag gespeichert/i)).toHaveCount(
            0,
        );

        expect(
            (await e2eDispoChoiceValue(page, dispoOrderId, headerSelectKey))
                .value_json,
        ).toBe('gamma');
    });

    test('09 unknown option key maps 422 to header choice control', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await page.goto(dispoUrl);
        const dispoOrderId = dispoOrderIdFromUrl(dispoUrl);

        // Baseline nach 409-Test: Gamma ist Gewinner; Seite neu laden.
        await page.reload();
        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toContainText('Gamma', { timeout: 20_000 });

        await page
            .locator(`[data-test="dispo-custom-choice-${headerSelectKey}"]`)
            .click();
        await page
            .locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}-option-beta"]`,
            )
            .click();

        await page.evaluate((fieldKey) => {
            // oxlint-disable-next-line typescript/unbound-method
            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (
                body?: Document | XMLHttpRequestBodyInit | null,
            ) {
                if (
                    typeof body === 'string' &&
                    body.includes(`"${fieldKey}"`)
                ) {
                    for (const from of [
                        `"${fieldKey}":"beta"`,
                        `"${fieldKey}":"alpha"`,
                        `"${fieldKey}":"gamma"`,
                    ]) {
                        if (body.includes(from)) {
                            body = body.replace(
                                from,
                                `"${fieldKey}":"ghostxx"`,
                            );
                            break;
                        }
                    }
                }
                return originalSend.call(this, body);
            };
        }, headerSelectKey);

        const savePromise = page.waitForResponse(
            (response) =>
                response.url().includes(`/dispoauftraege/${dispoOrderId}`) &&
                !response.url().includes('positions-angaben') &&
                response.request().method() === 'PATCH',
        );
        await page
            .locator('[data-test="dispo-order-save-custom-headers"]')
            .click();
        const saveResponse = await savePromise;
        // Inertia-Validation: Redirect 303 oder 422 je nach Accept.
        expect([303, 422, 302]).toContain(saveResponse.status());

        await expect(
            page.locator(
                `[data-test="dispo-custom-choice-${headerSelectKey}"]`,
            ),
        ).toHaveAttribute('aria-invalid', 'true', { timeout: 15_000 });
        await expect(
            page
                .getByText(
                    /unbekannten Optionsschlüssel|nicht mehr auswählbar/i,
                )
                .first(),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.getByText(/Exception|Stack trace|SQLSTATE/i),
        ).toHaveCount(0);
        await expect(page.getByText(/Dispoauftrag gespeichert/i)).toHaveCount(
            0,
        );

        expect(
            (await e2eDispoChoiceValue(page, dispoOrderId, headerSelectKey))
                .value_json,
        ).toBe('gamma');
    });
});
