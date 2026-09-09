import {
    expect,
    test,
    type APIRequestContext,
    type Page,
} from '@playwright/test';

/**
 * DF-3.3b isolierte Suite: Assignment-Admin-UI mit Herkunft/Konflikten.
 * Läuft nur über playwright.df33b.config.ts (eigene DB, Port 8006).
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

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const token = await page.evaluate(() => {
        const row = document.cookie
            .split('; ')
            .find((part) => part.startsWith('XSRF-TOKEN='));
        return row ? decodeURIComponent(row.slice(11)) : '';
    });

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token,
    };
}

async function postJson(
    request: APIRequestContext,
    page: Page,
    url: string,
    body: Record<string, unknown> = {},
) {
    return request.post(url, {
        headers: await csrfHeaders(page),
        data: body,
    });
}

async function putJson(
    request: APIRequestContext,
    page: Page,
    url: string,
    body: Record<string, unknown> = {},
) {
    return request.put(url, {
        headers: await csrfHeaders(page),
        data: body,
    });
}

async function createCustomDefinition(
    page: Page,
    label: string,
    scope: 'header' | 'position' = 'header',
) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#scope').selectOption(scope);
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

async function createAssignableFieldSet(
    page: Page,
    name: string,
    key: string,
    definitionLabel: string,
    options: {
        requiredOverride?: '' | '0' | '1';
        appliesTo?: 'calculation' | 'dispo_order' | 'both';
    } = {},
): Promise<number> {
    const requiredOverride = options.requiredOverride ?? '';
    const appliesTo = options.appliesTo ?? 'both';

    await page.goto('/administration/dynamische-felder/feldsets');
    await page.locator('[data-test="fieldset-create-link"]').click();
    await page.locator('[data-test="fieldset-name-input"]').fill(name);
    await page.locator('[data-test="fieldset-key-input"]').fill(key);
    await page
        .locator('[data-test="fieldset-applies-to-select"]')
        .selectOption(appliesTo);
    await page.locator('[data-test="fieldset-create-submit"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
    const fieldSetId = Number(page.url().match(/feldsets\/(\d+)/)?.[1]);

    await page.locator('[data-test="fieldset-open-draft"]').click();
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
        .filter({ hasText: definitionLabel })
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

    if (requiredOverride !== '') {
        const requiredSelect = page
            .locator('[data-test^="membership-required-"]')
            .first();
        await requiredSelect.selectOption(requiredOverride);
        await page.locator('[data-test="fieldset-draft-save"]').click();
        await expect(
            page.locator('[data-test="fieldset-draft-save"]'),
        ).toBeEnabled({
            timeout: 15_000,
        });
    }

    await page.locator('a[href*="/vorschau"]').first().click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-test="fieldset-version-activate"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

    return fieldSetId;
}

test.describe.serial('DF-3.3b Assignment-Admin-UI', () => {
    test('reines dispo_order-Feldset initialisiert Prozess korrekt und legt ohne 422 an', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        const suffix = Date.now().toString().slice(-6);
        const definitionLabel = `Dispo Only Feld ${suffix}`;
        const fieldSetKey = `aaa_dispo_only_${suffix}`;

        await createCustomDefinition(page, definitionLabel, 'header');
        await createAssignableFieldSet(
            page,
            `Dispo Only ${suffix}`,
            fieldSetKey,
            definitionLabel,
            { appliesTo: 'dispo_order' },
        );

        await page.goto('/administration/dynamische-felder/assignments/neu');
        await expect(
            page.locator('[data-test="assignment-create-form"]'),
        ).toBeVisible();

        const fieldSetSelect = page.locator(
            '[data-test="assignment-fieldset-select"]',
        );
        await expect(fieldSetSelect.locator('option')).toHaveCount(1);
        await expect(fieldSetSelect).toHaveValue(/.+/);

        const processSelect = page.locator(
            '[data-test="assignment-process-select"]',
        );
        await expect(processSelect).toHaveValue('dispo_order');
        await expect(processSelect.locator('option')).toHaveCount(1);
        await expect(processSelect.locator('option')).toHaveText(
            'Dispoauftrag',
        );

        const createResponsePromise = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .includes(
                        '/administration/dynamische-felder/assignments',
                    ) && response.request().method() === 'POST',
        );

        await page.locator('[data-test="assignment-create-submit"]').click();
        const createResponse = await createResponsePromise;
        expect(createResponse.status()).toBe(201);
        const requestBody = createResponse.request().postDataJSON() as {
            applies_to_process?: string;
        };
        expect(requestBody.applies_to_process).toBe('dispo_order');
        const body = await createResponse.json();
        expect(body.assignment.applies_to_process).toBe('dispo_order');
        expect(body.assignment.is_active).toBe(false);

        await expect(page).toHaveURL(/assignments\/\d+$/, { timeout: 30_000 });
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Inaktiv');
        await expect(
            page.locator('[data-test="assignment-create-error"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="assignment-show-error"]'),
        ).toHaveCount(0);
    });

    test('Feldset-Wechsel korrigiert den Prozesszustand', async ({ page }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);
        const definitionLabel = `Switch Feld ${suffix}`;

        await createCustomDefinition(page, definitionLabel, 'header');
        const bothId = await createAssignableFieldSet(
            page,
            `Switch Both ${suffix}`,
            `sw_both_${suffix}`,
            definitionLabel,
            { appliesTo: 'both' },
        );
        const calcId = await createAssignableFieldSet(
            page,
            `Switch Calc ${suffix}`,
            `sw_calc_${suffix}`,
            definitionLabel,
            { appliesTo: 'calculation' },
        );
        const dispoId = await createAssignableFieldSet(
            page,
            `Switch Dispo ${suffix}`,
            `sw_dispo_${suffix}`,
            definitionLabel,
            { appliesTo: 'dispo_order' },
        );

        await page.goto('/administration/dynamische-felder/assignments/neu');
        const fieldSetSelect = page.locator(
            '[data-test="assignment-fieldset-select"]',
        );
        const processSelect = page.locator(
            '[data-test="assignment-process-select"]',
        );

        await fieldSetSelect.selectOption(String(bothId));
        await processSelect.selectOption('calculation');
        await expect(processSelect).toHaveValue('calculation');

        // both → dispo_order
        await fieldSetSelect.selectOption(String(dispoId));
        await expect(processSelect).toHaveValue('dispo_order');
        await expect(processSelect.locator('option')).toHaveCount(1);

        // dispo_order → both (dispo_order bleibt zulässig)
        await fieldSetSelect.selectOption(String(bothId));
        await expect(processSelect).toHaveValue('dispo_order');
        await expect(processSelect.locator('option')).toHaveCount(3);

        // calculation → dispo_order
        await fieldSetSelect.selectOption(String(calcId));
        await expect(processSelect).toHaveValue('calculation');
        await expect(processSelect.locator('option')).toHaveCount(1);
        await fieldSetSelect.selectOption(String(dispoId));
        await expect(processSelect).toHaveValue('dispo_order');

        // both mit Prozess „both“ → dispo_order muss korrigieren
        await fieldSetSelect.selectOption(String(bothId));
        await processSelect.selectOption('both');
        await fieldSetSelect.selectOption(String(dispoId));
        await expect(processSelect).toHaveValue('dispo_order');
    });

    test('Happy Path inkl. Medium-Ziel, Herkunft, Activate/Deactivate und API-409', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');

        const suffix = Date.now().toString().slice(-6);
        const definitionLabel = `Assign Hinweis ${suffix}`;
        const fieldSetKey = `assign_admin_${suffix}`;

        await createCustomDefinition(page, definitionLabel, 'position');
        const fieldSetId = await createAssignableFieldSet(
            page,
            `Assign Admin ${suffix}`,
            fieldSetKey,
            definitionLabel,
        );

        await page.goto('/administration/dynamische-felder/assignments');
        await page.locator('[data-test="assignment-create-link"]').click();

        await expect(
            page.locator('[data-test="assignment-catalog-note"]'),
        ).toContainText('Katalog-Admin');
        await page
            .locator('[data-test="assignment-fieldset-select"]')
            .selectOption(String(fieldSetId));
        await page
            .locator('[data-test="assignment-target-layer-select"]')
            .selectOption('advertising_medium');
        await page
            .locator('[data-test="assignment-medium-select"]')
            .selectOption({ index: 0 });
        await page
            .locator('[data-test="assignment-process-select"]')
            .selectOption('calculation');
        await page.locator('[data-test="assignment-sort-input"]').fill('3');

        await Promise.all([
            page.waitForURL(/assignments\/\d+$/, { timeout: 30_000 }),
            page.locator('[data-test="assignment-create-submit"]').click(),
        ]);

        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Inaktiv');

        await page
            .locator('[data-test="assignment-context-scope-select"]')
            .selectOption('position');
        await page
            .locator('[data-test="assignment-context-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-context-preview"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator(
                '[data-test="assignment-context-preview-conflict-badge"]',
            ),
        ).toContainText('Konfliktfrei');

        await page
            .locator('[data-test="assignment-activation-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-activation-fingerprint"]'),
        ).toBeVisible({ timeout: 15_000 });
        await page.locator('[data-test="assignment-activate-button"]').click();
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Aktiv', { timeout: 15_000 });

        page.once('dialog', (dialog) => dialog.accept());
        await page
            .locator('[data-test="assignment-deactivate-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Inaktiv', { timeout: 15_000 });

        await page
            .locator('[data-test="assignment-activation-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-activation-fingerprint"]'),
        ).toBeVisible({ timeout: 15_000 });

        const assignmentId = page.url().match(/assignments\/(\d+)/)?.[1];
        expect(assignmentId).toBeTruthy();
        const stale = await postJson(
            page.request,
            page,
            `/administration/dynamische-felder/assignments/${assignmentId}/aktivieren`,
            {
                lock_version: 1,
                fingerprint: 'a'.repeat(64),
            },
        );
        expect(stale.status()).toBe(409);
    });

    test('UI-gesteuerter 409 bei Activate nach veraltetem Stand', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);
        const definitionLabel = `UI409 Feld ${suffix}`;

        await createCustomDefinition(page, definitionLabel, 'header');
        const fieldSetId = await createAssignableFieldSet(
            page,
            `UI409 Set ${suffix}`,
            `ui409_${suffix}`,
            definitionLabel,
            { appliesTo: 'both' },
        );

        const create = await postJson(
            page.request,
            page,
            '/administration/dynamische-felder/assignments',
            {
                field_set_id: fieldSetId,
                target_layer: 'global',
                applies_to_process: 'calculation',
                sort: 1,
            },
        );
        expect(create.ok()).toBeTruthy();
        const created = await create.json();
        const assignmentId = created.assignment.id as number;
        const lockVersion = created.assignment.lock_version as number;

        await page.goto(
            `/administration/dynamische-felder/assignments/${assignmentId}`,
        );
        await page
            .locator('[data-test="assignment-activation-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-activation-fingerprint"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="assignment-activate-button"]'),
        ).toBeEnabled();

        const bump = await putJson(
            page.request,
            page,
            `/administration/dynamische-felder/assignments/${assignmentId}`,
            {
                lock_version: lockVersion,
                sort: 99,
            },
        );
        expect(
            bump.ok(),
            `lock bump failed: ${bump.status()} ${await bump.text()}`,
        ).toBeTruthy();

        await page.locator('[data-test="assignment-activate-button"]').click();
        await expect(
            page.locator('[data-test="assignment-show-error"]'),
        ).toBeVisible({
            timeout: 15_000,
        });
        await expect(
            page.locator('[data-test="assignment-show-error"]'),
        ).toContainText(
            /Aktivierungsvorschau erneut laden|parallel geändert|Fingerprint/i,
        );
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Inaktiv');
        await expect(
            page.locator('[data-test="assignment-activation-fingerprint"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="assignment-activate-button"]'),
        ).toBeDisabled();

        await page
            .locator('[data-test="assignment-activation-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-activation-fingerprint"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="assignment-activate-button"]'),
        ).toBeEnabled();
        await page.locator('[data-test="assignment-activate-button"]').click();
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Aktiv', { timeout: 15_000 });
    });

    test('Konflikt blockiert Activate; Nicht-Admin erhält 403', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);
        const sharedLabel = `Konfliktfeld ${suffix}`;

        await createCustomDefinition(page, sharedLabel, 'header');
        const setAId = await createAssignableFieldSet(
            page,
            `Konflikt Set A ${suffix}`,
            `conf_a_${suffix}`,
            sharedLabel,
            { requiredOverride: '1' },
        );
        const setBId = await createAssignableFieldSet(
            page,
            `Konflikt Set B ${suffix}`,
            `conf_b_${suffix}`,
            sharedLabel,
            { requiredOverride: '0' },
        );

        const createA = await postJson(
            page.request,
            page,
            '/administration/dynamische-felder/assignments',
            {
                field_set_id: setAId,
                target_layer: 'global',
                applies_to_process: 'calculation',
                sort: 1,
            },
        );
        expect(createA.ok()).toBeTruthy();
        const bodyA = await createA.json();

        const previewA = await postJson(
            page.request,
            page,
            `/administration/dynamische-felder/assignments/${bodyA.assignment.id}/aktivierungs-vorschau`,
        );
        const previewABody = await previewA.json();
        const activateA = await postJson(
            page.request,
            page,
            `/administration/dynamische-felder/assignments/${bodyA.assignment.id}/aktivieren`,
            {
                lock_version: bodyA.assignment.lock_version,
                fingerprint: previewABody.fingerprint,
            },
        );
        expect(activateA.ok()).toBeTruthy();

        const createB = await postJson(
            page.request,
            page,
            '/administration/dynamische-felder/assignments',
            {
                field_set_id: setBId,
                target_layer: 'global',
                applies_to_process: 'calculation',
                sort: 2,
            },
        );
        expect(createB.ok()).toBeTruthy();
        const bodyB = await createB.json();

        await page.goto(
            `/administration/dynamische-felder/assignments/${bodyB.assignment.id}`,
        );
        await page
            .locator('[data-test="assignment-activation-preview-button"]')
            .click();
        await expect(
            page.locator('[data-test="assignment-activation-blocked"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="assignment-activate-button"]'),
        ).toBeDisabled();

        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        const forbidden = await page.goto(
            '/administration/dynamische-felder/assignments',
        );
        expect(forbidden?.status()).toBe(403);
    });
});
