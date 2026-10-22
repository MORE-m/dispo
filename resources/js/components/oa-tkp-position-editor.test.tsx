import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
    it('renders impressions, basis and readonly price metrics', () => {
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
            oa_platform_allocations: [
                {
                    oa_platform_id: 1,
                    platform_code: 'a',
                    platform_name: 'Platform A',
                    impressions_input: '100000',
                },
            ],
        };

        render(
            <OaTkpPositionEditor
                positionIndex={0}
                position={position}
                platforms={[
                    { id: 1, code: 'a', name: 'Platform A', sort: 1 },
                ]}
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
                platforms={[]}
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
            'Sonderfreigabe',
        );
    });
});
