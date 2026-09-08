import { expect, test, type APIResponse, type Page } from '@playwright/test';

/**
 * DF-3.3a2α isolierte Suite: globale Zuweisung eines freien Feldsets wird im
 * Kalkulationswizard angeboten und beim Anlegen als Generation-2-Snapshot
 * eingefroren. Läuft nur über playwright.df33a2a.config.ts (eigene DB
 * database/e2e-df33a2a.sqlite, Port 8004).
 *
 * Die Assignment-Verwaltung hat in DF-3.3a2α bewusst keine Admin-UI, deshalb
 * werden Anlage und Aktivierung über die JSON-Routen der Administration
 * gefahren – mit derselben Session wie die UI-Schritte.
 */

const stamp = Date.now();
const headerKey = `df33a2a_head_${stamp}`;
const positionKey = `df33a2a_pos_${stamp}`;
const headerLabel = `A2a Kopf ${stamp}`;
const positionLabel = `A2a Element ${stamp}`;
const fieldSetKey = `df33a2a_set_${stamp}`;
const headerValue = `Kopfwert ${stamp}`;
const positionValue = `Elementwert ${stamp}`;

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

async function createCustomDefinition(
    page: Page,
    label: string,
    key: string,
    scope: 'header' | 'position',
) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#key').fill(key);
    await page.locator('#scope').selectOption(scope);
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

async function addCustomFieldToDraft(page: Page, fieldKey: string) {
    await expect(page.locator('[data-test="fieldset-draft-save"]')).toBeEnabled(
        {
            timeout: 15_000,
        },
    );
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

/**
 * Freies Feldset mit Kopf- und Positionsfeld anlegen, aktivieren und global
 * für Kalkulationen zuweisen. Wird pro Test frisch als Admin ausgeführt, weil
 * jeder Test einen eigenen Browser-Context (und damit eine eigene Session) hat.
 */
async function ensureGlobalAssignment(page: Page) {
    await login(page, 'admin@example.com');

    if (await isFieldSetAlreadyPrepared(page)) {
        return;
    }

    await createCustomDefinition(page, headerLabel, headerKey, 'header');
    await createCustomDefinition(page, positionLabel, positionKey, 'position');

    await page.goto('/administration/dynamische-felder/feldsets');
    await page.locator('[data-test="fieldset-create-link"]').click();
    await page
        .locator('[data-test="fieldset-name-input"]')
        .fill(`A2a Freies Set ${stamp}`);
    await page.locator('[data-test="fieldset-key-input"]').fill(fieldSetKey);
    await page
        .locator('[data-test="fieldset-applies-to-select"]')
        .selectOption('both');
    await page.locator('[data-test="fieldset-create-submit"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

    const fieldSetId = Number(/feldsets\/(\d+)/.exec(page.url())![1]);
    expect(fieldSetId).toBeGreaterThan(0);

    await page.locator('[data-test="fieldset-open-draft"]').click();
    await addCustomFieldToDraft(page, headerKey);
    await addCustomFieldToDraft(page, positionKey);

    await page.locator('a[href*="/vorschau"]').first().click();
    await expect(page).toHaveURL(/\/vorschau$/, { timeout: 15_000 });
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-test="fieldset-version-activate"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
    await expect(
        page.locator('[data-test="fieldset-usability-label"]'),
    ).toContainText('assignierbar', { timeout: 15_000 });

    const created = await postJson(
        page,
        '/administration/dynamische-felder/assignments',
        {
            field_set_id: fieldSetId,
            target_layer: 'global',
            applies_to_process: 'calculation',
        },
    );
    expect(created.status(), await created.text()).toBe(201);
    const assignment = (await created.json()).assignment;
    expect(assignment.is_active).toBe(false);

    const previewResponse = await postJson(
        page,
        `/administration/dynamische-felder/assignments/${assignment.id}/aktivierungs-vorschau`,
        {},
    );
    expect(previewResponse.status(), await previewResponse.text()).toBe(200);
    const preview = await previewResponse.json();
    expect(preview.has_blocking_conflicts).toBe(false);
    expect(preview.fingerprint).toBeTruthy();

    const activated = await postJson(
        page,
        `/administration/dynamische-felder/assignments/${assignment.id}/aktivieren`,
        {
            lock_version: assignment.lock_version,
            fingerprint: preview.fingerprint,
        },
    );
    expect(activated.status(), await activated.text()).toBe(200);
    expect((await activated.json()).assignment.is_active).toBe(true);
}

async function isFieldSetAlreadyPrepared(page: Page): Promise<boolean> {
    await page.goto('/administration/dynamische-felder/feldsets');

    return page.getByText(fieldSetKey).first().isVisible();
}

async function logout(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
        timeout: 15_000,
    });
}

