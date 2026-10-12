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

async function saveSimpleCalculation(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
}

async function openDispoCreate(page: Page) {
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(
        page.locator('[data-test="dispo-order-create-open"]'),
    ).toBeVisible({ timeout: 15_000 });
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await expect(
        page.locator('[data-test="dispo-order-create-dialog"]'),
    ).toBeVisible();
}

async function createDispoFromWizard(page: Page) {
    await openDispoCreate(page);
    await expect(
        page.locator('[data-test="dispo-order-create-isolation-hint"]'),
    ).toBeVisible({ timeout: 15_000 });
    const submit = page.locator('[data-test="dispo-order-submit"]');
    // Nach erstem Create sind Adopted-Positionen default abgewählt – dann „Alle“ setzen.
    if (await submit.isDisabled()) {
        await page.locator('[data-test="dispo-order-select-all"]').check();
    }
    await expect(submit).toBeEnabled({ timeout: 10_000 });
    await Promise.all([
        page.waitForURL(/dispoauftraege\/\d+/, { timeout: 20_000 }),
        submit.click(),
    ]);
}

test.describe('Calc Dispo Isolation Banner', () => {
    test('ohne Dispo kein Banner; nach Create singular; zweiter Create Plural; Reload; Snapshot stabil', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page, 'sales@example.com');
        await saveSimpleCalculation(page);
        const calculationUrl = page.url();

        await expect(
            page.locator('[data-test="calc-dispo-isolation-banner"]'),
        ).toHaveCount(0);

        await createDispoFromWizard(page);
        const firstOrderNumber = (
            await page.locator('h1').first().textContent()
        )?.trim();
        expect(firstOrderNumber).toMatch(/^DA-/);
        const firstNn = (
            await page.locator('[data-test="dispo-order-net-total"]').textContent()
        )?.trim();

        await page.goto(calculationUrl);
        await expect(
            page.locator('[data-test="calc-dispo-isolation-banner"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="calc-dispo-isolation-message"]'),
        ).toContainText(
            'Aus dieser Kalkulation wurde bereits ein Dispoauftrag erstellt. Änderungen an der Kalkulation ändern den bestehenden Dispoauftrag nicht.',
        );
        await expect(
            page.locator(
                `[data-test="calc-dispo-isolation-link-${firstOrderNumber}"]`,
            ),
        ).toBeVisible();

        await page.reload();
        await expect(
            page.locator('[data-test="calc-dispo-isolation-banner"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator(
                `[data-test="calc-dispo-isolation-link-${firstOrderNumber}"]`,
            ),
        ).toBeVisible();

        await createDispoFromWizard(page);
        const secondOrderNumber = (
            await page.locator('h1').first().textContent()
        )?.trim();
        expect(secondOrderNumber).toMatch(/^DA-/);
        expect(secondOrderNumber).not.toBe(firstOrderNumber);

        await page.goto(calculationUrl);
        await expect(
            page.locator('[data-test="calc-dispo-isolation-message"]'),
        ).toContainText(
            'Aus dieser Kalkulation wurden bereits Dispoaufträge erstellt. Änderungen an der Kalkulation ändern die bestehenden Dispoaufträge nicht.',
        );
        await expect(
            page.locator(
                `[data-test="calc-dispo-isolation-link-${firstOrderNumber}"]`,
            ),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="calc-dispo-isolation-link-${secondOrderNumber}"]`,
            ),
        ).toBeVisible();

        // Calc ändern und speichern – bestehender Dispo-Snapshot bleibt.
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-spots-0-0"]').fill('25');
        const saveResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'PUT' &&
                /kalkulationen\/\d+$/.test(
                    new URL(response.url()).pathname,
                ) &&
                response.status() >= 200 &&
                response.status() < 400,
        );
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await saveResponse;
        await expect(
            page.locator('[data-test="calc-dispo-isolation-banner"]'),
        ).toBeVisible();

        await page
            .locator(
                `[data-test="calc-dispo-isolation-link-${firstOrderNumber}"]`,
            )
            .click();
        await expect(page).toHaveURL(/dispoauftraege\/\d+/);
        await expect(
            page.locator('[data-test="dispo-order-net-total"]'),
        ).toHaveText(firstNn ?? '');
        await expect(
            page.locator('[data-test="dispo-order-source-calculation-isolation-hint"]'),
        ).toBeVisible();
    });

    test('Standardangebotsmodus zeigt kein Calc-Dispo-Banner', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'admin@example.com');
        await page.goto('/standardangebote/neu');
        await expect(page).toHaveURL(/standardangebote/, { timeout: 15_000 });
        await expect(
            page.locator('[data-test="standard-offer-scope-note"]'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(
            page.locator('[data-test="calc-dispo-isolation-banner"]'),
        ).toHaveCount(0);
    });
});
