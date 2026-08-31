import { expect, test, type Page } from '@playwright/test';

async function loginAsSales(page: Page) {
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

function waitForNextPreview(page: Page) {
    return page.waitForResponse(
        (response) =>
            response.url().includes('vorschau') &&
            response.request().method() === 'POST',
        { timeout: 15_000 },
    );
}

async function waitForCalculationPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 15_000,
    });
    await expect(
        page.locator('[data-test="preview-net-total"]').first(),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        page.locator('[data-test="preview-net-total"]').first(),
    ).toContainText(/€/);
}

test('Health-Endpunkt antwortet', async ({ request }) => {
    const response = await request.get('/health');
    expect(response.ok()).toBeTruthy();
    await expect(response.json()).resolves.toMatchObject({ status: 'ok' });
});

test('Startseite ist erreichbar', async ({ page }) => {
    await page.goto('/');
    await expect(
        page.getByRole('heading', { name: 'Kalkulation und Disposition' }),
    ).toBeVisible();
});

test('Kalkulationen erfordern Anmeldung', async ({ page }) => {
    await page.goto('/kalkulationen');
    await expect(page).toHaveURL(/login/);
});

test('Wizard markiert Kalkulationen in der Navigation als aktiv', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    const navLink = page.locator('[data-sidebar="menu-button"]', {
        hasText: 'Kalkulationen',
    });
    await expect(navLink).toHaveAttribute('aria-current', 'page');
    await expect(navLink).toHaveClass(/bg-primary/);
});

test('CAL-001 Mehrsender-Wizard mit Durchschnitt und Konditionen', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-end-0-0"]').selectOption('9');
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.locator('[data-test="position-length-seconds-0"]').fill('30');
    await waitForCalculationPreview(page);

    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.locator('[data-test="position-inventory-1"]').selectOption({
        label: 'ROCK ANTENNE Hamburg',
    });
    await page.locator('[data-test="range-start-1-0"]').selectOption('10');
    await page.locator('[data-test="range-end-1-0"]').selectOption('11');
    await page.locator('[data-test="range-spots-1-0"]').fill('5');
    await page.locator('[data-test="position-length-seconds-1"]').fill('20');
    await waitForCalculationPreview(page);

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await expect(
        page.locator('[data-test="position-inventory-0"] option:checked'),
    ).toHaveText('Radio Hamburg');
    await expect(
        page.locator('[data-test="position-inventory-1"] option:checked'),
    ).toHaveText('ROCK ANTENNE Hamburg');
});

test('BUD-008 Budgetvorschlag und serverseitige Übernahme', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByLabel('Mit Budget planen').check();
    await page.getByLabel('Zielbudget N/N').fill('500');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();

    await page.locator('[data-test="range-end-0-0"]').selectOption('9');
    await page.locator('[data-test="range-spots-0-0"]').fill('1');
    await waitForCalculationPreview(page);
    await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
    await page.locator('[data-test="position-inventory-1"]').selectOption({
        label: 'ROCK ANTENNE Hamburg',
    });
    await page.locator('[data-test="range-end-1-0"]').selectOption('9');
    await page.locator('[data-test="range-spots-1-0"]').fill('1');
    await waitForCalculationPreview(page);

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

    const url = page.url();
    const calculationId = url.match(/kalkulationen\/(\d+)/)?.[1];
    expect(calculationId).toBeTruthy();

    await page.getByRole('button', { name: '3. Konditionen' }).click();

    const proposeResponse = page.waitForResponse(
        (response) =>
            response.url().includes('budget-vorschlag') &&
            response.request().method() === 'POST',
    );
    await page.locator('[data-test="budget-propose"]').click();
    const proposalJson = await (await proposeResponse).json();
    const proposalId = proposalJson.proposal.id;
    expect(typeof proposalId).toBe('number');
    expect(proposalId).toBeGreaterThan(0);

    await expect(page.locator('[data-test="budget-apply"]')).toBeVisible();
    await page.locator('[data-test="budget-apply"]').click();
    await expect(page.locator('[data-test="budget-apply"]')).toHaveCount(0, {
        timeout: 15_000,
    });

    const cookies = await page.context().cookies();
    const xsrfCookie = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrfCookie).toBeTruthy();

    const secondApply = await page.request.post(
        `/kalkulationen/${calculationId}/budget-vorschlaege/${proposalId}/uebernehmen`,
        {
            maxRedirects: 0,
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie!.value),
            },
        },
    );
    expect(secondApply.status()).toBe(422);
});

