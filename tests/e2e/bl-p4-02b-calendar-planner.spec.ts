import { expect, test, type Page } from '@playwright/test';

const monday = '2026-09-14';
const hour8 = 8;
const hour14 = 14;

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

async function selectCalendarAndLength(page: Page) {
    await page
        .locator('[data-test="calculation-method-radio-0-calendar"]')
        .check();
    await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
    await page.locator('[data-test="position-length-seconds-0"]').fill('30');
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

test.describe.serial('BL-P4-02b Kalenderplaner Wochenmatrix', () => {
    test('zeigt 7 Tage, zwei Zellen mit unterschiedlicher Stunde und Live-Brutto', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectCalendarAndLength(page);
        await ensureWeekContainsDate(page, monday);

        await expect(
            page.locator(`[data-test="planner-day-header-0-${monday}"]`),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="planner-day-header-0-2026-09-20"]'),
        ).toBeVisible();

        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            )
            .fill('10');
        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour14}"]`,
            )
            .fill('5');

        await waitForCalculationPreview(page);

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
        await expect(
            page.locator('[data-test="planner-position-gross-0"]'),
        ).toContainText('1.050,00');
        await expect(
            page.locator('[data-test="planner-total-spots-0"]'),
        ).toContainText('15');

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
        await ensureWeekContainsDate(page, monday);

        await expect(
            page.locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            ),
        ).toHaveValue('10');
        await expect(
            page.locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour14}"]`,
            ),
        ).toHaveValue('5');
        await expect(
            page.locator('[data-test="planner-week-label-0"]'),
        ).toBeVisible();
    });

    test('Wochen- und Monatsnavigation behält Einträge außerhalb der sichtbaren Woche', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectCalendarAndLength(page);
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            )
            .fill('7');

        const weekLabel = page.locator('[data-test="planner-week-label-0"]');
        const labelBefore = await weekLabel.textContent();

        await page.locator('[data-test="planner-month-next-0"]').click();
        await expect(weekLabel).not.toHaveText(labelBefore ?? '');
        await expect(
            page.locator('[data-test="planner-outside-week-0"]'),
        ).toContainText('7');
        await expect(
            page.locator('[data-test="planner-total-spots-0"]'),
        ).toContainText('7');

        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            ),
        ).toHaveValue('7');
    });

    test('zeigt Validierungsfehler bei ungültiger Spotanzahl in der Zelle', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectCalendarAndLength(page);
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            )
            .fill('-1');

        await waitForCalculationPreview(page);

        await expect(
            page.locator(`[data-test="planner-cell-0-${monday}-${hour8}"]`),
        ).toContainText('Die Spotanzahl darf nicht negativ sein.', {
            timeout: 15_000,
        });
    });

    test('übernimmt Kalenderverteilung in Dispoauftrag und zeigt sie chronologisch an', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectCalendarAndLength(page);
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-9"]`)
            .fill('4');

        await waitForCalculationPreview(page);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await expect(
            page.locator('[data-test="dispo-order-create-dialog"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-create-dialog"]'),
        ).toContainText(monday);
        await expect(
            page.locator('[data-test="dispo-order-create-dialog"]'),
        ).toContainText('4 Spots');

        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/dispoauftraege\/\d+/, {
            timeout: 30_000,
        });

        const plannerList = page.locator(
            '[data-test^="dispo-planner-entries-"]',
        );
        await expect(plannerList).toBeVisible();
        await expect(plannerList).toContainText(monday);
        await expect(plannerList).toContainText('09:00');
        await expect(plannerList).toContainText('4 Spots');
    });
});
