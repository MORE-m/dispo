import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
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
        await importOriginal<
            typeof import('@/lib/dispo-order-inertia-cache')
        >();

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

    it('shows isolation hint and no-replace hint when orders already exist', async () => {
        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-create-isolation-hint'),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByTestId('dispo-order-create-saved-positions-hint'),
        ).toHaveTextContent(
            'Für den Dispoauftrag werden die zuletzt gespeicherten Kalkulationspositionen verwendet. Nicht gespeicherte Änderungen im Wizard werden nicht übernommen.',
        );
        expect(
            screen.getByTestId('dispo-order-create-saved-positions-hint'),
        ).toHaveTextContent(
            'Spätere gespeicherte Änderungen an der Kalkulation ändern diesen Auftrag ebenfalls nicht.',
        );
        expect(
            screen.getByTestId('dispo-order-create-no-replace-hint'),
        ).toHaveTextContent(
            'Ein neuer Dispoauftrag ersetzt oder storniert bestehende Aufträge nicht automatisch.',
        );
    });

    it('hides no-replace hint when no positions are adopted yet', async () => {
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
                            time_ranges: [],
                            already_adopted: false,
                            adoptions: [],
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
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-create-isolation-hint'),
            ).toBeInTheDocument();
        });

        expect(
            screen.queryByTestId('dispo-order-create-no-replace-hint'),
        ).not.toBeInTheDocument();
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

    it('renders OA detail with impressions and TKP without spot length', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    positions: [
                        {
                            id: 10,
                            inventory_name: 'MORE Online Audio',
                            advertising_medium_name: 'Pre-Stream',
                            calculation_method_key: 'tkp',
                            engine_profile_key: 'oa_tkp',
                            impressions: 250_000,
                            final_tkp: '12.5000',
                            length_seconds: 0,
                            total_spot_count: 0,
                            nn_invest: '3125.00',
                            time_ranges: [],
                            already_adopted: false,
                            adoptions: [],
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
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-position-detail-10'),
            ).toBeInTheDocument();
        });

        const detail = screen.getByTestId('dispo-order-position-detail-10');
        expect(detail).toHaveTextContent('250.000 Impressions');
        expect(detail).toHaveTextContent('TKP');
        expect(detail).toHaveTextContent('12,5000 €');
        expect(detail).not.toHaveTextContent('Spots');
        expect(detail).not.toHaveTextContent('0s');
        expect(screen.getByTestId('dispo-order-submit')).toBeEnabled();
    });

    it('renders mixed list and allows selecting spot and OA together', async () => {
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
                            calculation_method_key: 'spot_classic',
                            engine_profile_key: 'spot_classic',
                            impressions: null,
                            final_tkp: null,
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
                            inventory_name: 'MORE Online Audio',
                            advertising_medium_name: 'Pre-Stream',
                            calculation_method_key: 'tkp',
                            engine_profile_key: 'oa_tkp',
                            impressions: 100_000,
                            final_tkp: '12.0000',
                            length_seconds: 0,
                            total_spot_count: 0,
                            nn_invest: '1200.00',
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

        render(
            <DispoOrderCreateDialog
                calculationId={42}
                open
                onOpenChange={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-position-detail-1'),
            ).toHaveTextContent('10 Spots');
        });

        expect(
            screen.getByTestId('dispo-order-position-detail-2'),
        ).toHaveTextContent('100.000 Impressions');
        expect(
            screen.getByTestId('dispo-order-position-adopted-2'),
        ).toHaveTextContent('Bereits übernommen');

        expect(
            screen.getByTestId('dispo-order-position-checkbox-1'),
        ).toBeChecked();
        expect(
            screen.getByTestId('dispo-order-position-checkbox-2'),
        ).not.toBeChecked();

        fireEvent.click(screen.getByTestId('dispo-order-select-all'));
        expect(
            screen.getByTestId('dispo-order-position-checkbox-2'),
        ).toBeChecked();

        fireEvent.click(screen.getByTestId('dispo-order-submit'));
        expect(mockPost).toHaveBeenCalledWith(
            '/kalkulationen/42/dispoauftraege',
            { position_ids: [1, 2] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
