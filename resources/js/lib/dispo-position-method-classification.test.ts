import { describe, expect, it } from 'vitest';
import {
    classifyDispoMethodPair,
    isInconsistentMethodPair,
    isOaTkpMethodPair,
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
});
