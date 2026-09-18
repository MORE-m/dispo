import { expect, test, type Page } from '@playwright/test';

const monday = '2026-09-14';
const hour8 = 8;

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

async function openNewCalculationStepTwo(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.locator('#customer').fill('Tandem Tridem E2E GmbH');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function waitForCalculationPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 20_000,
    });
}

async function selectInventory(page: Page, name = 'Radio Hamburg') {
    await page.getByRole('button', { name, exact: true }).click();
}

async function selectMedium(page: Page, label: string) {
    const select = page.locator('[data-test="position-medium-0"]');
    await expect(select).toBeVisible();
    const value = await select.locator('option').evaluateAll(
        (options, wanted) => {
            const match = options.find((option) =>
                (option.textContent ?? '').includes(wanted),
            );

            return match?.getAttribute('value') ?? null;
        },
        label,
    );
    expect(value).toBeTruthy();
    await select.selectOption(String(value));
    await waitForCalculationPreview(page);
}

async function fillTandemLengths(page: Page) {
    await expect(
        page.locator('[data-test="spot-components-section-0"]'),
    ).toBeVisible();
    await page
        .locator('[data-test="spot-component-length-main_spot-1-0"]')
        .fill('20');
    await page
        .locator('[data-test="spot-component-length-reminder-2-0"]')
        .fill('10');
}

async function fillTridemLengths(page: Page) {
    await expect(
        page.locator('[data-test="spot-components-section-0"]'),
    ).toBeVisible();
    await page
        .locator('[data-test="spot-component-length-main_spot-1-0"]')
        .fill('20');
    await page
        .locator('[data-test="spot-component-length-reminder-2-0"]')
        .fill('10');
    await page
        .locator('[data-test="spot-component-length-reminder-3-0"]')
        .fill('10');
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

test.describe.serial('BL-P4-02e Tandem/Tridem', () => {
    test('Tandem Average: Preview, speichern, Reload', async ({ page }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tandem');
        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();

        await fillTandemLengths(page);

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
            page.locator('[data-test="spot-component-length-main_spot-1-0"]'),
        ).toHaveValue('20');
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toHaveValue('10');
    });

    test('Tridem Calendar: drei Längen, Zelle, speichern', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tridem');
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await fillTridemLengths(page);
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(`[data-test="planner-cell-spots-0-${monday}-${hour8}"]`)
            .fill('10');
        await waitForCalculationPreview(page);

        await expect(
            page.locator(
                `[data-test="planner-cell-gross-0-${monday}-${hour8}"]`,
            ),
        ).toContainText('760,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-component-length-reminder-3-0"]'),
        ).toHaveValue('10');
    });

    test('Festpreis + AE auf Tandem', async ({ page }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tandem');
        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();
        await fillTandemLengths(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-start-0-0"]').selectOption('8');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await page
            .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
            .check();
        await page.locator('[data-test="fixed-price-nn-0"]').fill('10000');
        await waitForCalculationPreview(page);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="ae-enabled"]').click();
        await waitForCalculationPreview(page);

        await expect(page.locator('[data-test="ae-deduction"]')).toContainText(
            '1.764,71',
        );

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="fixed-price-ae-amount-0"]'),
        ).toContainText('1.764,71');
        await expect(
            page.locator('[data-test="fixed-price-net-before-ae-0"]'),
        ).toContainText('11.764,71');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });
    });

    test('Mediumwechsel Tandem → Classic ohne verwaiste Reminder', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tandem');
        await fillTandemLengths(page);
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toBeVisible();

        await selectMedium(page, 'Spot Classic');
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toHaveCount(0);

        await page.locator('[data-test="spot-components-activate-0"]').click();
        await page
            .locator('[data-test="spot-component-length-main_spot-0"]')
            .fill('20');
        await page.locator('[data-test="spot-components-add-allonge-0"]').click();
        await page
            .locator('[data-test="spot-component-length-allonge-0"]')
            .fill('10');

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="spot-component-length-allonge-0"]'),
        ).toHaveValue('10');
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toHaveCount(0);
    });

    test('UI-Vertrag: keine Allonge, Strategie Gemeinsame Gesamtlänge', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tandem');

        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-components-add-allonge-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="spot-components-strategy-hint-0"]'),
        ).toContainText(/Gemeinsame Gesamtlänge/i);
        await expect(
            page.locator('[data-test="spot-components-units-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-component-length-main_spot-1-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-component-length-reminder-2-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="spot-component-length-reminder-3-0"]'),
        ).toHaveCount(0);
    });

    test('Dispo zeigt Komponenten und Profil', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page);
        await selectMedium(page, 'Spot Tandem');
        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();
        await fillTandemLengths(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

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
        ).toContainText('Reminder');
        await expect(
            page.locator('[data-test="dispo-order-position-components-0"]'),
        ).toContainText('Tandem');
    });
});
