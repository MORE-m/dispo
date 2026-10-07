import { describe, expect, it } from 'vitest';
import {
    resolveComponentsAfterMediumChange,
    shouldClearTrailerSettlementForTargetMedium,
    trailerLengthFromRule,
    trailerSettlementReset,
    type ComponentStashEntry,
    type TrailerMediumChangeCatalog,
} from '@/lib/wizard-trailer-medium-change';

const catalog: TrailerMediumChangeCatalog = {
    media: [
        {
            id: 1,
            kind: 'spot_classic',
            default_length_seconds: 30,
            component_profile: null,
        },
        {
            id: 2,
            kind: 'swf_trailer',
            default_length_seconds: 30,
            component_profile: null,
        },
        {
            id: 3,
            kind: 'spot_classic',
            default_length_seconds: 30,
            component_profile: 'tandem',
        },
    ],
    rules: [
        {
            inventory_id: 10,
            advertising_medium_id: 1,
            default_length_seconds: 30,
            component_calculation_strategy: 'shared_total_length',
        },
        {
            inventory_id: 10,
            advertising_medium_id: 2,
            default_length_seconds: 20,
            component_calculation_strategy: null,
        },
        {
            inventory_id: 11,
            advertising_medium_id: 2,
            default_length_seconds: 15,
            component_calculation_strategy: null,
        },
        {
            inventory_id: 10,
            advertising_medium_id: 3,
            default_length_seconds: 30,
            component_calculation_strategy: 'shared_total_length',
        },
    ],
};

const profiles = {
    tandem: {
        label: 'Tandem',
        slots: [
            { role: 'main_spot', label: 'Hauptspot', sort: 0 },
            { role: 'allonge', label: 'Allonge', sort: 1 },
        ],
    },
    tridem: {
        label: 'Tridem',
        slots: [
            { role: 'main_spot', label: 'Hauptspot', sort: 0 },
            { role: 'allonge', label: 'Allonge', sort: 1 },
            { role: 'reminder', label: 'Reminder', sort: 2 },
        ],
    },
};

const spotComponents = [
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

describe('resolveComponentsAfterMediumChange (Trailer)', () => {
    it('entfernt Komponenten und Strategie beim Wechsel Spot → Trailer', () => {
        const stash = new Map<string, ComponentStashEntry>();
        const result = resolveComponentsAfterMediumChange(
            {
                client_key: 'p1',
                inventory_id: 10,
                advertising_medium_id: 1,
                length_seconds: 30,
                components: spotComponents,
                component_calculation_strategy: 'shared_total_length',
            },
            2,
            catalog,
            profiles,
            stash,
        );

        expect(result.components).toEqual([]);
        expect(result.strategy).toBeNull();
        expect(result.length_seconds).toBe(20);
        expect(stash.get('p1:optional')?.components).toEqual(spotComponents);
    });

    it('stellt Spot-Komponenten beim Rückwechsel Trailer → Spot aus dem Stash wieder her', () => {
        const stash = new Map<string, ComponentStashEntry>();
        resolveComponentsAfterMediumChange(
            {
                client_key: 'p1',
                inventory_id: 10,
                advertising_medium_id: 1,
                length_seconds: 30,
                components: spotComponents,
                component_calculation_strategy: 'shared_total_length',
            },
            2,
            catalog,
            profiles,
            stash,
        );

        const back = resolveComponentsAfterMediumChange(
            {
                client_key: 'p1',
                inventory_id: 10,
                advertising_medium_id: 2,
                length_seconds: 20,
                components: [],
                component_calculation_strategy: null,
            },
            1,
            catalog,
            profiles,
            stash,
        );

        expect(back.components).toEqual(spotComponents);
        expect(back.strategy).toBe('shared_total_length');
    });

    it('stellt keine Spot-Komponenten aus dem Stash her, wenn Ziel Trailer ist', () => {
        const stash = new Map<string, ComponentStashEntry>([
            [
                'p1:optional',
                {
                    components: spotComponents,
                    strategy: 'shared_total_length',
                },
            ],
        ]);

        const result = resolveComponentsAfterMediumChange(
            {
                client_key: 'p1',
                inventory_id: 10,
                advertising_medium_id: 1,
                length_seconds: 30,
                components: [],
                component_calculation_strategy: null,
            },
            2,
            catalog,
            profiles,
            stash,
        );

        expect(result.components).toEqual([]);
        expect(result.strategy).toBeNull();
        expect(result.length_seconds).toBe(20);
    });

    it('nimmt Trailer-Länge aus der Zielinventar-Regel bei Inventarwechsel', () => {
        const stash = new Map<string, ComponentStashEntry>();
        const result = resolveComponentsAfterMediumChange(
            {
                client_key: 'p1',
                inventory_id: 10,
                advertising_medium_id: 2,
                length_seconds: 20,
                components: [],
                component_calculation_strategy: null,
            },
            2,
            catalog,
            profiles,
            stash,
            11,
        );

        expect(result.length_seconds).toBe(15);
        expect(result.components).toEqual([]);
    });
});

describe('trailerLengthFromRule / trailerSettlementReset', () => {
    it('unterscheidet Regel-Länge von Fallback', () => {
        expect(trailerLengthFromRule(20, 99)).toBe(20);
        expect(trailerLengthFromRule(null, 99)).toBe(99);
        expect(trailerLengthFromRule(undefined, 99)).toBe(99);
    });

    it('setzt Settlement auf normal ohne Festpreis-Input', () => {
        expect(trailerSettlementReset()).toEqual({
            pricing_settlement_mode: 'normal',
            fixed_price_nn_input: '',
        });
    });
});

describe('shouldClearTrailerSettlementForTargetMedium', () => {
    it('ist true nur für Trailer-Zielmedium (direkt oder nach Inventar-Rebind)', () => {
        expect(shouldClearTrailerSettlementForTargetMedium(catalog, 2)).toBe(
            true,
        );
        expect(shouldClearTrailerSettlementForTargetMedium(catalog, 1)).toBe(
            false,
        );
        expect(
            shouldClearTrailerSettlementForTargetMedium(catalog, null),
        ).toBe(false);
        expect(
            shouldClearTrailerSettlementForTargetMedium(catalog, undefined),
        ).toBe(false);
    });
});
