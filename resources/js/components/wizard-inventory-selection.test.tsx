import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { WizardInventorySelection } from '@/components/wizard-inventory-selection';

afterEach(() => {
    cleanup();
});

const inventories = [
    {
        id: 1,
        name: 'Radio Hamburg',
        code: 'RH',
        is_active: true,
        logo_path: null,
    },
    {
        id: 2,
        name: 'ROCK ANTENNE Hamburg',
        code: 'RAH',
        is_active: true,
        logo_path: null,
    },
    {
        id: 3,
        name: 'Online Audio E2E',
        code: 'ONLINE_E2E',
        is_active: true,
        logo_path: null,
    },
];

describe('WizardInventorySelection', () => {
    it('collapses to the selected inventory and can expand again', () => {
        const onSelect = vi.fn(() => true);
        render(
            <WizardInventorySelection
                positionIndex={0}
                inventories={inventories}
                selectedInventoryId={1}
                canEdit={true}
                unplannableInventoryIds={new Set([3])}
                catalogLabel={(name) => name}
                onSelectInventory={onSelect}
            />,
        );

        const picker = screen.getByTestId('position-inventory-picker-0');
        expect(picker.getAttribute('data-expanded')).toBe('false');
        expect(
            screen.getByTestId('position-inventory-collapsed-0'),
        ).toBeTruthy();
        expect(
            screen.queryByTestId('position-inventory-tile-0-RAH'),
        ).toBeTruthy();

        fireEvent.click(screen.getByTestId('position-inventory-expand-0'));
        expect(picker.getAttribute('data-expanded')).toBe('true');
        expect(
            screen.getByRole('button', { name: /ROCK ANTENNE Hamburg/i }),
        ).toBeTruthy();
    });

    it('applies a new inventory and collapses afterwards', () => {
        const onSelect = vi.fn(() => true);
        render(
            <WizardInventorySelection
                positionIndex={0}
                inventories={inventories}
                selectedInventoryId={1}
                canEdit={true}
                unplannableInventoryIds={new Set([3])}
                catalogLabel={(name) => name}
                onSelectInventory={onSelect}
            />,
        );

        fireEvent.click(screen.getByTestId('position-inventory-expand-0'));
        fireEvent.click(screen.getByTestId('position-inventory-tile-0-RAH'));
        expect(onSelect).toHaveBeenCalledWith(2);
    });

    it('does not select an unplannable inventory', () => {
        const onSelect = vi.fn(() => true);
        render(
            <WizardInventorySelection
                positionIndex={0}
                inventories={inventories}
                selectedInventoryId={1}
                canEdit={true}
                unplannableInventoryIds={new Set([3])}
                catalogLabel={(name) => name}
                onSelectInventory={onSelect}
            />,
        );

        fireEvent.click(screen.getByTestId('position-inventory-expand-0'));
        const online = screen.getByTestId(
            'position-inventory-tile-0-ONLINE_E2E',
        );
        expect(online).toBeDisabled();
        fireEvent.click(online);
        expect(onSelect).not.toHaveBeenCalled();
        expect(screen.getByText('Derzeit nicht kalkulierbar')).toBeTruthy();
    });
});
