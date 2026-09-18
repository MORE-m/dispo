import { expect, test, type Page } from '@playwright/test';

const monday = '2026-09-14';
const hour8 = 8;

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
    await page.locator('#customer').fill('Festpreis E2E GmbH');
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

async function selectAverageBasis(page: Page) {
    await page
        .locator('[data-test="calculation-method-radio-0-average"]')
        .check();
}

async function selectCalendarBasis(page: Page) {
    await page
        .locator('[data-test="calculation-method-radio-0-calendar"]')
        .check();
}

async function switchToFixedPrice(page: Page) {
    await page
        .locator('[data-test="pricing-settlement-radio-0-fixed_price"]')
        .check();
}

async function switchToNormalSettlement(page: Page) {
    await page
        .locator('[data-test="pricing-settlement-radio-0-normal"]')
        .check();
}

async function enterFixedPrice(page: Page, amount: string) {
    await page.locator('[data-test="fixed-price-nn-0"]').fill(amount);
    await waitForCalculationPreview(page);
}

test.describe.serial('BL-P4-02d Festpreis-Settlement', () => {
    test('Average: Festpreis eingeben, Preview, speichern, Reload', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-start-0-0"]').selectOption('8');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '250,00');

        // Seed: Stunde 8 = 2,00 €/s → Mediabrutto 2×30×10 = 600; Payfaktor 250/600.
        await expect(
            page.locator('[data-test="fixed-price-media-gross-0"]'),
        ).toContainText('600,00');
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText('250,00');
        await expect(
            page.locator('[data-test="fixed-price-pay-factor-0"]'),
        ).toContainText('41,6667');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toBeChecked();
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /250/,
        );
    });

    test('Average: normal → Festpreis → normal behält Zeiträume', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('12');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '200,00');
        await switchToNormalSettlement(page);
        await waitForCalculationPreview(page);

        await expect(page.locator('[data-test="range-spots-0-0"]')).toHaveValue(
            '12',
        );
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-normal"]'),
        ).toBeChecked();

        await switchToFixedPrice(page);
        await expect(page.locator('[data-test="fixed-price-nn-0"]')).toHaveValue(
            /200/,
        );    });

    test('Calendar: normal → Festpreis → Calendar behält Planner-Spots', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectCalendarBasis(page);
        await ensureWeekContainsDate(page, monday);

        const cell = page.locator(
            `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
        );
        await cell.fill('7');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '480,00');
        await switchToNormalSettlement(page);
        await waitForCalculationPreview(page);

        await expect(cell).toHaveValue('7');
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
    });

    test('Festpreis mit Hauptspot und Allonge', async ({ page }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);
        await activateComponentsWithAllonge(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('5');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '300,00');

        await expect(
            page.locator('[data-test="spot-components-section-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText('300,00');
    });

    test('Dispo zeigt historischen Festpreis', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('8');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '220,50');

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
            page.locator('[data-test="dispo-order-fixed-price-0"]'),
        ).toContainText('Festpreis (N/N)');
        await expect(
            page.locator('[data-test="dispo-order-fixed-price-0"]'),
        ).toContainText('220,50');
        await expect(
            page.locator('[data-test="dispo-order-fixed-price-0"]'),
        ).toContainText('Referenz-Mediabrutto');
    });

    test('Validierung: leerer Festpreis', async ({ page }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-spots-0-0"]').fill('4');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();

        await expect(page.locator('[data-test="preview-error"]')).toContainText(
            'Festpreis erfordert',
        );
    });

    test('Festpreis mit AE: positiver AE-Betrag, unverändertes Endinvest, Toggle', async ({
        page,
    }) => {
        await login(page);
        await openNewCalculationStepTwo(page);
        await selectInventory(page, 'Radio Hamburg Shared');
        await selectAverageBasis(page);

        if ((await page.locator('[data-test="range-spots-0-0"]').count()) === 0) {
            await page.locator('[data-test="range-add-0"]').click();
        }
        await page.locator('[data-test="range-start-0-0"]').selectOption('8');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await waitForCalculationPreview(page);

        await switchToFixedPrice(page);
        await enterFixedPrice(page, '10000,00');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="ae-enabled"]').click();
        await waitForCalculationPreview(page);

        await expect(page.locator('[data-test="ae-deduction"]')).toContainText(
            '1.764,71',
        );
        await expect(
            page.locator(
                '[data-test="step-3-totals"] [data-test="preview-net-total"]',
            ),
        ).toContainText('10.000,00');

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="fixed-price-nn-preview-0"]'),
        ).toContainText('10.000,00');
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

        await page.reload();
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await waitForCalculationPreview(page);
        await expect(page.locator('[data-test="ae-enabled"]')).toBeChecked();
        await expect(page.locator('[data-test="ae-deduction"]')).toContainText(
            '1.764,71',
        );
        await expect(
            page.locator(
                '[data-test="step-3-totals"] [data-test="preview-net-total"]',
            ),
        ).toContainText('10.000,00');

        await page.locator('[data-test="ae-enabled"]').click();
        await waitForCalculationPreview(page);
        await expect(page.locator('[data-test="ae-deduction"]')).toHaveCount(0);
        await expect(
            page.locator(
                '[data-test="step-3-totals"] [data-test="preview-net-total"]',
            ),
        ).toContainText('10.000,00');

        await page.locator('[data-test="ae-enabled"]').click();
        await waitForCalculationPreview(page);
        await expect(page.locator('[data-test="ae-deduction"]')).toContainText(
            '1.764,71',
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, {
            timeout: 30_000,
        });
        await expect(
            page.locator('[data-test="dispo-order-fixed-price-0"]'),
        ).toContainText('10.000,00');
        await expect(
            page.locator('[data-test="dispo-order-fixed-price-ae-0"]'),
        ).toContainText('1.764,71');
    });
});
