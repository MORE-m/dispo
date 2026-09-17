import { describe, expect, it } from 'vitest';
import { rebindPositionOnInventoryChange } from '@/lib/wizard-inventory-rebind';

const catalog = {
    current_price_year: 2026,
    next_price_year: 2027,
    price_years_by_inventory: {
        '1': [
            {
                year: 2026,
                price_list_id: 101,
                version: 'shared-v1',
                status: 'active',
                is_default: true,
                available: true,
            },
        ],
        '2': [
            {
                year: 2026,
                price_list_id: 202,
                version: 'individual-v1',
                status: 'active',
                is_default: true,
                available: true,
            },
        ],
        '3': [
            {
                year: 2027,
                price_list_id: 303,
                version: 'other-v1',
                status: 'active',
                is_default: true,
                available: true,
            },
        ],
    },
    inventories: [
        { id: 1, name: 'Radio Hamburg Shared', code: 'RHS' },
        { id: 2, name: 'Radio Hamburg Individual', code: 'RHI' },
        { id: 3, name: 'Ohne 2026', code: 'X' },
    ],
    media: [
        {
            id: 10,
            default_length_seconds: 30,
            calculation_method_options: {
                medium_id: 10,
                source: 'test',
                default_calculation_method_key: 'average',
                methods: [
                    {
                        key: 'average',
                        name: 'Durchschnitt',
                        help_text: null,
                        is_default: true,
                    },
                    {
                        key: 'calendar',
                        name: 'Kalender',
                        help_text: null,
                        is_default: false,
                    },
                ],
            },
        },
    ],
    rules: [
        {
            inventory_id: 1,
            advertising_medium_id: 10,
            default_length_seconds: 30,
            component_calculation_strategy: 'shared_total_length',
        },
        {
            inventory_id: 2,
            advertising_medium_id: 10,
            default_length_seconds: 30,
            component_calculation_strategy: 'individual',
        },
        {
            inventory_id: 3,
            advertising_medium_id: 10,
            default_length_seconds: 30,
            component_calculation_strategy: 'shared_total_length',
        },
    ],
};

const components = [
    {
        role: 'main_spot' as const,
        label: 'Hauptspot',
        length_seconds: 20,
        sort: 0,
    },
    {
        role: 'allonge' as const,
        label: 'Allonge',
        length_seconds: 10,
        sort: 1,
    },
];

const plannerEntries = [
    { date: '2026-09-14', hour: 8, spot_count: 10 },
    { date: '2026-09-14', hour: 14, spot_count: 5 },
];

function basePosition(
    inventoryId: number,
    strategy: 'shared_total_length' | 'individual',
) {
    return {
        inventory_id: inventoryId,
        inventory_name: catalog.inventories.find((row) => row.id === inventoryId)
            ?.name,
        inventory_code: catalog.inventories.find((row) => row.id === inventoryId)
            ?.code,
        advertising_medium_id: 10,
        calculation_method_key: 'calendar',
        calculation_method_name: 'Kalender',
        historical_calculation_method_key: null,
        historical_calculation_method_name: null,
        length_seconds: 30,
        components,
        component_calculation_strategy: strategy,
        total_spot_count: 15,
        plan_rows: [],
        time_ranges: [],
        planner_entries: plannerEntries,
        position_discount_percent: '5',
        ae_percent: '0',
        position_discounts: [{ type: 'agency', custom_label: null, percent: '5' }],
        price_year: 2026,
        original_price_year: 2026,
        original_price_list_id: inventoryId === 1 ? 101 : 202,
        original_price_list_version: 'v',
        original_price_list_status: 'active',
        expected_price_list_id: inventoryId === 1 ? 101 : 202,
        price_list_version: 'v',
        price_list_status: 'active',
        schema_fingerprint: 'fp',
    };
}

describe('rebindPositionOnInventoryChange', () => {
    it('behält Calendar-Einträge und rebindet individual → shared', () => {
        const next = rebindPositionOnInventoryChange(
            basePosition(2, 'individual'),
            1,
            catalog,
            catalog.media,
        );

        expect(next).not.toBeNull();
        expect(next?.inventory_id).toBe(1);
        expect(next?.planner_entries).toEqual(plannerEntries);
        expect(next?.total_spot_count).toBe(15);
        expect(next?.components).toEqual(components);
        expect(next?.component_calculation_strategy).toBe('shared_total_length');
        expect(next?.calculation_method_key).toBe('calendar');
        expect(next?.expected_price_list_id).toBe(101);
        expect(next?.price_year).toBe(2026);
        expect(next?.schema_fingerprint).toBeNull();
    });

    it('behält Calendar-Einträge und rebindet shared → individual', () => {
        const next = rebindPositionOnInventoryChange(
            basePosition(1, 'shared_total_length'),
            2,
            catalog,
            catalog.media,
        );

        expect(next?.planner_entries).toEqual(plannerEntries);
        expect(next?.total_spot_count).toBe(15);
        expect(next?.component_calculation_strategy).toBe('individual');
        expect(next?.expected_price_list_id).toBe(202);
    });

    it('löscht Einträge nicht, wenn das Zieljahr im Zielinventar fehlt', () => {
        const next = rebindPositionOnInventoryChange(
            basePosition(1, 'shared_total_length'),
            3,
            catalog,
            catalog.media,
        );

        expect(next?.planner_entries).toEqual(plannerEntries);
        expect(next?.total_spot_count).toBe(15);
        expect(next?.price_year).toBe(2026);
        expect(next?.expected_price_list_id).toBeNull();
    });

    it('behält Average-Zeitraumzeilen bei gleichem Methoden-Key', () => {
        const ranges = [
            {
                start_hour: 8,
                end_hour_exclusive: 9,
                day_group: 'mo_fr',
                spot_count: 10,
            },
        ];
        const position = {
            ...basePosition(1, 'shared_total_length'),
            calculation_method_key: 'average',
            calculation_method_name: 'Durchschnitt',
            planner_entries: [],
            time_ranges: ranges,
            total_spot_count: 10,
        };

        const next = rebindPositionOnInventoryChange(
            position,
            2,
            catalog,
            catalog.media,
        );

        expect(next?.time_ranges).toEqual(ranges);
        expect(next?.total_spot_count).toBe(10);
        expect(next?.component_calculation_strategy).toBe('individual');
    });
});
