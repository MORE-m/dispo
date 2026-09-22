import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderSpotDistributionExport } from '@/components/dispo-order-spot-distribution-export';

describe('DispoOrderSpotDistributionExport', () => {
    const originalFetch = globalThis.fetch;

    afterEach(() => {
        cleanup();
        globalThis.fetch = originalFetch;
    });

    beforeEach(() => {
        globalThis.fetch = vi.fn();
    });

    it('is hidden without export permission', () => {
        const { container } = render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: false,
                    enabled: true,
                    mixed_order: false,
                    hint_kind: 'calendar_only',
                    disabled_reason: null,
                    url: '/dispoauftraege/1/spotverteilung.xlsx',
                }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('renders enabled button for calendar-only with hint', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    has_calendar_positions: true,
                    has_average_positions: false,
                    mixed_order: false,
                    hint_kind: 'calendar_only',
                    disabled_reason: null,
                    url: '/dispoauftraege/1/spotverteilung.xlsx',
                }}
            />,
        );

        const button = screen.getByTestId(
            'dispo-spot-distribution-export-button',
        );
        expect(button).toBeEnabled();
        expect(button).toHaveTextContent('Spotplanung exportieren (XLSX)');
        expect(
            screen.getByTestId('dispo-spot-distribution-export-hint'),
        ).toHaveTextContent('Der Export enthält die konkrete Spotverteilung.');
    });

    it('enables button for average-only with proposal hint', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    has_calendar_positions: false,
                    has_average_positions: true,
                    mixed_order: false,
                    hint_kind: 'average_only',
                    disabled_reason: null,
                    url: '/dispoauftraege/4/spotverteilung.xlsx',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-spot-distribution-export-button'),
        ).toBeEnabled();
        expect(
            screen.getByTestId('dispo-spot-distribution-export-hint'),
        ).toHaveTextContent(
            'Der Export enthält einen unverbindlichen Planungsvorschlag. Die konkrete Einplanung erfolgt durch die Disposition.',
        );
    });

    it('disables button only when neither calendar nor average is exportable', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: false,
                    mixed_order: false,
                    hint_kind: null,
                    disabled_reason:
                        'Keine exportierbare Spotplanung vorhanden (weder Calendar-Verteilung noch Average-Planungsvorschlag).',
                    url: '/dispoauftraege/2/spotverteilung.xlsx',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-spot-distribution-export-button'),
        ).toBeDisabled();
        expect(
            screen.getByTestId('dispo-spot-distribution-export-disabled-hint'),
        ).toHaveTextContent('Keine exportierbare Spotplanung vorhanden');
    });

    it('shows mixed-order hint', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: true,
                    hint_kind: 'mixed',
                    disabled_reason: null,
                    url: '/dispoauftraege/3/spotverteilung.xlsx',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-spot-distribution-export-hint'),
        ).toHaveTextContent(
            'Der Export enthält die konkrete Spotverteilung und einen separaten Planungsvorschlag für Average-Positionen.',
        );
    });

    it('downloads once and blocks multi-click while loading', async () => {
        let resolveFetch: (value: Response) => void = () => undefined;
        const fetchPromise = new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        });
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(
            fetchPromise,
        );

        const createObjectURL = vi.fn(() => 'blob:mock');
        const revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', {
            ...URL,
            createObjectURL,
            revokeObjectURL,
        });

        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: false,
                    hint_kind: 'calendar_only',
                    disabled_reason: null,
                    url: '/dispoauftraege/4/spotverteilung.xlsx',
                }}
            />,
        );

        const button = screen.getByTestId(
            'dispo-spot-distribution-export-button',
        );
        fireEvent.click(button);
        fireEvent.click(button);

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Spotplanung wird erstellt…');

        resolveFetch(
            new Response(Uint8Array.from([0x50, 0x4b, 0x03, 0x04]), {
                status: 200,
                headers: {
                    'Content-Type':
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition':
                        'attachment; filename="DO-1_Spotplanung.xlsx"',
                },
            }),
        );

        await waitFor(() => {
            expect(button).toBeEnabled();
        });
        expect(createObjectURL).toHaveBeenCalled();
    });

    it('shows error message on failed export', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'The given data was invalid.',
                    errors: {
                        export: [
                            'Für diesen Dispoauftrag liegt keine exportierbare Spotplanung vor.',
                        ],
                    },
                }),
                {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );

        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: false,
                    hint_kind: 'calendar_only',
                    disabled_reason: null,
                    url: '/dispoauftraege/5/spotverteilung.xlsx',
                }}
            />,
        );

        fireEvent.click(
            screen.getByTestId('dispo-spot-distribution-export-button'),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-spot-distribution-export-error'),
            ).toHaveTextContent(
                'Für diesen Dispoauftrag liegt keine exportierbare Spotplanung vor.',
            );
        });
    });
});
