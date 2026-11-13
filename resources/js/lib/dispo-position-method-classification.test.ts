import { describe, expect, it } from 'vitest';
import {
    classifyDispoMethodPair,
    isFreePositionMethodPair,
    isInconsistentMethodPair,
    isOaTkpMethodPair,
    METHOD_INCONSISTENCY_MESSAGE,
    METHOD_KIND_FREE_POSITION,
    METHOD_KIND_INCONSISTENT,
    METHOD_KIND_OA_TKP,
    METHOD_KIND_SPOT_FAMILY,
} from '@/lib/dispo-position-method-classification';

describe('dispo-position-method-classification', () => {
    it('classifies valid OA as AND pair only', () => {
        expect(classifyDispoMethodPair('oa_tkp', 'tkp')).toBe(METHOD_KIND_OA_TKP);
        expect(
            isOaTkpMethodPair({
                calculation_method_key: 'tkp',
                engine_profile_key: 'oa_tkp',
            }),
        ).toBe(true);
    });

    it('rejects OR half-pairs as inconsistent', () => {
        expect(classifyDispoMethodPair('oa_tkp', 'average')).toBe(
            METHOD_KIND_INCONSISTENT,
        );
        expect(classifyDispoMethodPair('spot_classic', 'tkp')).toBe(
            METHOD_KIND_INCONSISTENT,
        );
        expect(
            isOaTkpMethodPair({
                calculation_method_key: 'tkp',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(false);
        expect(
            isInconsistentMethodPair({
                calculation_method_key: 'tkp',
                engine_profile_key: null,
            }),
        ).toBe(true);
    });

    it('classifies known spot/SWF pairs and legacy nulls', () => {
        expect(classifyDispoMethodPair(null, null)).toBe(
            METHOD_KIND_SPOT_FAMILY,
        );
        expect(classifyDispoMethodPair('spot_classic', 'average')).toBe(
            METHOD_KIND_SPOT_FAMILY,
        );
        expect(classifyDispoMethodPair('swf_trailer', 'calendar')).toBe(
            METHOD_KIND_SPOT_FAMILY,
        );
    });

    it('prefers server method_classification when present', () => {
        expect(
            isOaTkpMethodPair({
                method_classification: METHOD_KIND_OA_TKP,
                calculation_method_key: 'average',
                engine_profile_key: 'spot_classic',
            }),
        ).toBe(true);
        expect(
            isInconsistentMethodPair({
                method_classification: METHOD_KIND_INCONSISTENT,
                calculation_method_key: 'tkp',
                engine_profile_key: 'oa_tkp',
            }),
        ).toBe(true);
    });

    it('classifies Free Position as AND pair only', () => {
        expect(classifyDispoMethodPair('free_position', 'free_position')).toBe(
            METHOD_KIND_FREE_POSITION,
        );
        expect(
            isFreePositionMethodPair({
                engine_profile_key: 'free_position',
                calculation_method_key: 'free_position',
            }),
        ).toBe(true);
        expect(
            isOaTkpMethodPair({
                engine_profile_key: 'free_position',
                calculation_method_key: 'free_position',
            }),
        ).toBe(false);
    });

    it('rejects half and wrong Free Position pairs as inconsistent', () => {
        const wrong: Array<[string | null, string | null]> = [
            ['free_position', 'average'],
            ['spot_classic', 'free_position'],
            ['free_position', 'tkp'],
            ['oa_tkp', 'free_position'],
            ['free_position', null],
            [null, 'free_position'],
        ];
        for (const [profile, method] of wrong) {
            expect(classifyDispoMethodPair(profile, method)).toBe(
                METHOD_KIND_INCONSISTENT,
            );
        }
    });

    it('uses neutral inconsistency wording', () => {
        expect(METHOD_INCONSISTENCY_MESSAGE).toBe(
            'Die Methoden-/Engine-Kombination dieser Position ist ungültig und kann nicht an die Disposition übergeben werden.',
        );
    });

    it('prefers server free_position classification', () => {
        expect(
            isFreePositionMethodPair({
                method_classification: METHOD_KIND_FREE_POSITION,
                engine_profile_key: null,
                calculation_method_key: null,
            }),
        ).toBe(true);
    });
});
