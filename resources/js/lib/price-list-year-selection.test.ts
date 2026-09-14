import { describe, expect, it } from 'vitest';
import {
    budgetPriceYearOptions,
    defaultPriceYear,
    expectedPriceListIdForYear,
    priceYearDirty,
    priceYearOptionsForInventory,
    resolveDisplayedPriceYearOptions,
    type PriceYearCatalog,
} from './price-list-year-selection';

const catalog: PriceYearCatalog = {
    current_price_year: 2026,
    next_price_year: 2027,
    price_years_by_inventory: {
        '10': [
            {
                year: 2026,
                price_list_id: 1,
                version: '2026-A',
                status: 'active',
                is_default: true,
                available: true,
            },
            {
                year: 2027,
                price_list_id: 2,
                version: '2027-A',
                status: 'active',
                is_default: false,
                available: true,
            },
        ],
        '11': [
            {
                year: 2026,
                price_list_id: null,
                version: null,
                status: null,
                is_default: true,
                available: false,
            },
        ],
    },
};

describe('price-list-year-selection', () => {
    it('defaults to current year option', () => {
        expect(defaultPriceYear(catalog, 10)).toBe(2026);
        expect(priceYearOptionsForInventory(catalog, 10)).toHaveLength(2);
        expect(expectedPriceListIdForYear(catalog, 10, 2027)).toBe(2);
    });

    it('hides next year when not offered and keeps missing current year visible', () => {
        expect(priceYearOptionsForInventory(catalog, 11)).toHaveLength(1);
        expect(priceYearOptionsForInventory(catalog, 11)[0]?.available).toBe(
            false,
        );
    });

    it('keeps historical years visible without making them newly selectable defaults', () => {
        const options = resolveDisplayedPriceYearOptions(catalog, 10, 2024, {
            version: '2024-OLD',
            status: 'archived',
            price_list_id: 99,
        });
        expect(options[0]?.year).toBe(2024);
        expect(options[0]?.available).toBe(false);
        expect(options.some((option) => option.year === 2027)).toBe(true);
    });

    it('marks dirty only when year actually changes', () => {
        expect(priceYearDirty(2026, 2026)).toBe(false);
        expect(priceYearDirty(2026, 2027)).toBe(true);
        expect(priceYearDirty(null, 2026)).toBe(false);
        // Neue Position: initiales Jahr als Original → Folgejahrwechsel dirty
        expect(priceYearDirty(2026, 2027)).toBe(true);
    });

    it('offers budget next year only when every inventory has it', () => {
        expect(budgetPriceYearOptions(catalog, [10])).toHaveLength(2);
        expect(budgetPriceYearOptions(catalog, [10, 11])).toHaveLength(1);
    });
});
