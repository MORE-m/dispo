import { describe, expect, it } from 'vitest';
import { allongeConfigurationHint } from '@/lib/wizard-allonge-confirmation-hint';

const catalog = {
    media: [
        { id: 1, kind: 'swf_allonge' },
        { id: 2, kind: 'spot_classic' },
    ],
    rules: [
        {
            inventory_id: 10,
            advertising_medium_id: 1,
            swf_allonge_confirmed_at: null,
        },
        {
            inventory_id: 11,
            advertising_medium_id: 1,
            swf_allonge_confirmed_at: '2026-10-14T10:00:00+02:00',
        },
    ],
};

describe('allongeConfigurationHint', () => {
    it('sperrt eine neue unbestätigte Position', () => {
        const hint = allongeConfigurationHint(catalog, {
            inventory_id: 10,
            advertising_medium_id: 1,
            price_year: 2026,
            saved_price_year: null,
        });

        expect(hint?.tone).toBe('block');
        expect(hint?.text).toContain('Kalkulation ist gesperrt');
    });

    it('zeigt bei unveränderter gespeicherter Position keine Sperre', () => {
        const hint = allongeConfigurationHint(catalog, {
            id: 5,
            inventory_id: 10,
            advertising_medium_id: 1,
            saved_inventory_id: 10,
            saved_advertising_medium_id: 1,
            price_year: 2027,
            saved_price_year: 2027,
        });

        expect(hint?.tone).toBe('snapshot');
        expect(hint?.text).toContain('bleiben gültig');
        expect(hint?.text).not.toContain('gesperrt');
    });

    it('verlangt beim Inventar-, Medium- und Preisjahr-Wechsel wieder die Live-Bestätigung', () => {
        const saved = {
            id: 5,
            inventory_id: 10,
            advertising_medium_id: 1,
            saved_inventory_id: 10,
            saved_advertising_medium_id: 1,
            price_year: 2026,
            saved_price_year: 2026,
        };

        expect(
            allongeConfigurationHint(catalog, {
                ...saved,
                saved_inventory_id: 11,
            })?.tone,
        ).toBe('block');
        expect(
            allongeConfigurationHint(catalog, {
                ...saved,
                saved_advertising_medium_id: 9,
            })?.tone,
        ).toBe('block');
        expect(
            allongeConfigurationHint(catalog, { ...saved, price_year: 2027 })
                ?.tone,
        ).toBe('block');
    });

    it('behandelt einen abweichenden Preislisten-Pin nicht als Live-Rebind', () => {
        const hint = allongeConfigurationHint(catalog, {
            id: 5,
            inventory_id: 10,
            advertising_medium_id: 1,
            saved_inventory_id: 10,
            saved_advertising_medium_id: 1,
            price_year: 2027,
            saved_price_year: 2027,
        });

        expect(hint?.tone).toBe('snapshot');
    });

    it('bleibt stumm, wenn die Kombination bestätigt ist oder das Medium kein Allonge ist', () => {
        expect(
            allongeConfigurationHint(catalog, {
                id: 5,
                inventory_id: 11,
                advertising_medium_id: 1,
                saved_inventory_id: 11,
                saved_advertising_medium_id: 1,
                price_year: 2026,
                saved_price_year: 2026,
            }),
        ).toBeNull();
        expect(
            allongeConfigurationHint(catalog, {
                inventory_id: 10,
                advertising_medium_id: 2,
            }),
        ).toBeNull();
    });
});
