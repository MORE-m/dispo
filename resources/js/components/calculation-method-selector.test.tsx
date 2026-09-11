import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CalculationMethodSelector } from '@/components/calculation-method-selector';
import type { CalculationMethodOptions } from '@/lib/calculation-method-draft';

afterEach(() => {
    cleanup();
});

const multi: CalculationMethodOptions = {
    medium_id: 1,
    source: 'category',
    default_calculation_method_key: 'average',
    methods: [
        {
            key: 'average',
            name: 'Durchschnitt',
            help_text: 'Hilfe A',
            is_default: true,
        },
        {
            key: 'calendar',
            name: 'Kalender',
            help_text: 'Hilfe B',
            is_default: false,
        },
    ],
};

describe('CalculationMethodSelector (ADV-001c4b)', () => {
    it('renders multiple options with legend and selection text', () => {
        const onSelect = vi.fn();
        render(
            <CalculationMethodSelector
                positionIndex={0}
                options={multi}
                state={{
                    calculation_method_key: 'average',
                    calculation_method_name: 'Durchschnitt',
                    historical_calculation_method_key: null,
                    historical_calculation_method_name: null,
                }}
                onSelectLiveKey={onSelect}
            />,
        );

        expect(
            screen.getByRole('group', { name: 'Berechnungsmethode' }),
        ).toBeTruthy();
        expect(screen.getByLabelText(/Durchschnitt/)).toBeChecked();
        expect(screen.getByText('Ausgewählt')).toBeTruthy();
        fireEvent.click(screen.getByLabelText(/Kalender/));
        expect(onSelect).toHaveBeenCalledWith('calendar');
    });

    it('shows historical hint and switch area without auto-selecting live default', () => {
        render(
            <CalculationMethodSelector
                positionIndex={0}
                options={multi}
                state={{
                    calculation_method_key: 'legacy',
                    calculation_method_name: 'Historisch',
                    historical_calculation_method_key: 'legacy',
                    historical_calculation_method_name: 'Historisch',
                }}
                onSelectLiveKey={vi.fn()}
            />,
        );

        expect(screen.getByText('Historische Berechnungsmethode')).toBeTruthy();
        expect(screen.getByText('Historisch')).toBeTruthy();
        expect(
            screen.getByText(/nicht mehr auswählbar/),
        ).toBeTruthy();
        expect(screen.getByLabelText(/Durchschnitt/)).not.toBeChecked();
        expect(screen.getByLabelText(/Kalender/)).not.toBeChecked();
    });
});
