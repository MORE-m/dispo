import { describe, expect, it } from 'vitest';
import {
    formatDispoShowPositionMeta,
    formatOaPriceListIdentity,
    isOaTkpDispoShowPosition,
} from '@/lib/dispo-order-oa-show-display';

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
                calculation_method_key: 'spot_classic',
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
            calculation_method_key: 'spot_classic',
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
});
