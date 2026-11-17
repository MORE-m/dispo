import { describe, expect, it } from 'vitest';
import {
    createEmptyManualPositionDraft,
    createManualPositionDraftForInventory,
    createNextManualPositionDraft,
    hasManualInventorySelection,
    type ManualPositionCatalog,
} from '@/lib/wizard-manual-position';

function catalogFixture(): ManualPositionCatalog {
    return {
        current_price_year: 2026,
        next_price_year: 2027,
        inventories: [
            {
                id: 10,
                name: 'MORE Hamburg-Kombi',
                code: 'inv_more_hamburg_kombi',
                is_active: true,
            },
            {
                id: 20,
                name: 'Radio Hamburg',
                code: 'RH',
                is_active: true,
            },
            {
                id: 30,
                name: 'ROCK ANTENNE Hamburg',
                code: 'RAH',
                is_active: true,
            },
        ],
        media: [
            {
                id: 100,
                is_active: true,
                is_bookable_for_new_positions: true,
                default_length_seconds: 20,
                calculation_method_options: {
                    medium_id: 100,
                    source: 'test',
                    default_calculation_method_key: 'average',
                    methods: [
                        {
                            key: 'average',
                            name: 'Durchschnitt',
                            help_text: null,
                            is_default: true,
                        },
                    ],
                },
            },
        ],
        rules: [
            {
                inventory_id: 10,
                advertising_medium_id: 100,
                is_active: true,
                default_length_seconds: 20,
            },
            {
                inventory_id: 20,
                advertising_medium_id: 100,
                is_active: true,
                default_length_seconds: 20,
            },
            {
                inventory_id: 30,
                advertising_medium_id: 100,
                is_active: true,
                default_length_seconds: 30,
            },
        ],
        price_years_by_inventory: {
            '10': [
                {
                    year: 2026,
                    price_list_id: 1,
                    available: true,
                    is_default: true,
                    version: 'v1',
                    status: 'active',
                },
            ],
            '20': [
                {
                    year: 2026,
                    price_list_id: 2,
                    available: true,
                    is_default: true,
                    version: 'v1',
                    status: 'active',
                },
            ],
            '30': [
                {
                    year: 2026,
                    price_list_id: 3,
                    available: true,
                    is_default: true,
                    version: 'v1',
                    status: 'active',
                },
            ],
        },
    };
}

describe('wizard-manual-position', () => {
    it('creates a fresh element without inventory default', () => {
        const draft = createEmptyManualPositionDraft(catalogFixture());
        expect(draft.inventory_id).toBeNull();
        expect(draft.advertising_medium_id).toBeNull();
        expect(hasManualInventorySelection(draft)).toBe(false);
    });

    it('builds a position for a chosen inventory without copying prices', () => {
        const draft = createManualPositionDraftForInventory(
            catalogFixture(),
            20,
        );
        expect(draft).not.toBeNull();
        expect(draft?.inventory_id).toBe(20);
        expect(draft?.inventory_name).toBe('Radio Hamburg');
        expect(draft?.advertising_medium_id).toBe(100);
        expect(draft?.position_discounts).toEqual([]);
        expect(draft?.custom_fields).toEqual({});
    });

    it('copies only the previous inventory when adding the next element', () => {
        const next = createNextManualPositionDraft(
            { inventory_id: 20 },
            catalogFixture(),
        );
        expect(next.inventory_id).toBe(20);
        expect(next.inventory_name).toBe('Radio Hamburg');
    });

    it('keeps the next element empty when the previous has no inventory', () => {
        const next = createNextManualPositionDraft(
            { inventory_id: null },
            catalogFixture(),
        );
        expect(next.inventory_id).toBeNull();
        expect(next.advertising_medium_id).toBeNull();
    });

    it('does not invent an inventory when previous is missing', () => {
        const next = createNextManualPositionDraft(null, catalogFixture());
        expect(next.inventory_id).toBeNull();
    });
});
