import { expect, test, type APIResponse, type Page } from '@playwright/test';

/**
 * DF-3.3a2β isolierte Suite: Generation-3-Freeze mit mindestens zwei
 * Positionen und unterschiedlichen Kategorie-/Werbemittel-Schemas.
 * Läuft nur über playwright.df33a2b.config.ts (DB e2e-df33a2b.sqlite, Port 8005).
 */

const stamp = Date.now();
const fieldKeyA = `df33a2b_a_${stamp}`;
const fieldKeyB = `df33a2b_b_${stamp}`;
const setKeyA = `df33a2b_set_a_${stamp}`;
const setKeyB = `df33a2b_set_b_${stamp}`;

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

async function logout(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
        timeout: 15_000,
    });
}

async function postJson(
    page: Page,
    url: string,
    data: Record<string, unknown>,
): Promise<APIResponse> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrf, 'XSRF-TOKEN-Cookie fehlt').toBeTruthy();

    return page.request.post(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
        },
        data,
    });
}

async function createCustomPositionDefinition(page: Page, label: string, key: string) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#key').fill(key);
    await page.locator('#scope').selectOption('position');
    await page.locator('#applies_to').selectOption('calculation');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

async function addCustomFieldToDraft(page: Page, fieldKey: string) {
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
}

async function createAndActivateFieldSet(
    page: Page,
    name: string,
    key: string,
    fieldKey: string,
): Promise<number> {
    await page.goto('/administration/dynamische-felder/feldsets');
    await page.locator('[data-test="fieldset-create-link"]').click();
    await page.locator('[data-test="fieldset-name-input"]').fill(name);
    await page.locator('[data-test="fieldset-key-input"]').fill(key);
    await page
        .locator('[data-test="fieldset-applies-to-select"]')
        .selectOption('calculation');
    await page.locator('[data-test="fieldset-create-submit"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

    const fieldSetId = Number(/feldsets\/(\d+)/.exec(page.url())![1]);
    expect(fieldSetId).toBeGreaterThan(0);

    await page.locator('[data-test="fieldset-open-draft"]').click();
    await addCustomFieldToDraft(page, fieldKey);

    await page.locator('a[href*="/vorschau"]').first().click();
    await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-test="fieldset-version-activate"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

    return fieldSetId;
}

async function activateAssignment(
    page: Page,
    payload: Record<string, unknown>,
) {
    const created = await postJson(
        page,
        '/administration/dynamische-felder/assignments',
        payload,
    );
    expect(created.status(), await created.text()).toBe(201);
    const assignment = (await created.json()).assignment;

    const previewResponse = await postJson(
        page,
        `/administration/dynamische-felder/assignments/${assignment.id}/aktivierungs-vorschau`,
        {},
    );
    expect(previewResponse.status(), await previewResponse.text()).toBe(200);
    const preview = await previewResponse.json();
    expect(preview.has_blocking_conflicts).toBe(false);

    const activated = await postJson(
        page,
        `/administration/dynamische-felder/assignments/${assignment.id}/aktivieren`,
        {
            lock_version: assignment.lock_version,
            fingerprint: preview.fingerprint,
        },
    );
    expect(activated.status(), await activated.text()).toBe(200);
}

test.describe('DF-3.3a2β contextual freeze', () => {
    test('zwei Positionen mit unterschiedlichen Kat-/Medium-Schemas frieren Gen3 ein', async ({
        page,
    }) => {
        test.setTimeout(300_000);

        await login(page, 'admin@example.com');

        await createCustomPositionDefinition(
            page,
            `β Feld A ${stamp}`,
            fieldKeyA,
        );
        await createCustomPositionDefinition(
            page,
            `β Feld B ${stamp}`,
            fieldKeyB,
        );
        const setIdA = await createAndActivateFieldSet(
            page,
            `β Set A ${stamp}`,
            setKeyA,
            fieldKeyA,
        );
        const setIdB = await createAndActivateFieldSet(
            page,
            `β Set B ${stamp}`,
            setKeyB,
            fieldKeyB,
        );

        await page.goto('/kalkulationen/neu');
        const catalog = await page.evaluate(() => {
            const script = document.querySelector(
                'script[data-page][type="application/json"]',
            );
            const payload = JSON.parse(script!.textContent!);

            return payload.props.catalog as {
                inventories: Array<{ id: number }>;
                media: Array<{
                    id: number;
                    code?: string;
                    category_id?: number;
                }>;
            };
        });
        expect(catalog.inventories.length).toBeGreaterThanOrEqual(2);
        expect(catalog.media.length).toBeGreaterThanOrEqual(2);

        const mediumA =
            catalog.media.find((m) => m.code === 'spot_classic') ??
            catalog.media[0];
        const mediumB =
            catalog.media.find((m) => m.id !== mediumA.id) ?? catalog.media[1];
        expect(mediumA.category_id).toBeTruthy();
        expect(mediumB.id).not.toBe(mediumA.id);

        await activateAssignment(page, {
            field_set_id: setIdA,
            target_layer: 'advertising_category',
            advertising_category_id: mediumA.category_id,
            applies_to_process: 'calculation',
        });
        await activateAssignment(page, {
            field_set_id: setIdB,
            target_layer: 'advertising_medium',
            advertising_medium_id: mediumB.id,
            applies_to_process: 'calculation',
        });

        await logout(page);
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');

        const baseSchema = await postJson(page, '/kalkulationen/feldschema', {});
        expect(baseSchema.status()).toBe(200);
        const baseBody = await baseSchema.json();
        expect(baseBody.target_format_version ?? baseBody.format_version).toBe(
            3,
        );

        const schemaA = await postJson(page, '/kalkulationen/feldschema', {
            advertising_medium_id: mediumA.id,
        });
        const schemaB = await postJson(page, '/kalkulationen/feldschema', {
            advertising_medium_id: mediumB.id,
        });
        expect(schemaA.status()).toBe(200);
        expect(schemaB.status()).toBe(200);
        const bodyA = await schemaA.json();
        const bodyB = await schemaB.json();
        expect(bodyA.fieldSchema.schema_fingerprint).toBeTruthy();
        expect(bodyB.fieldSchema.schema_fingerprint).toBeTruthy();
        expect(bodyA.fieldSchema.schema_fingerprint).not.toBe(
            bodyB.fieldSchema.schema_fingerprint,
        );

        const keysA = (bodyA.fieldSchema.fields as Array<{ key: string }>).map(
            (field) => field.key,
        );
        const keysB = (bodyB.fieldSchema.fields as Array<{ key: string }>).map(
            (field) => field.key,
        );
        expect(keysA).toContain(fieldKeyA);
        expect(keysB).toContain(fieldKeyB);
        expect(keysA).not.toContain(fieldKeyB);
        expect(keysB).not.toContain(fieldKeyA);

        const create = await postJson(page, '/kalkulationen', {
            planning_mode: 'manual',
            customer_name: `Kunde β ${stamp}`,
            agency_name: null,
            campaign: `β Zwei Pos ${stamp}`,
            product_title: 'β',
            order_discount_percent: '0',
            ae_enabled: false,
            schema_fingerprint: baseBody.fieldSchema.schema_fingerprint,
            dynamic_field_values: { campaign_period: null },
            positions: [
                {
                    inventory_id: catalog.inventories[0].id,
                    advertising_medium_id: mediumA.id,
                    schema_fingerprint: bodyA.fieldSchema.schema_fingerprint,
                    spot_method: 'average',
                    length_seconds: 30,
                    total_spot_count: 10,
                    position_discount_percent: '0',
                    ae_percent: '15',
                    plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
                    dynamic_field_values: {
                        period_open: true,
                        position_flight_period: null,
                        [fieldKeyA]: 'Wert A',
                    },
                },
                {
                    inventory_id: catalog.inventories[1].id,
                    advertising_medium_id: mediumB.id,
                    schema_fingerprint: bodyB.fieldSchema.schema_fingerprint,
                    spot_method: 'average',
                    length_seconds: 20,
                    total_spot_count: 5,
                    position_discount_percent: '0',
                    ae_percent: '15',
                    plan_rows: [{ hour: 10, day_group: 'mo_fr' }],
                    dynamic_field_values: {
                        period_open: true,
                        position_flight_period: null,
                        [fieldKeyB]: 'Wert B',
                    },
                },
            ],
        });

        expect(create.status(), await create.text()).toBeLessThan(400);

        await page.goto('/kalkulationen');
        await expect(page.getByText(`β Zwei Pos ${stamp}`)).toBeVisible({
            timeout: 30_000,
        });
        await page.getByRole('link', { name: /K-\d{4}-\d+/ }).first().click();
        await expect(page).toHaveURL(/kalkulationen\/\d+$/, { timeout: 30_000 });

        const frozen = await postJson(page, '/kalkulationen/feldschema', {});
        expect(frozen.status()).toBe(200);
        const frozenBody = await frozen.json();
        expect(frozenBody.format_version ?? frozenBody.fieldSchema.format_version).toBe(
            3,
        );
    });
});
