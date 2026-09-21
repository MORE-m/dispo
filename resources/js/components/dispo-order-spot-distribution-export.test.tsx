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
                    disabled_reason: null,
                    url: '/dispoauftraege/1/spotverteilung.xlsx',
                }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('renders enabled button for calendar data', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: false,
                    disabled_reason: null,
                    url: '/dispoauftraege/1/spotverteilung.xlsx',
                }}
            />,
        );

        const button = screen.getByTestId('dispo-spot-distribution-export-button');
        expect(button).toBeEnabled();
        expect(button).toHaveTextContent('Spotverteilung exportieren (XLSX)');
    });

    it('disables button without calendar data and shows hint', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: false,
                    mixed_order: false,
                    disabled_reason:
                        'Keine kalendergeplante Spotverteilung vorhanden.',
                    url: '/dispoauftraege/2/spotverteilung.xlsx',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-spot-distribution-export-button'),
        ).toBeDisabled();
        expect(
            screen.getByTestId('dispo-spot-distribution-export-disabled-hint'),
        ).toHaveTextContent('Keine kalendergeplante Spotverteilung vorhanden.');
    });

    it('shows mixed-order hint', () => {
        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: true,
                    disabled_reason: null,
                    url: '/dispoauftraege/3/spotverteilung.xlsx',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-spot-distribution-export-mixed-hint'),
        ).toHaveTextContent(
            'Der Export enthält nur kalendergeplante Positionen.',
        );
    });

    it('downloads once and blocks multi-click while loading', async () => {
        let resolveFetch: (value: Response) => void = () => undefined;
        const fetchPromise = new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        });
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(fetchPromise);

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
                    disabled_reason: null,
                    url: '/dispoauftraege/4/spotverteilung.xlsx',
                }}
            />,
        );

        const button = screen.getByTestId('dispo-spot-distribution-export-button');
        fireEvent.click(button);
        fireEvent.click(button);

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Spotverteilung wird erstellt…');

        resolveFetch(
            new Response(Uint8Array.from([0x50, 0x4b, 0x03, 0x04]), {
                status: 200,
                headers: {
                    'Content-Type':
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition':
                        'attachment; filename="DO-1_Spotverteilung.xlsx"',
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
                            'Für diesen Dispoauftrag liegt keine exportierbare Kalender-Spotverteilung vor.',
                        ],
                    },
                }),
                { status: 422, headers: { 'Content-Type': 'application/json' } },
            ),
        );

        render(
            <DispoOrderSpotDistributionExport
                exportConfig={{
                    can_export: true,
                    enabled: true,
                    mixed_order: false,
                    disabled_reason: null,
                    url: '/dispoauftraege/5/spotverteilung.xlsx',
                }}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-spot-distribution-export-button'));

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-spot-distribution-export-error'),
            ).toHaveTextContent(
                'Für diesen Dispoauftrag liegt keine exportierbare Kalender-Spotverteilung vor.',
            );
        });
    });
});
