import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCreateDialog } from '@/components/dispo-order-create-dialog';

const mockPost = vi.fn();
const mockFlush = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => mockPost(...args),
        flushByCacheTags: vi.fn(),
        flush: vi.fn(),
    },
}));

vi.mock('@/lib/dispo-order-inertia-cache', async (importOriginal) => {
    const actual =
        await importOriginal<typeof import('@/lib/dispo-order-inertia-cache')>();

    return {
        ...actual,
        flushDispoOrderInertiaCache: (...args: unknown[]) => mockFlush(...args),
    };
});

describe('DispoOrderCreateDialog', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockPost.mockReset();
        mockFlush.mockReset();
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
                screen.getAllByTestId('dispo-order-position-checkbox-1')[0],
            ).toBeChecked();
        });

        expect(
            screen.getAllByTestId('dispo-order-position-checkbox-2')[0],
        ).not.toBeChecked();
        expect(
            screen.getAllByTestId('dispo-order-position-adopted-2')[0],
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

        expect(mockFlush).toHaveBeenCalled();
        expect(mockPost).toHaveBeenCalledWith(
            '/kalkulationen/42/dispoauftraege',
            { position_ids: [1] },
            expect.objectContaining({
                preserveScroll: true,
                invalidateCacheTags: 'dispo-orders',
            }),
        );
    });

    it('uses preferred positions and revision payload when correcting', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    preferred_position_ids: [2],
                    revision: {
                        predecessor_id: 11,
                        predecessor_number: 'DA-2026-000001-01',
                        rejection_reason: 'Zu hoher Rabatt',
                    },
                    positions: [
                        {
                            id: 1,
                            inventory_name: 'Radio Hamburg',
                            advertising_medium_name: 'Spot',
                            length_seconds: 30,
                            total_spot_count: 10,
                            nn_invest: '210.00',
                            time_ranges: [],
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
                                    dispo_order_id: 11,
                                    dispo_order_number: 'DA-2026-000001-01',
                                },
                            ],
                        },
                    ],
                }),
            }),
        );

        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
                revision={{
                    predecessor_id: 11,
                    predecessor_number: 'DA-2026-000001-01',
                    rejection_reason: 'Zu hoher Rabatt',
                    return_url: '/dispoauftraege/11',
                }}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getAllByTestId('dispo-order-position-checkbox-2')[0],
            ).toBeChecked();
        });

        expect(
            screen.getAllByTestId('dispo-order-position-checkbox-1')[0],
        ).not.toBeChecked();
        expect(
            screen.getAllByTestId('dispo-order-submit')[0],
        ).toHaveTextContent('Korrigierten Dispoauftrag erstellen');

        fireEvent.click(screen.getAllByTestId('dispo-order-submit')[0]);

        expect(mockPost).toHaveBeenCalledWith(
            '/kalkulationen/42/dispoauftraege',
            {
                position_ids: [2],
                revises_dispo_order_id: 11,
            },
            expect.objectContaining({
                invalidateCacheTags: 'dispo-orders',
            }),
        );
    });

    it('blocks double submit while request is pending', async () => {
        mockPost.mockImplementation(() => undefined);

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

        const submit = screen.getAllByTestId('dispo-order-submit')[0];
        fireEvent.click(submit);
        fireEvent.click(submit);

        expect(mockPost).toHaveBeenCalledTimes(1);
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
