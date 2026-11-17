import { expect, type Page } from '@playwright/test';

/**
 * PO-CALC-DISPO-UX-1: Frische manuelle Positionen starten ohne Inventar.
 * Bestehende E2E-Flows rufen dies auf, bevor Positionsfelder bedient werden.
 *
 * Auswahl erfolgt über sichtbare Inventar-Kacheln (kein sr-only-Select).
 * Fehlende Optionen werden nicht stillschweigend übersprungen.
 */
export async function ensureWizardInventorySelected(
    page: Page,
    positionIndex = 0,
    inventory: { code?: string; label?: string } = {
        code: 'RH',
        label: 'Radio Hamburg',
    },
): Promise<void> {
    const picker = page.locator(
        `[data-test="position-inventory-picker-${positionIndex}"]`,
    );
    await expect(picker).toBeVisible({ timeout: 30_000 });

    const select = page.locator(
        `[data-test="position-inventory-${positionIndex}"]`,
    );
    await expect(select).toBeAttached({ timeout: 15_000 });

    const current = await select.inputValue();
    if (current !== '') {
        return;
    }

    if (inventory.code) {
        const tile = page.locator(
            `[data-test="position-inventory-tile-${positionIndex}-${inventory.code}"]`,
        );
        if ((await tile.count()) > 0) {
            await expect(tile).toBeEnabled({ timeout: 15_000 });
            await tile.click();
            await expect(select).not.toHaveValue('', { timeout: 15_000 });
            return;
        }
    }

    if (inventory.label) {
        const tileByName = page.getByRole('button', {
            name: inventory.label,
            exact: true,
        });
        if ((await tileByName.count()) > 0) {
            await expect(tileByName.first()).toBeEnabled({ timeout: 15_000 });
            await tileByName.first().click();
            await expect(select).not.toHaveValue('', { timeout: 15_000 });
            return;
        }
    }

    throw new Error(
        `Kein Inventar für Werbeelement ${positionIndex + 1} wählbar` +
            (inventory.code || inventory.label
                ? ` (gesucht: ${[inventory.code, inventory.label].filter(Boolean).join(' / ')})`
                : '') +
            '.',
    );
}
