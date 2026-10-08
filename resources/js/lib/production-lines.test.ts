import { describe, expect, it } from 'vitest';
import {
    normalizeQuantityForDisplay,
    productionLinesPayload,
    productionSupported,
    type ProductionLineDraft,
} from './production-lines';

describe('production-lines', () => {
    it('sendet nur Bezeichnung, Menge, Bemerkung und Sortierung (kein Preis, keine Flags)', () => {
        const lines: ProductionLineDraft[] = [
            {
                client_key: 'k1',
                production_type: 'spot_production',
                label: '  ',
                quantity: '',
                remark: '',
            },
        ];

        const [payload] = productionLinesPayload(lines);

        expect(Object.keys(payload).sort()).toEqual([
            'client_key',
            'label',
            'production_type',
            'quantity',
            'remark',
            'sort',
        ]);
        expect(payload.label).toBe('Spotproduktion');
        expect(payload.quantity).toBe('0');
        expect(payload.remark).toBeNull();
    });

    it('unterstützt Produktion nur für Spot Classic × Durchschnitt ohne Festpreis', () => {
        expect(
            productionSupported({
                mediumKind: 'spot_classic',
                methodKey: 'average',
                settlementMode: 'normal',
            }),
        ).toBe(true);
        expect(
            productionSupported({
                mediumKind: 'swf_trailer',
                methodKey: 'average',
                settlementMode: 'normal',
            }),
        ).toBe(false);
        expect(
            productionSupported({
                mediumKind: 'spot_classic',
                methodKey: 'calendar',
                settlementMode: 'normal',
            }),
        ).toBe(false);
        expect(
            productionSupported({
                mediumKind: 'spot_classic',
                methodKey: 'average',
                settlementMode: 'fixed_price',
            }),
        ).toBe(false);
    });

    it('normalisiert Server-Mengen für die Anzeige', () => {
        expect(normalizeQuantityForDisplay('2.0000')).toBe('2');
        expect(normalizeQuantityForDisplay('2.5000')).toBe('2.5');
        expect(normalizeQuantityForDisplay('0.0000')).toBe('0');
    });
});
