import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { DispoOrderCreateDialog } from '@/components/dispo-order-create-dialog';

const mockPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => mockPost(...args),
    },
}));

describe('DispoOrderCreateDialog', () => {
    beforeEach(() => {
        mockPost.mockReset();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    positions: [
                        {
                            id: 1,
                            inventory_name: 'Radio Hamburg',
                            advertising_medium_name: 'Spot',
                            length_seconds: 30,
                            total_spot_count: 10,
                            nn_invest: '210.00',
                            time_ranges: [
                                {
                                    start_hour: 8,
                                    end_hour_exclusive: 10,
                                    spot_count: 10,
                                },
                            ],
                            already_adopted: false,
                            adoptions: [],
                        },
                        {
                            id: 2,
                            inventory_name: 'ROCK ANTENNE Hamburg',
                            advertising_medium_name: 'Spot',
                            length_seconds: 30,
                            total_spot_count: 5,
                            nn_invest: '120.00',
                            time_ranges: [],
                            already_adopted: true,
                            adoptions: [
                                {
                                    dispo_order_id: 9,
                                    dispo_order_number: 'DA-2026-000001-01',
                                },
                            ],
                        },
                    ],
                }),
            }),
        );
    });

    it('selects non-adopted positions by default', async () => {
        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-position-checkbox-1'),
            ).toBeChecked();
        });

        expect(
            screen.getByTestId('dispo-order-position-checkbox-2'),
        ).not.toBeChecked();
        expect(
            screen.getByTestId('dispo-order-position-adopted-2'),
        ).toHaveTextContent('Bereits übernommen');
    });

    it('submits selected positions via router post', async () => {
        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getAllByTestId('dispo-order-submit')[0],
            ).toBeEnabled();
        });

        fireEvent.click(screen.getAllByTestId('dispo-order-submit')[0]);

        expect(mockPost).toHaveBeenCalledWith(
            '/kalkulationen/42/dispoauftraege',
            { position_ids: [1] },
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
    });

    it('shows empty state when no positions returned', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ positions: [] }),
            }),
        );

        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByText('Keine übernehmbaren Positionen vorhanden.'),
            ).toBeInTheDocument();
        });
    });
});
