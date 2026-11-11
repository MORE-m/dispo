import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OaTkpPositionEditor } from '@/components/oa-tkp-position-editor';
import { emptyOaTkpDraftFields } from '@/lib/oa-tkp-wizard';

vi.mock('@/hooks/use-oa-tkp-pricing-metadata', () => ({
    useOaTkpPricingMetadata: () => ({
        loading: false,
        error: null,
        fieldErrors: {},
        metadata: {
            advertising_medium_id: 1,
            inventory_id: 1,
            price_year: 2026,
            available_price_years: [2026],
            expected_oa_tkp_price_list_id: 10,
            list_tkp: '12.0000',
            minimum_tkp: '8.0000',
            price_list_version: '2026.1',
            price_list_revision: 1,
        },
    }),
    useOaTkpAvailablePriceYears: () => ({
        loading: false,
        years: [2026],
        error: null,
    }),
}));

describe('OaTkpPositionEditor', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders impressions, basis and readonly price metrics without platform UX', () => {
        const position = {
            ...emptyOaTkpDraftFields(2026),
            client_key: 'c1',
            calculation_method_key: 'tkp',
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: null,
            saved_advertising_medium_id: null,
            saved_price_year: null,
            impressions_input: '100000',
            basis_tkp: '10.0000',
            list_tkp_snapshot: '12.0000',
            minimum_tkp_snapshot: '8.0000',
            expected_oa_tkp_price_list_id: 10,
            oa_platform_allocations: [],
        };

        render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                targetings={[
                    {
                        id: 5,
                        code: 'geo',
                        name: 'Geo',
                        category: 'Geo',
                        tkp_surcharge: '2.0000',
                        revision_number: 1,
                    },
                ]}
                currentPriceYear={2026}
                nextPriceYear={2027}
                methodResult={{
                    basis_tkp: '10.0000',
                    list_tkp: '12.0000',
                    minimum_tkp: '8.0000',
                    targeting_surcharge_total: '2.0000',
                    final_tkp: '12.0000',
                    below_minimum_tkp: false,
                    impressions: 100_000,
                    price_year: 2026,
                }}
                mediaGross="1200.00"
                fieldErrors={{}}
                canEdit
                showActivePickers
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByTestId('oa-tkp-editor-0')).toBeTruthy();
        expect(screen.getByTestId('oa-tkp-impressions-0')).toBeTruthy();
        expect(screen.getByTestId('oa-tkp-basis-0')).toBeTruthy();
        expect(screen.getByTestId('oa-tkp-final-0').textContent).toContain(
            '12,0000',
        );
        expect(screen.queryByText('Spotlänge')).toBeNull();
        expect(screen.queryByText('Platform-Verteilung')).toBeNull();
        expect(screen.queryByText('Platform hinzufügen')).toBeNull();
        expect(screen.queryByTestId('oa-tkp-platform-add-0')).toBeNull();
        expect(screen.queryByTestId('oa-tkp-platform-summary-0')).toBeNull();
        expect(screen.queryByText('Spotify')).toBeNull();
        expect(screen.queryByText('YouTube')).toBeNull();
        expect(screen.queryByText('Deezer')).toBeNull();
    });

    it('shows under-min warning', () => {
        const position = {
            ...emptyOaTkpDraftFields(2026),
            client_key: 'c1',
            calculation_method_key: 'tkp',
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: null,
            saved_advertising_medium_id: null,
            saved_price_year: null,
            basis_tkp: '7.0000',
            below_minimum_tkp: true,
        };

        render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                targetings={[]}
                currentPriceYear={2026}
                nextPriceYear={2027}
                methodResult={{
                    basis_tkp: '7.0000',
                    list_tkp: '12.0000',
                    minimum_tkp: '8.0000',
                    targeting_surcharge_total: '0.0000',
                    final_tkp: '7.0000',
                    below_minimum_tkp: true,
                }}
                mediaGross={null}
                fieldErrors={{}}
                canEdit={false}
                showActivePickers={false}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByTestId('oa-tkp-under-min-0').textContent).toContain(
            'Finaler TKP unterschreitet Mindest-TKP',
        );
        expect(screen.getByTestId('oa-tkp-under-min-0').textContent).toContain(
            'Sonderfreigabe',
        );
    });

    it('keeps historical targeting visible and blocks same-code active without platform UX', () => {
        const position = {
            ...emptyOaTkpDraftFields(2026),
            client_key: 'c1',
            calculation_method_key: 'tkp',
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: 1,
            saved_advertising_medium_id: 2,
            saved_price_year: 2026,
            impressions_input: '100000',
            basis_tkp: '10.0000',
            expected_oa_tkp_price_list_id: 10,
            original_oa_tkp_price_list_id: 10,
            original_oa_list_tkp: '12.0000',
            original_oa_minimum_tkp: '8.0000',
            original_oa_price_year: 2026,
            list_tkp_snapshot: '12.0000',
            minimum_tkp_snapshot: '8.0000',
            oa_platform_allocations: [],
            oa_targeting_selections: [
                {
                    oa_targeting_definition_id: 7,
                    targeting_code: 'geo',
                    targeting_name: 'Geo Alt',
                    targeting_category: 'Geo',
                    tkp_surcharge_snapshot: '1.0000',
                    targeting_revision_number: 1,
                    historical: true,
                },
            ],
        };

        render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                targetings={[
                    {
                        id: 8,
                        code: 'geo',
                        name: 'Geo Neu',
                        category: 'Geo',
                        tkp_surcharge: '2.0000',
                        revision_number: 2,
                    },
                ]}
                currentPriceYear={2026}
                nextPriceYear={2027}
                methodResult={null}
                mediaGross={null}
                fieldErrors={{}}
                canEdit
                showActivePickers
                onChange={vi.fn()}
            />,
        );

        expect(screen.queryByText('Legacy Platform')).toBeNull();
        expect(screen.queryByText('Platform-Verteilung')).toBeNull();
        expect(
            screen.getByTestId('oa-tkp-targeting-historical-0-geo').textContent,
        ).toContain('historisch');

        const activeGeoLabel = screen.getByTestId('oa-tkp-targeting-0-8');
        const activeGeo = activeGeoLabel.querySelector(
            'input[type="checkbox"]',
        ) as HTMLInputElement;
        expect(activeGeo.disabled).toBe(true);
        expect(activeGeoLabel.textContent).toMatch(
            /historische Auswahl entfernen/i,
        );
    });

    it('midroll music environment shows no Spotify/YouTube/Deezer single-select', () => {
        const position = {
            ...emptyOaTkpDraftFields(2026),
            client_key: 'c1',
            calculation_method_key: 'tkp',
            inventory_id: 1,
            advertising_medium_id: 9,
            price_year: 2026,
            saved_inventory_id: null,
            saved_advertising_medium_id: null,
            saved_price_year: null,
            impressions_input: '50000',
            basis_tkp: '15.0000',
            expected_oa_tkp_price_list_id: 10,
            oa_platform_allocations: [],
        };

        render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                targetings={[]}
                currentPriceYear={2026}
                nextPriceYear={2027}
                methodResult={null}
                mediaGross={null}
                fieldErrors={{}}
                canEdit
                showActivePickers
                onChange={vi.fn()}
            />,
        );

        // Midroll ist das Spotify/YouTube/Deezer-Bundle – keine Einzelplattform-UX.
        expect(screen.queryByRole('checkbox', { name: /spotify/i })).toBeNull();
        expect(screen.queryByRole('checkbox', { name: /youtube/i })).toBeNull();
        expect(screen.queryByRole('checkbox', { name: /deezer/i })).toBeNull();
        expect(screen.queryByLabelText(/platform/i)).toBeNull();
        expect(screen.queryByText(/Spotify/i)).toBeNull();
        expect(screen.queryByText(/YouTube/i)).toBeNull();
        expect(screen.queryByText(/Deezer/i)).toBeNull();
        expect(screen.queryByText('Platform hinzufügen')).toBeNull();
        expect(screen.queryByText('Platform-Verteilung')).toBeNull();
        expect(screen.queryByText(/mindestens eine Plattform/i)).toBeNull();
    });

    const basePosition = {
        ...emptyOaTkpDraftFields(2026),
        client_key: 'c1',
        calculation_method_key: 'tkp',
        inventory_id: 1,
        advertising_medium_id: 2,
        price_year: 2026,
        saved_inventory_id: null,
        saved_advertising_medium_id: null,
        saved_price_year: null,
        impressions_input: '100000',
        basis_tkp: '10.0000',
        list_tkp_snapshot: '12.0000',
        minimum_tkp_snapshot: '8.0000',
        expected_oa_tkp_price_list_id: 10,
        oa_platform_allocations: [],
    };

    const fixedPosition = {
        ...basePosition,
        basis_tkp: '',
        pricing_settlement_mode: 'fixed_price' as const,
        fixed_price_nn_input: '1.500',
    };

    function renderEditor(
        position: typeof basePosition,
        overrides: Partial<Parameters<typeof OaTkpPositionEditor>[0]> = {},
    ) {
        return render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                targetings={[]}
                currentPriceYear={2026}
                nextPriceYear={2027}
                methodResult={null}
                mediaGross={null}
                fieldErrors={{}}
                canEdit
                showActivePickers
                onChange={vi.fn()}
                {...overrides}
            />,
        );
    }

    it('C1 normal: basis visible, fixed input and approval banner hidden', () => {
        renderEditor(basePosition);

        expect(screen.getByTestId('oa-tkp-basis-0')).toBeTruthy();
        expect(screen.queryByTestId('oa-tkp-fixed-price-0')).toBeNull();
        expect(screen.queryByTestId('oa-tkp-fixed-approval-0')).toBeNull();
        expect(
            (
                screen.getByTestId(
                    'oa-tkp-settlement-normal-0',
                ) as HTMLInputElement
            ).checked,
        ).toBe(true);
        expect(screen.getByText('Targeting-Aufschlag')).toBeTruthy();
    });

    it('C2 fixed: fixed input and approval banner, basis hidden', () => {
        renderEditor(fixedPosition);

        expect(screen.getByTestId('oa-tkp-fixed-price-0')).toBeTruthy();
        expect(screen.queryByTestId('oa-tkp-basis-0')).toBeNull();
        expect(
            screen.getByTestId('oa-tkp-fixed-approval-0').textContent,
        ).toContain('Online-Audio-Festpreis erfordert eine Sonderfreigabe.');
        expect(screen.queryByText('Targeting-Aufschlag')).toBeNull();
    });

    it('C3 fixed: preview metrics come from method result', () => {
        renderEditor(fixedPosition, {
            mediaGross: '1200.00',
            methodResult: {
                basis_tkp: '12.0000',
                list_tkp: '12.0000',
                minimum_tkp: '9.0000',
                targeting_surcharge_total: '0.0000',
                final_tkp: '11.2500',
                below_minimum_tkp: false,
                pricing_settlement_mode: 'fixed_price',
                fixed_price_nn: '1350.00',
                reference_tkp: '12.5000',
            },
        });

        expect(screen.getByText('Effektiver Mindest-TKP')).toBeTruthy();
        expect(screen.getByText('Effektiver TKP')).toBeTruthy();
        expect(screen.getByTestId('oa-tkp-final-0').textContent).toContain(
            '11,2500',
        );
        expect(screen.getByTestId('oa-tkp-fixed-nn-0').textContent).toContain(
            '1.350',
        );
        expect(
            screen.getByTestId('oa-tkp-reference-tkp-0').textContent,
        ).toContain('12,5000');
        expect(screen.getByText('Referenz-Mediabrutto')).toBeTruthy();
    });

    it('C4 fixed under-min shows approval and under-min banners', () => {
        renderEditor(fixedPosition, {
            methodResult: {
                basis_tkp: '12.0000',
                list_tkp: '12.0000',
                minimum_tkp: '9.0000',
                targeting_surcharge_total: '0.0000',
                final_tkp: '7.0000',
                below_minimum_tkp: true,
            },
        });

        expect(screen.getByTestId('oa-tkp-fixed-approval-0')).toBeTruthy();
        expect(screen.getByTestId('oa-tkp-under-min-0')).toBeTruthy();
    });

    it('C5 fixed shows the targeting reference hint, normal does not', () => {
        const { unmount } = renderEditor(fixedPosition);
        expect(
            screen.getByTestId('oa-tkp-fixed-targeting-hint-0').textContent,
        ).toContain('nicht zusätzlich auf den vereinbarten Festpreis');
        unmount();

        renderEditor(basePosition);
        expect(
            screen.queryByTestId('oa-tkp-fixed-targeting-hint-0'),
        ).toBeNull();
    });

    it('C6 reloaded fixed draft renders toggle and value', () => {
        renderEditor(fixedPosition);

        expect(
            (
                screen.getByTestId(
                    'oa-tkp-settlement-fixed-0',
                ) as HTMLInputElement
            ).checked,
        ).toBe(true);
        expect(
            (screen.getByTestId('oa-tkp-fixed-price-0') as HTMLInputElement)
                .value,
        ).toBe('1.500');
    });

    it('mode radios emit settlement patches and respect canEdit', () => {
        const onChange = vi.fn();
        const { unmount } = renderEditor(basePosition, { onChange });
        fireEvent.click(screen.getByTestId('oa-tkp-settlement-fixed-0'));
        expect(onChange).toHaveBeenCalledWith({
            pricing_settlement_mode: 'fixed_price',
            fixed_price_nn_input: '',
        });
        unmount();

        renderEditor(fixedPosition, { canEdit: false });
        expect(
            (
                screen.getByTestId(
                    'oa-tkp-settlement-normal-0',
                ) as HTMLInputElement
            ).disabled,
        ).toBe(true);
    });

    it('C7 midroll fixed still has no platform single-select UI', () => {
        renderEditor({ ...fixedPosition, advertising_medium_id: 9 });

        expect(screen.queryByText(/Spotify/i)).toBeNull();
        expect(screen.queryByText(/YouTube/i)).toBeNull();
        expect(screen.queryByText(/Deezer/i)).toBeNull();
        expect(screen.queryByText('Platform hinzufügen')).toBeNull();
        expect(screen.queryByLabelText(/platform/i)).toBeNull();
    });
});
