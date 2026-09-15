import { describe, expect, it } from 'vitest';
import {
    budgetPriceYearOptions,
    defaultPriceYear,
    expectedPriceListIdForYear,
    priceYearDirty,
    priceYearOptionsForInventory,
    requireCurrentPriceYear,
    requireNextPriceYear,
    resolveDisplayedPriceYearOptions,
    restoreOriginalPriceListPin,
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
            year: 2024,
            version: '2024-OLD',
            status: 'archived',
            price_list_id: 99,
        });
        expect(options[0]?.year).toBe(2024);
        expect(options[0]?.available).toBe(false);
        expect(options[0]?.price_list_id).toBe(99);
        expect(options.some((option) => option.year === 2027)).toBe(true);
    });

    it('shows archived pin identity when a newer active revision of the same year exists', () => {
        const options = resolveDisplayedPriceYearOptions(catalog, 10, 2026, {
            year: 2026,
            version: '2026-PIN',
            status: 'archived',
            price_list_id: 77,
        });
        const current = options.find((option) => option.year === 2026);
        expect(current?.price_list_id).toBe(77);
        expect(current?.version).toBe('2026-PIN');
        expect(current?.status).toBe('archived');
        expect(current?.available).toBe(false);
        expect(expectedPriceListIdForYear(catalog, 10, 2026)).toBe(1);
    });

    it('restores original pin after selecting another year', () => {
        const pin = {
            year: 2026,
            price_list_id: 77,
            version: '2026-PIN',
            status: 'archived',
        };
        expect(restoreOriginalPriceListPin(pin)).toEqual({
            price_year: 2026,
            expected_price_list_id: 77,
            price_list_version: '2026-PIN',
            price_list_status: 'archived',
        });
        expect(priceYearDirty(2026, 2027)).toBe(true);
        expect(priceYearDirty(2026, 2026)).toBe(false);
    });

    it('marks dirty only when year actually changes', () => {
        expect(priceYearDirty(2026, 2026)).toBe(false);
        expect(priceYearDirty(2026, 2027)).toBe(true);
        expect(priceYearDirty(null, 2026)).toBe(false);
    });

    it('offers budget next year only when every inventory has it', () => {
        expect(budgetPriceYearOptions(catalog, [10])).toHaveLength(2);
        expect(budgetPriceYearOptions(catalog, [10, 11])).toHaveLength(1);
    });

    it('fails closed without server calendar props', () => {
        expect(() => requireCurrentPriceYear({})).toThrow(
            /current_price_year fehlt/,
        );
        expect(() => requireNextPriceYear({ current_price_year: 2026 })).toThrow(
            /next_price_year fehlt/,
        );
        expect(() => defaultPriceYear({}, 10)).toThrow(
            /current_price_year fehlt/,
        );
    });
});
