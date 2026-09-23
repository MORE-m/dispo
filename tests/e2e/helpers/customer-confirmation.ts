import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';

export const CUSTOMER_CONFIRMATION_REASON =
    'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.';

export async function setCustomerConfirmationException(
    page: Page,
    reason: string = CUSTOMER_CONFIRMATION_REASON,
) {
    await expect(
        page.locator('[data-test="dispo-order-customer-confirmation"]'),
    ).toBeVisible({ timeout: 15_000 });

    if (
        (await page
            .locator('[data-test="customer-confirmation-readonly"]')
            .count()) > 0
    ) {
        return;
    }

    const checkbox = page.locator(
        '[data-test="customer-confirmation-without-upload"]',
    );
    await expect(checkbox).toBeVisible({ timeout: 15_000 });

    const state = await checkbox.getAttribute('data-state');
    if (state !== 'checked') {
        await checkbox.click();
    }

    const reasonField = page.locator(
        '[data-test="customer-confirmation-exception-reason"]',
    );
    await expect(reasonField).toBeVisible();
    const current = await reasonField.inputValue();
    if (current.trim() === reason.trim()) {
        // Bereits gesetzt und UI stabil (kein offenes Speichern nötig).
        await expect(
            page.locator('[data-test="customer-confirmation-save"]'),
        ).toBeDisabled({ timeout: 15_000 });
        return;
    }

    await reasonField.fill(reason);
    const save = page.locator('[data-test="customer-confirmation-save"]');
    await expect(save).toBeEnabled();
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().includes('/kundenbestaetigung') &&
                response.request().method() === 'PUT' &&
                response.ok(),
            { timeout: 15_000 },
        ),
        save.click(),
    ]);

    // Speichern löst router.visit aus — erst nach stabilem Reload fortfahren,
    // sonst remountet Inertia den Submit-Dialog (unstable/detached).
    await page.reload();
    await expect(
        page.locator('[data-test="dispo-order-customer-confirmation"]'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        page.locator('[data-test="customer-confirmation-exception-reason"]'),
    ).toHaveValue(reason, { timeout: 15_000 });
    await expect(
        page.locator('[data-test="customer-confirmation-save"]'),
    ).toBeDisabled({ timeout: 15_000 });
}

export async function approveWithExceptionAcknowledgement(page: Page) {
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await expect(
        page.locator('[data-test="dispo-order-approve-dialog"]'),
    ).toBeVisible();
    const ack = page.locator(
        '[data-test="customer-confirmation-exception-ack"]',
    );
    if ((await ack.count()) > 0) {
        await expect(
            page.locator('[data-test="dispo-order-approve-confirm"]'),
        ).toBeDisabled();
        await ack.click();
    }
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
}
