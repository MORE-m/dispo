import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CalculationSummaryPanel } from '@/components/calculation-summary-panel';

afterEach(() => {
    cleanup();
});

const totals = {
    media_gross: '1200.00',
    position_discount_total: '174.00',
    order_discount_total: '102.60',
    ae_total: '0.00',
    nn_invest: '923.40',
    after_position_discount_total: '1026.00',
    after_order_discount_total: '923.40',
    order_discounts: [
        {
            label: 'Mengenrabatt',
            percent: '10.0000',
            amount: '102.60',
        },
    ],
    positions: [
        {
            media_gross: '1200.00',
            after_position_discount: '1026.00',
            nn_invest: '923.40',
            position_discounts: [
                {
                    label: 'Mengenrabatt',
                    percent: '10.0000',
                    amount: '120.00',
                },
                {
                    label: 'Sonderrabatt',
                    percent: '5.0000',
                    amount: '54.00',
                },
            ],
        },
    ],
};

describe('CalculationSummaryPanel', () => {
    it('zeigt den Positionsbetrag nach Positionsrabatten', () => {
        render(
            <CalculationSummaryPanel
                totals={totals}
                positions={[
                    {
                        inventory_id: 1,
                        total_spot_count: 30,
                        length_seconds: 30,
                    },
                ]}
                inventories={[
                    { id: 1, name: 'Radio Hamburg', logo_path: null },
                ]}
            />,
        );

        expect(screen.getByTestId('summary-position-total-0')).toHaveTextContent(
            '1.026,00',
        );
        expect(screen.getByTestId('summary-position-total-0')).not.toHaveTextContent(
            '923,40',
        );
    });

    it('zeigt Auftragsrabatte nur einmal mit negativem Vorzeichen', () => {
        render(
            <CalculationSummaryPanel
                totals={totals}
                positions={[
                    {
                        inventory_id: 1,
                        total_spot_count: 30,
                        length_seconds: 30,
                    },
                ]}
                inventories={[
                    { id: 1, name: 'Radio Hamburg', logo_path: null },
                ]}
            />,
        );

        expect(screen.getByTestId('summary-order-discount-0')).toHaveTextContent(
            '−102,60',
        );
        expect(screen.queryByText('Rabatte Auftrag')).not.toBeInTheDocument();
        expect(screen.getByTestId('summary-after-position-total')).toHaveTextContent(
            '1.026,00',
        );
        expect(screen.getByTestId('summary-after-order-total')).toHaveTextContent(
            '923,40',
        );
    });

    it('formatiert Prozentwerte ohne technische Nachkommastellen', () => {
        render(
            <CalculationSummaryPanel
                totals={totals}
                positions={[
                    {
                        inventory_id: 1,
                        total_spot_count: 30,
                        length_seconds: 30,
                    },
                ]}
                inventories={[
                    { id: 1, name: 'Radio Hamburg', logo_path: null },
                ]}
            />,
        );

        const summary = screen.getByTestId('calculation-summary');
        expect(summary).toHaveTextContent('Mengenrabatt 10 %');
        expect(summary).not.toHaveTextContent('10.0000');
    });

    it('hält zwei Positionen getrennt nach Positionsrabatten', () => {
        render(
            <CalculationSummaryPanel
                totals={{
                    ...totals,
                    positions: [
                        totals.positions[0],
                        {
                            media_gross: '500.00',
                            after_position_discount: '450.00',
                            nn_invest: '400.00',
                            position_discounts: [
                                {
                                    label: 'Mengenrabatt',
                                    percent: '10.0000',
                                    amount: '50.00',
                                },
                            ],
                        },
                    ],
                }}
                positions={[
                    {
                        inventory_id: 1,
                        total_spot_count: 30,
                        length_seconds: 30,
                    },
                    {
                        inventory_id: 2,
                        total_spot_count: 10,
                        length_seconds: 20,
                    },
                ]}
                inventories={[
                    { id: 1, name: 'Radio Hamburg', logo_path: null },
                    { id: 2, name: 'ROCK ANTENNE Hamburg', logo_path: null },
                ]}
            />,
        );

        expect(screen.getByTestId('summary-position-total-0')).toHaveTextContent(
            '1.026,00',
        );
        expect(screen.getByTestId('summary-position-total-1')).toHaveTextContent(
            '450,00',
        );
    });

    it('zeigt den eingefrorenen Inventarnamen statt des aktuellen Live-Namens', () => {
        render(
            <CalculationSummaryPanel
                totals={{
                    ...totals,
                    positions: [
                        {
                            ...totals.positions[0],
                            inventory_name: 'Radio Hamburg',
                        },
                    ],
                }}
                positions={[
                    {
                        inventory_id: 1,
                        inventory_name: 'Radio Hamburg',
                        total_spot_count: 30,
                        length_seconds: 30,
                    },
                ]}
                inventories={[
                    { id: 1, name: 'Radio Hamburg Neu', logo_path: null },
                ]}
            />,
        );

        expect(screen.getByText('Radio Hamburg')).toBeTruthy();
        expect(screen.queryByText('Radio Hamburg Neu')).toBeNull();
    });
});
