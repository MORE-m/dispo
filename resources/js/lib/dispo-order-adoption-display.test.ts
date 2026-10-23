import { describe, expect, it } from 'vitest';
import {
    formatDispoAdoptionPositionDetail,
    isOaTkpAdoptionPosition,
} from '@/lib/dispo-order-adoption-display';

describe('dispo-order-adoption-display', () => {
    it('detects OA-TKP adoption positions', () => {
        expect(
            isOaTkpAdoptionPosition({
                calculation_method_key: 'tkp',
                engine_profile_key: 'oa_tkp',
            }),
        ).toBe(true);
        expect(
            isOaTkpAdoptionPosition({
                calculation_method_key: 'spot_classic',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(false);
    });

    it('formats spot detail with length and timing', () => {
        expect(
            formatDispoAdoptionPositionDetail({
                calculation_method_key: 'spot_classic',
                engine_profile_key: 'spot_classic',
                length_seconds: 30,
                total_spot_count: 10,
                time_ranges: [
                    {
                        start_hour: 8,
                        end_hour_exclusive: 10,
                        spot_count: 10,
                    },
                ],
            }),
        ).toContain('10 Spots');
        expect(
            formatDispoAdoptionPositionDetail({
                calculation_method_key: 'spot_classic',
                engine_profile_key: 'spot_classic',
                length_seconds: 30,
                total_spot_count: 10,
                time_ranges: [
                    {
                        start_hour: 8,
                        end_hour_exclusive: 10,
                        spot_count: 10,
                    },
                ],
            }),
        ).toContain('30s');
    });

    it('formats OA detail with impressions and final TKP without spot length', () => {
        const detail = formatDispoAdoptionPositionDetail({
            calculation_method_key: 'tkp',
            engine_profile_key: 'oa_tkp',
            impressions: 250_000,
            final_tkp: '12.5000',
            length_seconds: 0,
            total_spot_count: 0,
            time_ranges: [],
        });

        expect(detail).toContain('250.000 Impressions');
        expect(detail).toContain('TKP');
        expect(detail).toContain('12,5000 €');
        expect(detail).not.toContain('Spots');
        expect(detail).not.toContain('0s');
        expect(detail).not.toMatch(/\b0\s*Spots\b/);
    });
});
