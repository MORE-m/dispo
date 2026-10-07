/**
 * BL-P5-01a Review-Nachzug: Mediumwechsel hin zu Trailer (swf_trailer).
 *
 * Trailer kennt keine Spot-Komponenten und kein Festpreis-Settlement.
 * Beim Eintritt müssen Komponenten/Strategie entfernt und die Länge aus der
 * inventarspezifischen Trailer-Regel genommen werden (kein Medium-Default-Fallback).
 * Spot-Komponenten dürfen für Trailer nicht aus dem Stash wiederhergestellt werden;
 * der Stash bleibt für den Rückwechsel auf Spot/Tandem/Tridem erhalten.
 */

import {
    buildProfileComponents,
    isForcedProfile,
    resolveStrategyFromRule,
    totalComponentLength,
    type ComponentCalculationStrategy,
    type ProfileSlot,
    type SpotComponentDraft,
    type SpotComponentProfile,
} from '@/lib/spot-components';

export const SWF_TRAILER_KIND = 'swf_trailer';

export type TrailerComponentProfiles = Record<
    SpotComponentProfile,
    { label: string; slots: ProfileSlot[] }
>;

export type TrailerMediumChangeCatalog = {
    media: Array<{
        id: number;
        kind?: string | null;
        default_length_seconds: number;
        component_profile?: SpotComponentProfile | null;
    }>;
    rules: Array<{
        inventory_id: number;
        advertising_medium_id: number;
        default_length_seconds: number | null;
        component_calculation_strategy?: string | null;
    }>;
};

export type TrailerMediumChangePosition = {
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    length_seconds: number;
    components: SpotComponentDraft[];
    component_calculation_strategy: ComponentCalculationStrategy | null;
};

export type ComponentStashEntry = {
    components: SpotComponentDraft[];
    strategy: ComponentCalculationStrategy | null;
};

export function isSwfTrailerMedium(
    catalog: TrailerMediumChangeCatalog,
    mediumId: number,
): boolean {
    return (
        catalog.media.find((item) => item.id === mediumId)?.kind ===
        SWF_TRAILER_KIND
    );
}

function profileForMedium(
    catalog: TrailerMediumChangeCatalog,
    mediumId: number,
): SpotComponentProfile | null {
    const profile = catalog.media.find(
        (item) => item.id === mediumId,
    )?.component_profile;

    return profile === 'tandem' || profile === 'tridem' ? profile : null;
}

export function componentStashKey(
    clientKey: string,
    profile: SpotComponentProfile | null,
): string {
    return `${clientKey}:${profile ?? 'optional'}`;
}

function ruleFor(
    catalog: TrailerMediumChangeCatalog,
    inventoryId: number,
    mediumId: number,
) {
    return catalog.rules.find(
        (item) =>
            item.inventory_id === inventoryId &&
            item.advertising_medium_id === mediumId,
    );
}

/**
 * Länge für Trailer: nur inventarspezifische Regel – kein Medium-Default (30 s).
 * Fehlt die Regel-Länge, bleibt die bisherige Länge als UI-Zwischenstand;
 * Preview/Save fail-closed serverseitig.
 */
export function trailerLengthFromRule(
    ruleLength: number | null | undefined,
    fallbackLength: number,
): number {
    return ruleLength !== null && ruleLength !== undefined
        ? ruleLength
        : fallbackLength;
}

export function resolveComponentsAfterMediumChange(
    item: TrailerMediumChangePosition,
    newMediumId: number,
    catalog: TrailerMediumChangeCatalog,
    componentProfiles: TrailerComponentProfiles,
    stash: Map<string, ComponentStashEntry>,
    inventoryId: number = item.inventory_id,
): {
    components: SpotComponentDraft[];
    strategy: ComponentCalculationStrategy | null;
    length_seconds: number;
} {
    const medium = catalog.media.find(
        (candidate) => candidate.id === newMediumId,
    );
    if (!medium) {
        return {
            components: item.components,
            strategy: item.component_calculation_strategy,
            length_seconds: item.length_seconds,
        };
    }

    const oldProfile = profileForMedium(catalog, item.advertising_medium_id);
    const newProfile = profileForMedium(catalog, newMediumId);
    const rule = ruleFor(catalog, inventoryId, newMediumId);

    if (item.components.length > 0 || item.component_calculation_strategy) {
        stash.set(componentStashKey(item.client_key, oldProfile), {
            components: item.components,
            strategy: item.component_calculation_strategy,
        });
    }

    // Trailer: nie Komponenten/Strategie, nie Stash-Restore optionaler Spot-Komponenten.
    if (medium.kind === SWF_TRAILER_KIND) {
        return {
            components: [],
            strategy: null,
            length_seconds: trailerLengthFromRule(
                rule?.default_length_seconds,
                item.length_seconds,
            ),
        };
    }

    let components: SpotComponentDraft[] = [];
    let strategy: ComponentCalculationStrategy | null = null;

    if (isForcedProfile(newProfile)) {
        const meta = componentProfiles[newProfile];
        const stashed = stash.get(
            componentStashKey(item.client_key, newProfile),
        );
        const previous =
            stashed && stashed.components.length > 0
                ? stashed.components
                : item.components;
        components = buildProfileComponents(newProfile, meta.slots, previous);
        strategy = 'shared_total_length';
    } else if (
        isForcedProfile(oldProfile) ||
        isSwfTrailerMedium(catalog, item.advertising_medium_id)
    ) {
        // Rückwechsel von Tandem/Tridem oder Trailer → optionaler Spot: Stash wiederherstellen.
        const stashed = stash.get(componentStashKey(item.client_key, null));
        components = stashed?.components ?? [];
        strategy = stashed?.strategy ?? null;
    } else {
        components = item.components.length > 0 ? item.components : [];
        strategy = item.components.length
            ? (item.component_calculation_strategy ??
              resolveStrategyFromRule(rule?.component_calculation_strategy))
            : null;
    }

    const length_seconds =
        components.length > 0
            ? totalComponentLength(components)
            : (rule?.default_length_seconds ?? medium.default_length_seconds);

    return { components, strategy, length_seconds };
}

/** Settlement-Reset beim Wechsel auf Trailer (kein Festpreis). */
export function trailerSettlementReset(): {
    pricing_settlement_mode: 'normal';
    fixed_price_nn_input: string;
} {
    return {
        pricing_settlement_mode: 'normal',
        fixed_price_nn_input: '',
    };
}
