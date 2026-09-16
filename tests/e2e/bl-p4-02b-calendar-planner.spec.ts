import { expect, test, type Page } from '@playwright/test';

const plannerDate = '2026-09-14';

async function login(page: Page) {
    await page.goto('/login');
    await page.locator('#email').fill('sales@example.com');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

async function openNewCalculationStepTwo(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.locator('#customer').fill('Kalenderplaner E2E GmbH');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function waitForCalculationPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 20_000,
    });
}

test.describe.serial('BL-P4-02b Kalenderplaner', () => {
    test('Live-Summe entspricht Summe der Einzelzeilen und persistiert', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);

        await expect(
            page.locator('[data-test="calculation-method-fieldset-0"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(
            page.locator('[data-test="planner-entry-0-0"]'),
        ).toBeVisible();

        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await page
            .locator('[data-test="planner-date-0-0"]')
            .fill(plannerDate);
        await page.locator('[data-test="planner-hour-0-0"]').selectOption('8');
        await page.locator('[data-test="planner-spots-0-0"]').fill('10');

        await page.locator('[data-test="planner-add-0"]').click();
        await page
            .locator('[data-test="planner-date-0-1"]')
            .fill(plannerDate);
        await page.locator('[data-test="planner-hour-0-1"]').selectOption('14');
        await page.locator('[data-test="planner-spots-0-1"]').fill('5');

        await waitForCalculationPreview(page);

        await expect(
            page.locator('[data-test="planner-line-gross-0-0"]'),
        ).toContainText('600,00');
        await expect(
            page.locator('[data-test="planner-line-gross-0-1"]'),
        ).toContainText('450,00');
        await expect(
            page.locator('[data-test="planner-position-gross-0"]'),
        ).toContainText('1.050,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="planner-spots-0-0"]')).toHaveValue(
            '10',
        );
        await expect(page.locator('[data-test="planner-hour-0-1"]')).toHaveValue(
            '14',
        );
        await expect(page.locator('[data-test="planner-spots-0-1"]')).toHaveValue(
            '5',
        );
    });

    test('Monatsnavigation ändert Referenzwoche ohne Einträge zu verändern', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await page
            .locator('[data-test="planner-date-0-0"]')
            .fill(plannerDate);
        await page.locator('[data-test="planner-hour-0-0"]').selectOption('8');
        await page.locator('[data-test="planner-spots-0-0"]').fill('7');

        const weekLabel = page.locator('[data-test="planner-week-label-0"]');
        const labelBefore = await weekLabel.textContent();

        await page.locator('[data-test="planner-month-next-0"]').click();
        await expect(weekLabel).not.toHaveText(labelBefore ?? '');

        await expect(page.locator('[data-test="planner-date-0-0"]')).toHaveValue(
            plannerDate,
        );
        await expect(page.locator('[data-test="planner-spots-0-0"]')).toHaveValue(
            '7',
        );
    });

    test('zeigt Validierungsfehler bei ungültiger Spotanzahl', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await page
            .locator('[data-test="planner-date-0-0"]')
            .fill(plannerDate);
        await page.locator('[data-test="planner-hour-0-0"]').selectOption('8');
        await page.locator('[data-test="planner-spots-0-0"]').fill('-1');

        await waitForCalculationPreview(page);

        await expect(
            page.locator('[data-test="planner-entry-0-0"]'),
        ).toContainText('Die Spotanzahl darf nicht negativ sein.', {
            timeout: 15_000,
        });
    });
});
