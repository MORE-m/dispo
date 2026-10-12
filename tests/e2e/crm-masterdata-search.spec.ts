import { expect, test, type Page } from '@playwright/test';

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

async function xsrfToken(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const raw = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;
    expect(raw).toBeTruthy();
    return decodeURIComponent(raw!);
}

async function createProvisionalViaApi(
    page: Page,
    name: string,
    type: 'customer' | 'agency' = 'customer',
    matchingDomain: string | null = null,
) {
    const response = await page.request.post('/crm/accounts/vorlaeufig', {
        data: {
            name,
            type,
            billing_email: null,
            matching_domain: matchingDomain,
        },
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': await xsrfToken(page),
        },
    });
    expect(
        response.ok(),
        `provisional create failed: ${response.status()} ${await response.text()}`,
    ).toBeTruthy();
    const body = (await response.json()) as {
        account: { id: number; name: string | null };
    };
    return body.account;
}

test.describe('CRM Stammdaten-Suche und Prüflisten-Labels', () => {
    test('Suche, Filter, Reset, Reload und verständliche Labels', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'admin@example.com');

        const unique = `Masterdata Suche ${Date.now()}`;
        const twinA = await createProvisionalViaApi(
            page,
            unique,
            'customer',
            'masterdata-a.test',
        );
        const twinB = await createProvisionalViaApi(
            page,
            unique,
            'customer',
            'masterdata-b.test',
        );
        await createProvisionalViaApi(page, 'Andere Agentur Suche', 'agency');

        for (let i = 0; i < 52; i++) {
            await createProvisionalViaApi(
                page,
                `Pagination Filler ${String(i).padStart(2, '0')}`,
                'agency',
            );
        }

        await page.goto('/crm/accounts');
        await expect(
            page.locator('[data-test="crm-accounts-index"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von');

        await page.locator('[data-test="crm-accounts-search"]').fill(unique);
        await page.locator('[data-test="crm-accounts-search-submit"]').click();
        await expect(page).toHaveURL(/q=/);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="crm-account-row-${twinB.id}"]`),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toContainText('masterdata-a.test');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinB.id}"]`),
        ).toContainText('masterdata-b.test');
        await expect(
            page.locator(`[data-test="crm-account-row-${twinA.id}"]`),
        ).toContainText('Meridian-Nummer folgt');

        await page.reload();
        await expect(
            page.locator('[data-test="crm-accounts-search"]'),
        ).toHaveValue(unique);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');

        await page.goto(
            `/crm/accounts?q=${encodeURIComponent(unique)}&type=customer&provisional=1`,
        );
        await expect(page).toHaveURL(/type=customer/);
        await expect(page).toHaveURL(/provisional=1/);
        await expect(
            page.locator('[data-test="crm-accounts-result-summary"]'),
        ).toContainText('von 2');
        await expect(
            page.locator('[data-test="crm-accounts-type-filter"]'),
        ).toHaveValue('customer');
        await expect(
            page.locator('[data-test="crm-accounts-provisional-filter"]'),
        ).toBeChecked();

        await page.locator('[data-test="crm-accounts-search-reset"]').click();
        await expect(page).not.toHaveURL(/[?&]q=/);
        await expect(page).toHaveURL(/type=customer/);
        await expect(page).toHaveURL(/provisional=1/);
        await expect(
            page.locator('[data-test="crm-accounts-search"]'),
        ).toHaveValue('');

        await page.goto('/crm/accounts?type=agency');
        await expect(
            page.locator('[data-test="crm-accounts-pagination"]'),
        ).toBeVisible({ timeout: 15_000 });
        await page.locator('[data-test="crm-accounts-page-next"]').click();
        await expect(page).toHaveURL(/page=2/);
        await expect(
            page.locator('[data-test="crm-accounts-pagination"]'),
        ).toContainText('Seite 2');

        await page.goto('/crm/accounts/pruefliste');
        await expect(page.locator('[data-test="crm-match-queue"]')).toBeVisible();
        const source = page.locator(
            `[data-test="crm-account-label-source-${twinA.id}"]`,
        );
        await expect(source).toBeVisible();
        await expect(source).toContainText(unique);
        await expect(source).toContainText('Kunde · vorläufig');
        await expect(source).toContainText('masterdata-a.test');
        await expect(source).toContainText('Meridian-Nummer folgt');
        await expect(source).not.toContainText(`ID ${twinA.id}`);

        await page
            .locator(`[data-test="crm-manual-search-${twinA.id}"]`)
            .fill('GibtsNichtXYZ');
        await page
            .locator(`[data-test="crm-manual-search-btn-${twinA.id}"]`)
            .click();
        await expect(
            page.locator(`[data-test^="crm-manual-link-${twinA.id}-"]`),
        ).toHaveCount(0);
    });
});