test('Preiszeiträume, gestaffelte Rabatte und AE bleiben persistent', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');

    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="position-length-seconds-0"]').fill('30');
    await page.locator('[data-test="range-start-0-0"]').selectOption('8');
    await page.locator('[data-test="range-end-0-0"]').selectOption('12');
    await page.locator('[data-test="range-day-0-0"]').selectOption('mo_fr');
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.locator('[data-test="range-add-0"]').click();
    await page.locator('[data-test="range-start-0-1"]').selectOption('14');
    await page.locator('[data-test="range-end-0-1"]').selectOption('18');
    await page.locator('[data-test="range-day-0-1"]').selectOption('mo_fr');
    await page.locator('[data-test="range-spots-0-1"]').fill('20');
    await expect(page.locator('[data-test="position-total-spots-0"]')).toContainText(
        '30',
    );
    await expect(page.getByText('30 Spots')).toBeVisible();
    await waitForCalculationPreview(page);
    await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
        '300,00',
    );
    await expect(page.locator('[data-test="range-gross-0-1"]')).toContainText(
        '900,00',
    );

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.screenshot({
        path: 'docs/screenshots/wizard-step-2-time-ranges-desktop.png',
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: 'docs/screenshots/wizard-step-2-time-ranges-mobile.png',
    });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.locator('[data-test="positions.0.position_discounts-add"]').click();
    await page
        .locator('[data-test="positions.0.position_discounts-type-0"]')
        .selectOption('quantity');
    await page
        .locator('[data-test="positions.0.position_discounts-percent-0"]')
        .fill('10');
    await page.locator('[data-test="positions.0.position_discounts-add"]').click();
    await page
        .locator('[data-test="positions.0.position_discounts-type-1"]')
        .selectOption('special');
    await page
        .locator('[data-test="positions.0.position_discounts-percent-1"]')
        .fill('5');
    await page.locator('[data-test="order_discounts-add"]').click();
    await page.locator('[data-test="order_discounts-type-0"]').selectOption('quantity');
    const discountedPreview = waitForNextPreview(page);
    await page.locator('[data-test="order_discounts-percent-0"]').fill('10');
    await discountedPreview;
    await waitForCalculationPreview(page);
    await expect(
        page.locator('[data-test="preview-net-total"]').first(),
    ).not.toContainText('1.200,00');

    await expect(page.locator('[data-test="ae-enabled"]')).not.toBeChecked();
    await expect(page.getByText('Bruttoausgangswert')).toBeVisible();
    await expect(page.getByText('Bruttoausgangswert')).not.toContainText('–');
    await expect(page.locator('[data-test="preview-net-total"]').first()).toContainText(
        /€/,
    );
    await expect(page.locator('[data-test="ae-deduction"]')).toHaveCount(0);
    await expect(
        page.locator('[data-test="summary-position-total-0"]'),
    ).toContainText('1.026,00');
    await expect(
        page.locator('[data-test="summary-after-position-total"]'),
    ).toContainText('1.026,00');
    await expect(
        page.locator('[data-test="summary-order-discount-0"]'),
    ).toContainText('−102,60');
    await expect(
        page.locator('[data-test="summary-after-order-total"]'),
    ).toContainText('923,40');
    await expect(page.getByText('Rabatte Auftrag')).toHaveCount(0);
    await expect(page.getByText(/10\.0000/)).toHaveCount(0);

    await page.screenshot({
        path: 'docs/screenshots/wizard-step-3-conditions-desktop.png',
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page
        .locator('[data-test="calculation-summary"]')
        .evaluate((element) => element.scrollIntoView({ block: 'start' }));
    await expect(
        page.locator('[data-test="summary-after-order-total"]'),
    ).toBeVisible();
    await page.screenshot({
        path: 'docs/screenshots/wizard-step-3-conditions-mobile.png',
    });
    await page.setViewportSize({ width: 1440, height: 900 });

    const netWithoutAe = await page
        .locator('[data-test="preview-net-total"]')
        .first()
        .innerText();
    const aePreview = waitForNextPreview(page);
    await page.locator('[data-test="ae-enabled"]').check();
    await aePreview;
    await waitForCalculationPreview(page);
    await expect(page.locator('[data-test="ae-deduction"]')).toContainText(/€/);
    const netWithAe = await page
        .locator('[data-test="preview-net-total"]')
        .first()
        .innerText();
    expect(netWithAe).not.toBe(netWithoutAe);

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(page.getByText('Media-Brutto')).toBeVisible();

    const saveResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            /\/kalkulationen\/?$/.test(new URL(response.url()).pathname),
    );
    await page.getByRole('button', { name: 'Speichern' }).click();
    await saveResponse;
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
    await expect(page.getByText('Kalkulation gespeichert')).toBeVisible({
        timeout: 10_000,
    });

    await page.reload();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await expect(page.locator('[data-test="range-start-0-0"]')).toHaveValue('8');
    await expect(page.locator('[data-test="range-end-0-0"]')).toHaveValue('12');
    await expect(page.locator('[data-test="range-spots-0-0"]')).toHaveValue('10');
    await expect(page.locator('[data-test="range-start-0-1"]')).toHaveValue('14');
    await expect(page.locator('[data-test="range-end-0-1"]')).toHaveValue('18');
    await expect(page.locator('[data-test="range-spots-0-1"]')).toHaveValue('20');
    await expect(page.locator('[data-test="position-total-spots-0"]')).toContainText(
        '30',
    );

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await expect(
        page.locator('[data-test="positions.0.position_discounts-percent-0"]'),
    ).toHaveValue(/10/);
    await expect(
        page.locator('[data-test="positions.0.position_discounts-percent-1"]'),
    ).toHaveValue(/5/);
    await expect(page.locator('[data-test="order_discounts-percent-0"]')).toHaveValue(
        /10/,
    );
    await expect(page.locator('[data-test="ae-enabled"]')).toBeChecked();
});

test('Neue Kalkulation startet ohne AE und zeigt keinen AE-Abzug', async ({
    page,
}) => {
    await loginAsSales(page);
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await waitForCalculationPreview(page);

    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await expect(page.locator('[data-test="ae-enabled"]')).not.toBeChecked();
    await expect(page.locator('[data-test="ae-deduction"]')).toHaveCount(0);
});
