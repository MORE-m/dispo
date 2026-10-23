import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { DispoOrderOaTkpSnapshotPanel } from '@/components/dispo-order-oa-tkp-snapshot-panel';

afterEach(() => {
    cleanup();
});

describe('DispoOrderOaTkpSnapshotPanel', () => {
    it('renders prices, platforms, targetings and price identity', () => {
        render(
            <DispoOrderOaTkpSnapshotPanel
                index={0}
                position={{
                    calculation_method_key: 'tkp',
                    engine_profile_key: 'oa_tkp',
                    impressions: 250_000,
                    basis_tkp: '10.0000',
                    list_tkp_snapshot: '12.0000',
                    minimum_tkp_snapshot: '8.0000',
                    targeting_surcharge_total: '2.5000',
                    final_tkp: '12.5000',
                    media_gross: '3125.00',
                    below_minimum_tkp: true,
                    oa_tkp_price_list_year: 2026,
                    oa_tkp_price_list_version: '2026.1',
                    oa_tkp_price_list_revision: 1,
                    oa_tkp_price_list_id: 44,
                    oa_platform_allocations_snapshot: [
                        {
                            oa_platform_id: 1,
                            platform_name: 'Platform A',
                            platform_code: 'plat_a',
                            impressions: 150_000,
                            sort: 1,
                        },
                        {
                            oa_platform_id: 2,
                            platform_name: 'Platform B',
                            platform_code: 'plat_b',
                            impressions: 100_000,
                            sort: 2,
                        },
                    ],
                    oa_targeting_selections_snapshot: [
                        {
                            targeting_name: 'Geo Historisch',
                            targeting_code: 'geo_old',
                            targeting_category: 'Technisch',
                            tkp_surcharge_snapshot: '2.5000',
                            targeting_revision_number: 3,
                            sort: 1,
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-oa-tkp-prices-0'),
        ).toHaveTextContent('250.000');
        expect(
            screen.getByTestId('dispo-order-oa-tkp-prices-0'),
        ).toHaveTextContent('12,5000 €');
        expect(
            screen.getByTestId('dispo-order-oa-platforms-0'),
        ).toHaveTextContent('Platform A');
        expect(
            screen.getByTestId('dispo-order-oa-platforms-0'),
        ).toHaveTextContent('150.000');
        expect(
            screen.getByTestId('dispo-order-oa-targetings-0'),
        ).toHaveTextContent('Geo Historisch');
        expect(
            screen.getByTestId('dispo-order-oa-targetings-0'),
        ).toHaveTextContent('Rev. 3');
        expect(
            screen.getByTestId('dispo-order-oa-price-identity-0'),
        ).toHaveTextContent('Jahr 2026');
        expect(
            screen.getByTestId('dispo-order-oa-below-minimum-0'),
        ).toBeInTheDocument();
    });

    it('handles missing optional snapshot arrays safely', () => {
        render(
            <DispoOrderOaTkpSnapshotPanel
                index={1}
                position={{
                    calculation_method_key: 'tkp',
                    engine_profile_key: 'oa_tkp',
                    impressions: 10_000,
                    final_tkp: '10.0000',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-oa-tkp-snapshot-1'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-oa-platforms-1'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-oa-targetings-1'),
        ).not.toBeInTheDocument();
    });
});
