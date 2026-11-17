import { describe, expect, it } from 'vitest';
import { shouldShowSpotPriceYearControls } from '@/lib/spot-price-year-visibility';

describe('shouldShowSpotPriceYearControls', () => {
    it('zeigt Spot-Preisjahr für Spot-/SWF-Methoden', () => {
        expect(
            shouldShowSpotPriceYearControls({
                calculation_method_key: 'average',
            }),
        ).toBe(true);
        expect(
            shouldShowSpotPriceYearControls({
                calculation_method_key: 'calendar',
            }),
        ).toBe(true);
    });

    it('blendet Spot-Preisjahr im OA-TKP-Kontext aus', () => {
        expect(
            shouldShowSpotPriceYearControls({
                calculation_method_key: 'tkp',
            }),
        ).toBe(false);
    });

    it('blendet Spot-Preisjahr bei Free Position / Native aus', () => {
        expect(
            shouldShowSpotPriceYearControls({
                calculation_method_key: 'free_position',
            }),
        ).toBe(false);
    });
});
