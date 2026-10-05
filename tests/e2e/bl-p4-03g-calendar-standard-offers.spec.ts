import { expect, test, type Page } from '@playwright/test';

const monday = '2026-03-02';
const hour8 = 8;

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

async function ensureWeekContainsDate(page: Page, date: string) {
    const header = page.locator(`[data-test="planner-day-header-0-${date}"]`);
    if ((await header.count()) > 0) {
        return;
    }

    for (let i = 0; i < 80; i += 1) {
        await page.locator('[data-test="planner-week-prev-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    for (let i = 0; i < 160; i += 1) {
        await page.locator('[data-test="planner-week-next-0"]').click();
        if ((await header.count()) > 0) {
            return;
        }
    }

    await expect(header).toBeVisible();
}

test.describe.serial('BL-P4-03g Calendar-Standardangebote', () => {
    test('PM: Calendar-Vorlage speichern, reload, publish; Vertrieb adoptiert', async ({
        page,
    }) => {
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');
        await expect(
            page.locator('[data-test="standard-offer-scope-note"]'),
        ).toContainText('Calendar');

        await page.locator('[data-test="standard-offer-title"]').fill(
            'E2E Calendar Vorlage März 2026',
        );
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await expect(page.locator('[data-test="planner-grid-0"]')).toBeVisible();
        await page.locator('[data-test="position-price-year-0"]').selectOption('2026');
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            )
            .fill('4');

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
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
        ).toHaveValue('4');

        await page.locator('[data-test="standard-offer-publish"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });

        const offerUrl = page.url();

        await page.context().clearCookies();
        await login(page, 'sales@example.com');
        await page.goto(offerUrl);

        await expect(
            page.locator('[data-test="standard-offer-adopt-hint"]'),
        ).toContainText('gespeicherten Termine und Preise');

        await page
            .locator('[data-test="standard-offer-customer"]')
            .fill('E2E Adopt Kunde GmbH');
        await page.locator('[data-test="standard-offer-adopt"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });

        // Expliziter Reload der Kundenkalkulation nach Adopt (Persistenz-/Hydrate-Beleg).
        await page.reload();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+/, {
            timeout: 30_000,
        });
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toBeChecked();
        await ensureWeekContainsDate(page, monday);
        await expect(
            page.locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            ),
        ).toHaveValue('4');
    });

    test('Average-Regression im Vorlagenmodus', async ({ page }) => {
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');
        await page
            .locator('[data-test="standard-offer-title"]')
            .fill('E2E Average Regression');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeVisible();
        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();

        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await expect(
            page.locator('[data-test="time-range-0-0"]'),
        ).toBeVisible();

        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/standardangebote\/\d+/, {
            timeout: 30_000,
        });
        await page.getByRole('button', { name: '1. Grunddaten' }).click();
        await expect(
            page.locator('[data-test="standard-offer-title"]'),
        ).toHaveValue('E2E Average Regression');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();
    });

    test('Negativ: Calendar-Zelle mit Spotanzahl 0 wird verständlich abgewiesen', async ({
        page,
    }) => {
        await login(page, 'pm@example.com');
        await page.goto('/standardangebote/neu');
        await page
            .locator('[data-test="standard-offer-title"]')
            .fill('E2E Calendar Negativ');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page
            .locator('[data-test="calculation-method-radio-0-calendar"]')
            .check();
        await page.locator('[data-test="position-price-year-0"]').selectOption('2026');
        await ensureWeekContainsDate(page, monday);

        await page
            .locator(
                `[data-test="planner-cell-spots-0-${monday}-${hour8}"]`,
            )
            .fill('0');

        await page.locator('[data-test="wizard-save"]').click();

        await expect(
            page.getByText(/Spotanzahl|mindestens 1|erforderlich|ungültig/i).first(),
        ).toBeVisible({ timeout: 15_000 });
    });
});
