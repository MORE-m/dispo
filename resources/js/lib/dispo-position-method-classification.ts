import { OA_TKP_METHOD_KEY } from '@/lib/oa-tkp-wizard';

/** Spiegelt App\Support\DispoOrder\DispoPositionMethodClassifier (ohne volle Registry). */
export const METHOD_KIND_OA_TKP = 'oa_tkp' as const;
export const METHOD_KIND_SPOT_FAMILY = 'spot_family' as const;
export const METHOD_KIND_INCONSISTENT = 'inconsistent' as const;

export type DispoMethodClassification =
    | typeof METHOD_KIND_OA_TKP
    | typeof METHOD_KIND_SPOT_FAMILY
    | typeof METHOD_KIND_INCONSISTENT;

export const METHOD_INCONSISTENCY_MESSAGE =
    'Die Methoden-/Engine-Kombination dieser Position ist ungültig und kann weder als Spot noch als Online-Audio übernommen werden.';

/** Bekannte Nicht-OA-Registry-Paare (Spiegel EngineProfileRegistry, Stand D3d-1). */
const SPOT_FAMILY_PAIRS: ReadonlyArray<readonly [string, string]> = [
    ['spot_classic', 'average'],
    ['spot_classic', 'calendar'],
    ['spot_classic', 'fixed_price'],
    ['swf_trailer', 'average'],
    ['swf_trailer', 'calendar'],
    ['swf_allonge', 'average'],
    ['swf_allonge', 'calendar'],
    ['swf_promo', 'average'],
    ['swf_promo', 'calendar'],
    ['swf_contest', 'average'],
    ['swf_contest', 'calendar'],
];

function normalize(value: string | null | undefined): string | null {
    if (value == null) {
        return null;
    }
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
}

function isKnownSpotFamilyPair(profile: string, method: string): boolean {
    return SPOT_FAMILY_PAIRS.some(([p, m]) => p === profile && m === method);
}

/**
 * Klassifikation aus Keys. Serverseitiges `method_classification` hat Vorrang,
 * wenn vom API geliefert.
 */
export function classifyDispoMethodPair(
    engineProfileKey: string | null | undefined,
    calculationMethodKey: string | null | undefined,
): DispoMethodClassification {
    const profile = normalize(engineProfileKey);
    const method = normalize(calculationMethodKey);

    if (profile === null && method === null) {
        return METHOD_KIND_SPOT_FAMILY;
    }

    if (profile === null || method === null) {
        return METHOD_KIND_INCONSISTENT;
    }

    const isOaProfile = profile === 'oa_tkp';
    const isOaMethod = method === OA_TKP_METHOD_KEY;

    if (isOaProfile || isOaMethod) {
        if (isOaProfile && isOaMethod) {
            return METHOD_KIND_OA_TKP;
        }
        return METHOD_KIND_INCONSISTENT;
    }

    if (isKnownSpotFamilyPair(profile, method)) {
        return METHOD_KIND_SPOT_FAMILY;
    }

    return METHOD_KIND_INCONSISTENT;
}

export function resolveDispoMethodClassification(position: {
    method_classification?: DispoMethodClassification | null;
    engine_profile_key?: string | null;
    calculation_method_key?: string | null;
}): DispoMethodClassification {
    if (
        position.method_classification === METHOD_KIND_OA_TKP ||
        position.method_classification === METHOD_KIND_SPOT_FAMILY ||
        position.method_classification === METHOD_KIND_INCONSISTENT
    ) {
        return position.method_classification;
    }

    return classifyDispoMethodPair(
        position.engine_profile_key,
        position.calculation_method_key,
    );
}

export function isOaTkpMethodPair(position: {
    method_classification?: DispoMethodClassification | null;
    engine_profile_key?: string | null;
    calculation_method_key?: string | null;
}): boolean {
    return resolveDispoMethodClassification(position) === METHOD_KIND_OA_TKP;
}

export function isInconsistentMethodPair(position: {
    method_classification?: DispoMethodClassification | null;
    engine_profile_key?: string | null;
    calculation_method_key?: string | null;
}): boolean {
    return (
        resolveDispoMethodClassification(position) === METHOD_KIND_INCONSISTENT
    );
}
