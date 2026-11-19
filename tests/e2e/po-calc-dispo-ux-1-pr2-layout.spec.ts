import { expect, test, type Page } from '@playwright/test';
import { ensureWizardInventorySelected } from './helpers/wizard-inventory';

/** PR2 layout contract; CI must verify sticky chat + closing order after SEC-NPM-AUDIT (#255) on main. */
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

async function createDraftDispoOrder(page: Page) {
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await ensureWizardInventorySelected(page);
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });
    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await expect(
        page.locator('[data-test="dispo-order-create-open"]'),
    ).toBeVisible({ timeout: 15_000 });
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });
}

async function expectClosingOrder(page: Page) {
    const confirmation = page.locator(
        '[data-test="dispo-order-customer-confirmation"]',
    );
    const material = page.locator('[data-test="dispo-order-material-upload"]');
    const uploads = page.locator('[data-test="dispo-order-uploads"]');
    const submit = page.locator('[data-test="dispo-order-submit-open"]');

    await expect(confirmation).toBeVisible();
    await expect(uploads).toBeVisible();
    await expect(submit).toBeVisible();

    const confirmationBox = await confirmation.boundingBox();
    const uploadsBox = await uploads.boundingBox();
    const submitBox = await submit.boundingBox();
    expect(confirmationBox).toBeTruthy();
    expect(uploadsBox).toBeTruthy();
    expect(submitBox).toBeTruthy();
    expect(confirmationBox!.y).toBeLessThan(uploadsBox!.y);
    expect(uploadsBox!.y).toBeLessThan(submitBox!.y);

    if ((await material.count()) > 0) {
        const materialBox = await material.boundingBox();
        expect(materialBox).toBeTruthy();
        expect(confirmationBox!.y).toBeLessThan(materialBox!.y);
        expect(materialBox!.y).toBeLessThan(uploadsBox!.y);
    }

    await expect(
        page.locator('[data-test="dispo-order-approval-actions-submit"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="dispo-order-submit-open"]'),
    ).toHaveCount(1);
}

test.describe('PO-CALC-DISPO-UX-1 PR2 Layout', () => {
    test('Desktop: sticky Kommunikation rechts und Endreihenfolge', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, 'sales@example.com');
        await createDraftDispoOrder(page);

        const layout = page.locator('[data-test="dispo-order-show-layout"]');
        await expect(layout).toHaveAttribute(
            'data-layout',
            'with-communication',
        );
        await expect(
            page.locator('[data-test="dispo-order-communication-aside"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-communication-history"]'),
        ).toBeVisible();

        const aside = page.locator(
            '[data-test="dispo-order-communication-aside"]',
        );
        await page
            .locator('[data-test="dispo-order-closing-block"]')
            .scrollIntoViewIfNeeded();
        await expect(aside).toBeInViewport();
        const after = await aside.boundingBox();
        expect(after).toBeTruthy();
        expect(after!.y).toBeLessThan(120);

        await expectClosingOrder(page);
        await expect(
            page.locator('[data-test="dispo-order-approve-open"]'),
        ).toHaveCount(0);
    });

    test('Mobile: Kommunikation im Fluss vor dem Endblock', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: 390, height: 844 });
        await login(page, 'sales@example.com');
        await createDraftDispoOrder(page);

        const communication = page.locator(
            '[data-test="dispo-order-communication-history"]',
        );
        const closing = page.locator('[data-test="dispo-order-closing-block"]');
        await expect(communication).toBeVisible();
        await expect(closing).toBeVisible();

        const communicationBox = await communication.boundingBox();
        const closingBox = await closing.boundingBox();
        expect(communicationBox).toBeTruthy();
        expect(closingBox).toBeTruthy();
        expect(communicationBox!.y).toBeLessThan(closingBox!.y);

        await expectClosingOrder(page);
        await expect(page.locator('body')).not.toHaveCSS(
            'overflow-x',
            'scroll',
        );
    });
});
