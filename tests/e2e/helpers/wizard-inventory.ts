import { expect, type Page } from '@playwright/test';

/**
 * PO-CALC-DISPO-UX-1: Frische manuelle Positionen starten ohne Inventar.
 * Bestehende E2E-Flows rufen dies auf, bevor Positionsfelder bedient werden.
 */
export async function ensureWizardInventorySelected(
    page: Page,
    positionIndex = 0,
    inventory: { code?: string; label?: string } = {
        code: 'RH',
        label: 'Radio Hamburg',
    },
): Promise<void> {
    const select = page.locator(
        `[data-test="position-inventory-${positionIndex}"]`,
    );
    await expect(select).toBeVisible({ timeout: 15_000 });

    const current = await select.inputValue();
    if (current !== '') {
        return;
    }

    if (inventory.code) {
        const tile = page.locator(
            `[data-test="position-inventory-tile-${positionIndex}-${inventory.code}"]`,
        );
        if ((await tile.count()) > 0) {
            await tile.click();
            await expect(select).not.toHaveValue('');
            return;
        }
    }

    if (inventory.label) {
        await select.selectOption({ label: inventory.label });
        await expect(select).not.toHaveValue('');
        return;
    }

    throw new Error(
        `Kein Inventar für Werbeelement ${positionIndex + 1} wählbar.`,
    );
}