test.describe.serial('DF-3.3a2α globaler Snapshot-Freeze', () => {
    test('global zugewiesene Custom-Felder erscheinen im Wizard und werden eingefroren', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        await ensureGlobalAssignment(page);
        await logout(page);
        await login(page, 'sales@example.com');

        await page.goto('/kalkulationen/neu');

        // Sichtbar vor dem Speichern: Kopffeld aus der globalen Zuweisung.
        await expect(
            page.locator('[data-test="calculation-custom-header-fields"]'),
        ).toBeVisible({ timeout: 30_000 });
        const headerField = page.locator(
            `[data-test="calc-custom-${headerKey}"]`,
        );
        await expect(headerField).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(headerLabel).first()).toBeVisible();
        await headerField.fill(headerValue);

        // Sichtbar vor dem Speichern: Positionsfeld am vorhandenen Werbeelement.
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page
                .locator('[data-test^="calculation-custom-position-fields-"]')
                .first(),
        ).toBeVisible({ timeout: 15_000 });
        const positionField = page
            .locator(
                `[data-test^="calc-pos-custom-"][data-test$="-${positionKey}"]`,
            )
            .first();
        await expect(positionField).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText(positionLabel).first()).toBeVisible();
        await positionField.fill(positionValue);

        await page.locator('[data-test="range-spots-0-0"]').fill('10');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        // Nach dem Speichern liest der Wizard aus dem eingefrorenen Snapshot.
        await page.reload();
        await expect(
            page.locator(`[data-test="calc-custom-${headerKey}"]`),
        ).toHaveValue(headerValue, { timeout: 30_000 });

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page
                .locator(
                    `[data-test^="calc-pos-custom-"][data-test$="-${positionKey}"]`,
                )
                .first(),
        ).toHaveValue(positionValue, { timeout: 15_000 });
    });

    test('veralteter schema_fingerprint wird beim Anlegen mit 409 abgelehnt', async ({
        page,
    }) => {
        test.setTimeout(120_000);

        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');

        // Inertia legt die Seiten-Props als JSON-Script neben dem Mount-Div ab.
        const catalog = await page.evaluate(() => {
            const script = document.querySelector(
                'script[data-page][type="application/json"]',
            );
            const payload = JSON.parse(script!.textContent!);

            return payload.props.catalog as {
                inventories: Array<{ id: number }>;
                media: Array<{ id: number }>;
            };
        });
        expect(catalog.inventories.length).toBeGreaterThan(0);
        expect(catalog.media.length).toBeGreaterThan(0);

        const schemaResponse = await postJson(
            page,
            '/kalkulationen/feldschema',
            {},
        );
        expect(schemaResponse.status(), await schemaResponse.text()).toBe(200);
        const schema = await schemaResponse.json();
        expect(schema.fieldSchema.schema_fingerprint).toBeTruthy();
        expect(schema.fieldSchema.format_version).toBe(
            schema.target_format_version,
        );

        const payload = (fingerprint: string) => ({
            planning_mode: 'manual',
            customer_name: `Drift ${stamp}`,
            agency_name: null,
            campaign: 'Drift',
            product_title: 'Drift',
            order_discount_percent: '0',
            ae_enabled: false,
            schema_fingerprint: fingerprint,
            dynamic_field_values: { campaign_period: null },
            positions: [
                {
                    inventory_id: catalog.inventories[0].id,
                    advertising_medium_id: catalog.media[0].id,
                    spot_method: 'average',
                    length_seconds: 30,
                    total_spot_count: 10,
                    position_discount_percent: '0',
                    ae_percent: '15',
                    plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
                    dynamic_field_values: {
                        period_open: true,
                        position_flight_period: null,
                    },
                },
            ],
        });

        // Drift schlägt als 409 durch, noch bevor Feldwerte geprüft werden.
        const drifted = payload('a'.repeat(64)) as Record<string, unknown>;
        (
            drifted.dynamic_field_values as Record<string, unknown>
        ).unknown_field = 'x';

        const conflict = await postJson(page, '/kalkulationen', drifted);
        expect(conflict.status()).toBe(409);
        expect((await conflict.json()).message).toContain('Feldkonfiguration');

        const accepted = await postJson(
            page,
            '/kalkulationen',
            payload(schema.fieldSchema.schema_fingerprint),
        );
        expect(accepted.status(), await accepted.text()).not.toBe(409);
    });
});
