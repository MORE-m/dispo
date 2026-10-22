import { describe, expect, it } from 'vitest';
import {
    buildOaTkpPositionPayload,
    draftHasTkpPositions,
    emptyOaTkpDraftFields,
    hasDuplicatePlatformIds,
    hasDuplicateTargetingCodes,
    isActiveTargetingBlockedByHistorical,
    isOaTkpPreviewReady,
    isSelectableForCalculationWizardPosition,
    isTkpPosition,
    isValidBasisTkpInput,
    needsOaTkpRebind,
    parsePositiveIntegerInput,
    platformAllocationSum,
    restoreOriginalOaTkpPin,
} from '@/lib/oa-tkp-wizard';

describe('oa-tkp-wizard helpers', () => {
    it('detects TKP only by method key', () => {
        expect(isTkpPosition({ calculation_method_key: 'tkp' })).toBe(true);
        expect(isTkpPosition({ calculation_method_key: 'average' })).toBe(false);
        expect(isTkpPosition({ calculation_method_key: null })).toBe(false);
    });

    it('soft-opens selection under capability when methods include tkp', () => {
        const medium = {
            is_active: true,
            is_bookable_for_new_positions: false,
            calculation_method_options: {
                medium_id: 1,
                source: 'category',
                default_calculation_method_key: 'tkp',
                methods: [
                    {
                        key: 'tkp',
                        name: 'TKP',
                        help_text: null,
                        is_default: true,
                    },
                ],
            },
        };

        expect(isSelectableForCalculationWizardPosition(medium, false)).toBe(
            false,
        );
        expect(isSelectableForCalculationWizardPosition(medium, true)).toBe(
            true,
        );
    });

    it('validates impressions and basis tkp', () => {
        expect(parsePositiveIntegerInput('100000')).toBe(100_000);
        expect(parsePositiveIntegerInput('')).toBeNull();
        expect(parsePositiveIntegerInput('10.5')).toBeNull();
        expect(isValidBasisTkpInput('10.0000')).toBe(true);
        expect(isValidBasisTkpInput('0')).toBe(false);
        expect(isValidBasisTkpInput('10.12345')).toBe(false);
        expect(isValidBasisTkpInput('1,5')).toBe(false);
    });

    it('validates platform sum and duplicates', () => {
        const rows = [
            {
                oa_platform_id: 1,
                platform_code: 'a',
                platform_name: 'A',
                impressions_input: '60000',
            },
            {
                oa_platform_id: 2,
                platform_code: 'b',
                platform_name: 'B',
                impressions_input: '40000',
            },
        ];
        expect(platformAllocationSum(rows)).toBe(100_000);
        expect(
            hasDuplicatePlatformIds([
                ...rows,
                {
                    oa_platform_id: 1,
                    platform_code: 'a',
                    platform_name: 'A',
                    impressions_input: '1',
                },
            ]),
        ).toBe(true);
        expect(
            hasDuplicateTargetingCodes([
                {
                    oa_targeting_definition_id: 1,
                    targeting_code: 'geo',
                    targeting_name: null,
                    targeting_category: null,
                    tkp_surcharge_snapshot: null,
                    targeting_revision_number: null,
                },
                {
                    oa_targeting_definition_id: 2,
                    targeting_code: 'geo',
                    targeting_name: null,
                    targeting_category: null,
                    tkp_surcharge_snapshot: null,
                    targeting_revision_number: null,
                },
            ]),
        ).toBe(true);
    });

    it('blocks active targeting when historical same code selected', () => {
        expect(
            isActiveTargetingBlockedByHistorical('geo', [
                {
                    oa_targeting_definition_id: 9,
                    targeting_code: 'geo',
                    targeting_name: 'Old',
                    targeting_category: null,
                    tkp_surcharge_snapshot: '1.0000',
                    targeting_revision_number: 1,
                    historical: true,
                },
            ]),
        ).toBe(true);
    });

    it('detects rebind and restores original pin', () => {
        const base = {
            ...emptyOaTkpDraftFields(2026),
            calculation_method_key: 'tkp',
            id: 5,
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: 1,
            saved_advertising_medium_id: 2,
            saved_price_year: 2026,
            expected_oa_tkp_price_list_id: 10,
            original_oa_tkp_price_list_id: 10,
            original_oa_list_tkp: '12.0000',
            original_oa_minimum_tkp: '8.0000',
            original_oa_price_year: 2026,
            list_tkp_snapshot: '15.0000',
            minimum_tkp_snapshot: '9.0000',
        };

        expect(needsOaTkpRebind(base)).toBe(false);
        expect(needsOaTkpRebind({ ...base, price_year: 2027 })).toBe(true);

        const restored = restoreOriginalOaTkpPin({
            ...base,
            expected_oa_tkp_price_list_id: 99,
        });
        expect(restored.expected_oa_tkp_price_list_id).toBe(10);
        expect(restored.list_tkp_snapshot).toBe('12.0000');
    });

    it('builds hygienic TKP payload without spot fields', () => {
        const payload = buildOaTkpPositionPayload({
            ...emptyOaTkpDraftFields(2026),
            calculation_method_key: 'tkp',
            client_key: 'c1',
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: null,
            saved_advertising_medium_id: null,
            saved_price_year: null,
            expected_oa_tkp_price_list_id: 11,
            impressions_input: '100000',
            basis_tkp: '10.0000',
            oa_platform_allocations: [
                {
                    oa_platform_id: 3,
                    platform_code: 'p',
                    platform_name: 'P',
                    impressions_input: '100000',
                },
            ],
            oa_targeting_selections: [
                {
                    oa_targeting_definition_id: 4,
                    targeting_code: 'geo',
                    targeting_name: 'Geo',
                    targeting_category: 'Geo',
                    tkp_surcharge_snapshot: '2.0000',
                    targeting_revision_number: 1,
                },
            ],
            position_discounts: [],
            dynamic_field_values: { period_open: true },
        });

        expect(payload).toMatchObject({
            calculation_method_key: 'tkp',
            impressions: 100_000,
            basis_tkp: '10.0000',
            expected_oa_tkp_price_list_id: 11,
        });
        expect(payload).not.toHaveProperty('length_seconds');
        expect(payload).not.toHaveProperty('time_ranges');
        expect(payload).not.toHaveProperty('planner_entries');
        expect(payload).not.toHaveProperty('components');
        expect(payload).not.toHaveProperty('production_lines');
        expect(draftHasTkpPositions([{ calculation_method_key: 'tkp' }])).toBe(
            true,
        );
    });

    it('preview readiness requires complete allocation', () => {
        const incomplete = {
            ...emptyOaTkpDraftFields(2026),
            calculation_method_key: 'tkp',
            inventory_id: 1,
            advertising_medium_id: 2,
            price_year: 2026,
            saved_inventory_id: null,
            saved_advertising_medium_id: null,
            saved_price_year: null,
            expected_oa_tkp_price_list_id: 11,
            impressions_input: '100000',
            basis_tkp: '10.0000',
            oa_platform_allocations: [
                {
                    oa_platform_id: 3,
                    platform_code: 'p',
                    platform_name: 'P',
                    impressions_input: '50000',
                },
            ],
        };
        expect(isOaTkpPreviewReady(incomplete)).toBe(false);

        incomplete.oa_platform_allocations[0]!.impressions_input = '100000';
        expect(isOaTkpPreviewReady(incomplete)).toBe(true);
    });
});
