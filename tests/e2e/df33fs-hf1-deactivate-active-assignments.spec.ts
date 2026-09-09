import {
    expect,
    test,
    type APIRequestContext,
    type Page,
} from '@playwright/test';

/**
 * DF-3.3-fs-HF1: Feldset-Deaktivierung bei aktiven Assignments.
 * Läuft über playwright.df33fs.config.ts (testMatch df33fs-*.spec.ts).
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

async function createCustomDefinition(page: Page, label: string) {
    await page.goto('/administration/dynamische-felder/definitionen/neu');
    await page.locator('#label').fill(label);
    await page.locator('#scope').selectOption('header');
    await page.locator('#applies_to').selectOption('both');
    await page.locator('[data-test="custom-field-definition-submit"]').click();
    await expect(page).toHaveURL(/definitionen\/\d+$/, { timeout: 30_000 });
}

async function createAssignableFieldSet(
    page: Page,
    name: string,
    key: string,
    definitionLabel: string,
): Promise<number> {
    await page.goto('/administration/dynamische-felder/feldsets');
    await page.locator('[data-test="fieldset-create-link"]').click();
    await page.locator('[data-test="fieldset-name-input"]').fill(name);
    await page.locator('[data-test="fieldset-key-input"]').fill(key);
    await page.locator('[data-test="fieldset-applies-to-select"]').selectOption('both');
    await page.locator('[data-test="fieldset-create-submit"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });
    const fieldSetId = Number(page.url().match(/feldsets\/(\d+)/)?.[1]);

    await page.locator('[data-test="fieldset-open-draft"]').click();
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

    await page.locator('a[href*="/vorschau"]').first().click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-test="fieldset-version-activate"]').click();
    await expect(page).toHaveURL(/feldsets\/\d+$/, { timeout: 30_000 });

    return fieldSetId;
}

test.describe('DF-3.3-fs-HF1 Feldset-Deaktivierung', () => {
    test('aktive Assignments blockieren Deaktivierung; danach freigegeben', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await login(page, 'admin@example.com');

        const suffix = Date.now().toString().slice(-6);
        const definitionLabel = `HF1 Feld ${suffix}`;
        const fieldSetKey = `hf1_fs_${suffix}`;

        await createCustomDefinition(page, definitionLabel);
        const fieldSetId = await createAssignableFieldSet(
            page,
            `HF1 Set ${suffix}`,
            fieldSetKey,
            definitionLabel,
        );

        await page.goto('/administration/dynamische-felder/assignments/neu');
        await page
            .locator('[data-test="assignment-fieldset-select"]')
            .selectOption(String(fieldSetId));
        await page
            .locator('[data-test="assignment-process-select"]')
            .selectOption('calculation');
        await page.locator('[data-test="assignment-create-submit"]').click();
        await expect(page).toHaveURL(/assignments\/\d+$/, { timeout: 30_000 });

        const assignmentId = Number(page.url().match(/assignments\/(\d+)/)?.[1]);
        expect(assignmentId).toBeGreaterThan(0);

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

        await page.goto(
            `/administration/dynamische-felder/feldsets/${fieldSetId}`,
        );
        await expect(
            page.locator('[data-test="fieldset-active-assignment-count"]'),
        ).toContainText('1 aktiv');
        await expect(
            page.locator('[data-test="fieldset-deactivate-blocker"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="fieldset-deactivate-blocked"]'),
        ).toBeDisabled();
        await expect(
            page.locator('[data-test="fieldset-deactivate"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="fieldset-active-assignment-link"]'),
        ).toHaveAttribute('href', new RegExp(`/assignments/${assignmentId}$`));

        const headerBlock = await page.locator('body').innerText();
        const lockMatch = /Sperrversion\s+(\d+)/.exec(headerBlock);
        const lockVersion = Number(lockMatch?.[1] ?? 0);
        expect(lockVersion).toBeGreaterThan(0);

        const conflict = await postJson(
            page.request,
            page,
            `/administration/dynamische-felder/feldsets/${fieldSetId}/deaktivieren`,
            { lock_version: lockVersion },
        );
        expect(conflict.status()).toBe(422);
        const conflictBody = await conflict.json();
        expect(JSON.stringify(conflictBody.errors ?? {})).toContain(
            'aktive Assignments',
        );

        await page.goto(
            `/administration/dynamische-felder/assignments/${assignmentId}`,
        );
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('[data-test="assignment-deactivate-button"]').click();
        await expect(
            page.locator('[data-test="assignment-status-badge"]'),
        ).toContainText('Inaktiv', { timeout: 15_000 });

        await page.goto(
            `/administration/dynamische-felder/feldsets/${fieldSetId}`,
        );
        await expect(
            page.locator('[data-test="fieldset-active-assignment-count"]'),
        ).toContainText('0 aktiv');
        await expect(
            page.locator('[data-test="fieldset-deactivate"]'),
        ).toBeEnabled();
        await page.locator('[data-test="fieldset-deactivate"]').click();
        await expect(
            page.locator('[data-test="fieldset-usability-label"]'),
        ).toContainText('deaktiviert', { timeout: 15_000 });
    });
});
