import { expect, test, type Page } from '@playwright/test';

const monday = '2026-09-14';
const hour8 = 8;
const hour14 = 14;

async function login(page: Page, email = 'sales@example.com') {
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
    await page.context().clearCookies();
    await page.goto('/login');
    await expect(page.locator('#email')).toBeVisible({ timeout: 30_000 });
}

async function openNewCalculationStepTwo(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.locator('#customer').fill('Spot-Komponenten E2E GmbH');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function waitForCalculationPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 20_000,
    });
}

async function selectInventory(page: Page, name: string) {
    await page.getByRole('button', { name, exact: true }).click();
}

async function activateComponentsWithAllonge(page: Page) {
    await page.locator('[data-test="spot-components-activate-0"]').click();
    await expect(
        page.locator('[data-test="spot-components-section-0"]'),
    ).toBeVisible();
    await page.locator('[data-test="spot-component-length-main_spot-0"]').fill('20');
    await page.locator('[data-test="spot-components-add-allonge-0"]').click();
    await page.locator('[data-test="spot-component-length-allonge-0"]').fill('10');
}

async function ensureWeekContainsDate(page: Page, date: string) {
    const header = page.locator(`[data-test="planner-day-header-0-${date}"]`);
    if ((await header.count()) > 0) {
        return;
    }

    for (let i = 0; i < 60; i += 1) {
        await page.locator('[data-test="planner-week-prev-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    for (let i = 0; i < 120; i += 1) {
        await page.locator('[data-test="planner-week-next-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    await expect(header).toBeVisible();
}

async function setRuleStrategyIndividual(page: Page) {
    await login(page, 'admin@example.com');
    await page.goto('/administration/inventare');
    await page.getByRole('link', { name: 'Radio Hamburg Shared' }).click();
    await expect(page).toHaveURL(/\/administration\/inventare\/\d+$/);

    const strategySelect = page.locator(
        '[data-test^="inventory-medium-rule-strategy-"]',
    );
    await expect(strategySelect.first()).toBeVisible();

    const responsePromise = page.waitForResponse((response) => {
        const request = response.request();

        return (
            request.method() === 'PUT' &&
            /\/administration\/inventare\/\d+\/werbemittel-regeln\/\d+/.test(
                response.url(),
            )
        );
    });

    await strategySelect.first().selectOption('individual');
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    const payload = (await response.json()) as {
        rule?: { component_calculation_strategy?: string };
        lock_version?: number;
    };
    expect(payload.rule?.component_calculation_strategy).toBe('individual');
    expect(typeof payload.lock_version).toBe('number');
    expect(payload.lock_version as number).toBeGreaterThanOrEqual(1);

    await expect(strategySelect.first()).toHaveValue('individual');
    await logout(page);
}

test.describe.serial('BL-P4-02c Spot-Komponenten', () => {
    test('Average shared: Hauptspot + Allonge speichern und neu laden', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');

        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();
        await activateComponentsWithAllonge(page);

        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Gemeinsame Gesamtlänge');
        await expect(
            page.locator('[data-test="spot-components-total-length-0"]'),
        ).toContainText('30s');
        await expect(
            page.locator('[data-test="position-length-seconds-0"]'),
        ).toHaveCount(0);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-start-0-0"]').selectOption('8');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await expect(
            page.locator('[data-test="range-gross-0-0"]'),
        ).toContainText('600,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-allonge-0"]'),
        ).toHaveValue('10');
    });

    test('Calendar shared: zwei Zellen, Reload und Dispo', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await activateComponentsWithAllonge(page);
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');
        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour14}"]`)
            .fill('5');
        await waitForCalculationPreview(page);

        // shared: cell1 10×2×30×1=600, cell2 5×3×30×1=450 → 1050
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).toContainText('600,00');
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour14}"]`,
            ),
        ).toContainText('450,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, {
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-test="dispo-order-position-components-0"]'),
        ).toContainText('Hauptspot');
        await expect(
            page.locator('[data-test="dispo-order-position-components-0"]'),
        ).toContainText('Allonge');
    });

    test('Calendar Inventarwechsel behält Spotzahlen und rechnet Strategie neu', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Individual');

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await activateComponentsWithAllonge(page);
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Komponenten einzeln berechnen');
        await ensureWeekContainsDate(page, monday);

        const cell8 = page.locator(
            `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
        );
        const cell14 = page.locator(
            `[data-test="planner-cell-spots-0-${monday}-${hour14}"]`,
        );
        await cell8.fill('10');
        await cell14.fill('5');
        await waitForCalculationPreview(page);

        // individual: 10×2×20×1.05 + 10×2×10×1.10 = 420+220 = 640 at 08:00
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).toContainText('640,00');

        await selectInventory(page, 'Radio Hamburg Shared');
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Gemeinsame Gesamtlänge');
        await expect(cell8).toHaveValue('10');
        await expect(cell14).toHaveValue('5');
        await expect(page.getByText(/mindestens eine Stunde/i)).toHaveCount(0);
        await expect(
            page.getByText(/Mindestens ein vollständiger Kalendereintrag/i),
        ).toHaveCount(0);

        await waitForCalculationPreview(page);
        // shared: 10×2×30×1 = 600 at 08:00 — alter Individual-Preis 640 darf weg sein
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).toContainText('600,00');
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).not.toContainText('640,00');

        await selectInventory(page, 'Radio Hamburg Individual');
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Komponenten einzeln berechnen');
        await expect(cell8).toHaveValue('10');
        await expect(cell14).toHaveValue('5');
        await waitForCalculationPreview(page);
        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).toContainText('640,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Komponenten einzeln berechnen');
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-allonge-0"]'),
        ).toHaveValue('10');
        await expect(cell8).toHaveValue('10');
        await expect(cell14).toHaveValue('5');
    });

    test('Average individual: Inventar Individual, Einzelbruttos, Reload', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'sales@example.com');
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Individual');

        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();
        await activateComponentsWithAllonge(page);

        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Komponenten einzeln berechnen');

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-start-0-0"]').selectOption('8');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await expect(
            page.locator('[data-test="spot-component-result-main_spot-0"]'),
        ).toContainText('Index: 105');
        await expect(
            page.locator('[data-test="spot-component-result-main_spot-0"]'),
        ).toContainText('420,00');
        await expect(
            page.locator('[data-test="spot-component-result-allonge-0"]'),
        ).toContainText('Index: 110');
        await expect(
            page.locator('[data-test="spot-component-result-allonge-0"]'),
        ).toContainText('220,00');
        await expect(
            page.locator('[data-test="spot-components-position-gross-0"]'),
        ).toContainText('640,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText('Komponenten einzeln berechnen');
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-allonge-0"]'),
        ).toHaveValue('10');
    });

    test('Admin kann Komponentenstrategie speichern', async ({ page }) => {
        test.setTimeout(60_000);
        await setRuleStrategyIndividual(page);
    });
});
