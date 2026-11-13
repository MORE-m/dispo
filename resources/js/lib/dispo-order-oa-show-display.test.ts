import { describe, expect, it } from 'vitest';
import {
    formatDispoShowPositionMeta,
    formatOaPriceListIdentity,
    isFreePositionDispoShowPosition,
    isInconsistentDispoShowPosition,
    isOaTkpDispoShowPosition,
} from '@/lib/dispo-order-oa-show-display';
import { METHOD_INCONSISTENCY_MESSAGE } from '@/lib/dispo-position-method-classification';

describe('dispo-order-oa-show-display', () => {
    it('detects OA via method keys not spot sentinels', () => {
        expect(
            isOaTkpDispoShowPosition({
                calculation_method_key: 'tkp',
                engine_profile_key: 'oa_tkp',
            }),
        ).toBe(true);
        expect(
            isOaTkpDispoShowPosition({
                calculation_method_key: 'average',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(false);
        expect(
            isOaTkpDispoShowPosition({
                calculation_method_key: 'tkp',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(false);
    });

    it('formats spot meta with spots and length', () => {
        const meta = formatDispoShowPositionMeta({
            calculation_method_key: 'average',
            engine_profile_key: 'spot_classic',
            spot_method_label: 'Durchschnitt',
            total_spot_count: 10,
            length_seconds: 30,
            price_list_version: '2026.1',
        });
        expect(meta).toContain('10 Spots');
        expect(meta).toContain('30s');
        expect(meta).toContain('Preisliste 2026.1');
    });

    it('formats OA meta with impressions and TKP without spot sentinels', () => {
        const meta = formatDispoShowPositionMeta({
            calculation_method_key: 'tkp',
            engine_profile_key: 'oa_tkp',
            impressions: 250_000,
            final_tkp: '12.5000',
            total_spot_count: 0,
            length_seconds: 0,
        });
        expect(meta).toContain('250.000 Impressions');
        expect(meta).toContain('12,5000 €');
        expect(meta).not.toContain('Spots');
        expect(meta).not.toContain('0s');
    });

    it('formats inconsistent historical pairs without spot fallback', () => {
        expect(
            isInconsistentDispoShowPosition({
                calculation_method_key: 'tkp',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(true);

        const meta = formatDispoShowPositionMeta({
            calculation_method_key: 'tkp',
            engine_profile_key: 'spot_classic',
            spot_method_label: 'TKP',
            total_spot_count: 0,
            length_seconds: 0,
        });
        expect(meta).toBe(METHOD_INCONSISTENCY_MESSAGE);
        expect(meta).not.toMatch(/\b0\s*Spots\b/);
    });

    it('formats frozen price list identity', () => {
        expect(
            formatOaPriceListIdentity({
                calculation_method_key: 'tkp',
                engine_profile_key: 'oa_tkp',
                oa_tkp_price_list_year: 2026,
                oa_tkp_price_list_version: '2026.1',
                oa_tkp_price_list_revision: 2,
                oa_tkp_price_list_id: 9,
            }),
        ).toBe('Jahr 2026 · Version 2026.1 · Revision 2 · ID 9');
    });

    it('formats Free Position meta without spots, length or OA values', () => {
        const free = {
            calculation_method_key: 'free_position',
            engine_profile_key: 'free_position',
            spot_method_label: 'Freie Preisposition',
            total_spot_count: 0,
            length_seconds: 0,
        };

        expect(isFreePositionDispoShowPosition(free)).toBe(true);
        expect(isOaTkpDispoShowPosition(free)).toBe(false);
        expect(isInconsistentDispoShowPosition(free)).toBe(false);

        expect(formatDispoShowPositionMeta(free)).toBe('Freie Preisposition');

        const meta = formatDispoShowPositionMeta({
            ...free,
            media_gross: '5000.00',
            price_list_version: '2026.1',
        });
        expect(meta).toContain('Freie Preisposition · ');
        expect(meta).toContain('5.000,00');
        expect(meta).not.toContain('Spots');
        expect(meta).not.toMatch(/\b0s\b/);
        expect(meta).not.toContain('Preisliste');
        expect(meta).not.toContain('Impressions');
        expect(meta).not.toContain('TKP');
    });

    it('treats wrong Free Position pairs as inconsistent in show', () => {
        expect(
            isInconsistentDispoShowPosition({
                calculation_method_key: 'free_position',
                engine_profile_key: 'oa_tkp',
            }),
        ).toBe(true);
    });
});
