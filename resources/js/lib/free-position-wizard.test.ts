import { describe, expect, it } from 'vitest';
import {
    buildFreePositionPayload,
    draftBlocksFreePositionPreview,
    emptyFreePositionDraftFields,
    FREE_POSITION_METHOD_KEY,
    freePositionDraftPatchAfterResolvedMethod,
    freePositionDraftPatchOnMethodSelect,
    hydrateFreePositionFromSaved,
    isFreePosition,
    isFreePositionPreviewReady,
    validateFreePriceInput,
} from '@/lib/free-position-wizard';

const basePosition = {
    id: null,
    client_key: 'c1',
    inventory_id: 3,
    advertising_medium_id: 7,
    schema_fingerprint: 'fp',
    free_price_input: '5000',
    position_discounts: [] as Array<{
        type: string;
        custom_label: string | null;
        percent: string;
    }>,
    dynamic_field_values: { period_open: true } as Record<
        string,
        string | number | boolean | null | Record<string, string | null>
    >,
};

describe('free-position-wizard', () => {
    it('detects free_position by method key only', () => {
        expect(FREE_POSITION_METHOD_KEY).toBe('free_position');
        expect(
            isFreePosition({ calculation_method_key: 'free_position' }),
        ).toBe(true);
        expect(isFreePosition({ calculation_method_key: 'average' })).toBe(
            false,
        );
        expect(isFreePosition({ calculation_method_key: 'tkp' })).toBe(false);
        expect(isFreePosition({ calculation_method_key: null })).toBe(false);
    });

    it('provides an empty free price field', () => {
        expect(emptyFreePositionDraftFields()).toEqual({
            free_price_input: '',
        });
    });

    describe('validateFreePriceInput', () => {
        it('accepts positive amounts with up to 2 decimals', () => {
            expect(validateFreePriceInput('5000')).toEqual({
                valid: true,
                normalized: '5000.00',
            });
            expect(validateFreePriceInput('5.000,50')).toEqual({
                valid: true,
                normalized: '5000.50',
            });
            expect(validateFreePriceInput('12,5')).toEqual({
                valid: true,
                normalized: '12.50',
            });
            expect(validateFreePriceInput('0.01')).toEqual({
                valid: true,
                normalized: '0.01',
            });
        });

        it.each(['', '  ', '0', '0,00', '-5', 'abc', '1,234', '1.5e3'])(
            'rejects %j',
            (raw) => {
                expect(validateFreePriceInput(raw).valid).toBe(false);
            },
        );
    });

    describe('buildFreePositionPayload', () => {
        it('sends free_price and the method key as the only price input', () => {
            const payload = buildFreePositionPayload(basePosition);

            expect(payload.free_price).toBe('5000.00');
            expect(payload.calculation_method_key).toBe('free_position');
            expect(payload.inventory_id).toBe(3);
            expect(payload.advertising_medium_id).toBe(7);
            expect(payload).not.toHaveProperty('media_gross');
        });

        it('contains no spot, OA or settlement fields', () => {
            const payload = buildFreePositionPayload({
                ...basePosition,
                // Fremdfelder am Draft dürfen nie im Payload landen.
                ...({
                    length_seconds: 30,
                    total_spot_count: 4,
                    time_ranges: [{}],
                    planner_entries: [{}],
                    components: [{}],
                    impressions_input: '1000',
                    basis_tkp: '10',
                    pricing_settlement_mode: 'fixed_price',
                    fixed_price_nn_input: '100',
                    price_year: 2026,
                    expected_price_list_id: 5,
                    media_gross: '999.00',
                } as Record<string, unknown>),
            });

            for (const forbidden of [
                'length_seconds',
                'total_spot_count',
                'time_ranges',
                'planner_entries',
                'plan_rows',
                'components',
                'component_calculation_strategy',
                'component_profile',
                'production_lines',
                'impressions',
                'impressions_input',
                'basis_tkp',
                'oa_platform_allocations',
                'oa_targeting_selections',
                'expected_oa_tkp_price_list_id',
                'expected_price_list_id',
                'price_year',
                'pricing_settlement_mode',
                'fixed_price_nn',
                'fixed_price_nn_input',
                'media_gross',
            ]) {
                expect(payload).not.toHaveProperty(forbidden);
            }
        });

        it('preserves position discounts', () => {
            const discounts = [
                { type: 'quantity', custom_label: null, percent: '10' },
                { type: 'other', custom_label: 'Rabatt X', percent: '5' },
            ];
            const payload = buildFreePositionPayload({
                ...basePosition,
                position_discounts: discounts,
            });

            expect(payload.position_discounts).toEqual(discounts);
            expect(payload.position_discount_percent).toBe('0');
        });

        it('passes invalid input raw so the server reports free_price errors', () => {
            expect(
                buildFreePositionPayload({
                    ...basePosition,
                    free_price_input: ' abc ',
                }).free_price,
            ).toBe('abc');
        });
    });

    describe('hydrateFreePositionFromSaved', () => {
        it('prefers server free_price', () => {
            expect(
                hydrateFreePositionFromSaved({
                    free_price: '5000.00',
                    media_gross: '1.00',
                }),
            ).toEqual({ free_price_input: '5.000' });
        });

        it('falls back to media_gross', () => {
            expect(
                hydrateFreePositionFromSaved({ media_gross: '1234.50' }),
            ).toEqual({ free_price_input: '1.234,5' });
        });

        it('returns empty input without server values', () => {
            expect(hydrateFreePositionFromSaved({})).toEqual({
                free_price_input: '',
            });
            expect(hydrateFreePositionFromSaved({ free_price: null })).toEqual({
                free_price_input: '',
            });
        });

        it('round-trips into the same payload price', () => {
            const hydrated = hydrateFreePositionFromSaved({
                free_price: '5000.00',
            });

            expect(
                buildFreePositionPayload({ ...basePosition, ...hydrated })
                    .free_price,
            ).toBe('5000.00');
        });
    });

    describe('method switching', () => {
        it('Free → Spot clears free fields', () => {
            const free = { calculation_method_key: 'free_position' };

            expect(
                freePositionDraftPatchOnMethodSelect(free, 'average', 2026),
            ).toEqual({ free_price_input: '' });
            expect(
                freePositionDraftPatchAfterResolvedMethod(
                    free,
                    'average',
                    2026,
                ),
            ).toEqual({ free_price_input: '' });
            expect(
                freePositionDraftPatchAfterResolvedMethod(free, 'tkp', 2026),
            ).toEqual({ free_price_input: '' });
        });

        it('Spot → Free clears foreign spot/OA/settlement fields', () => {
            const spot = { calculation_method_key: 'average' };
            const patch = freePositionDraftPatchOnMethodSelect(
                spot,
                'free_position',
                2026,
            );

            expect(patch).toMatchObject({
                free_price_input: '',
                time_ranges: [],
                planner_entries: [],
                total_spot_count: 0,
                components: [],
                component_calculation_strategy: null,
                production_lines: [],
                impressions_input: '',
                basis_tkp: '',
                oa_platform_allocations: [],
                oa_targeting_selections: [],
                expected_oa_tkp_price_list_id: null,
                pricing_settlement_mode: 'normal',
                fixed_price_nn_input: '',
            });
        });

        it('TKP → Free clears OA fields including settlement', () => {
            const patch = freePositionDraftPatchAfterResolvedMethod(
                { calculation_method_key: 'tkp' },
                'free_position',
                2026,
            );

            expect(patch).toMatchObject({
                free_price_input: '',
                impressions_input: '',
                basis_tkp: '',
                pricing_settlement_mode: 'normal',
                fixed_price_nn_input: '',
                expected_oa_tkp_price_list_id: null,
                original_oa_price_year: 2026,
            });
        });

        it('Free → Free keeps the entered price', () => {
            const free = { calculation_method_key: 'free_position' };

            expect(
                freePositionDraftPatchOnMethodSelect(
                    free,
                    'free_position',
                    2026,
                ),
            ).toEqual({});
            expect(
                freePositionDraftPatchAfterResolvedMethod(
                    free,
                    'free_position',
                    2026,
                ),
            ).toEqual({});
        });

        it('Spot → Spot does not touch free fields', () => {
            expect(
                freePositionDraftPatchOnMethodSelect(
                    { calculation_method_key: 'average' },
                    'calendar',
                    2026,
                ),
            ).toEqual({});
        });
    });

    describe('preview readiness', () => {
        it('blocks free positions without a valid price', () => {
            const free = {
                calculation_method_key: 'free_position',
                inventory_id: 1,
                advertising_medium_id: 2,
            };

            expect(
                isFreePositionPreviewReady({ ...free, free_price_input: '' }),
            ).toBe(false);
            expect(
                isFreePositionPreviewReady({ ...free, free_price_input: '0' }),
            ).toBe(false);
            expect(
                isFreePositionPreviewReady({
                    ...free,
                    free_price_input: '5000',
                }),
            ).toBe(true);
            expect(
                draftBlocksFreePositionPreview([
                    { ...free, free_price_input: '' },
                ]),
            ).toBe(true);
        });

        it('never blocks non-free positions', () => {
            expect(
                draftBlocksFreePositionPreview([
                    { calculation_method_key: 'average' },
                    { calculation_method_key: 'tkp' },
                ]),
            ).toBe(false);
        });
    });
});
