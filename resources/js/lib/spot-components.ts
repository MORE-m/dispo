/**
 * BL-P4-02c / AT-04: reine Hilfsfunktionen für Spot-Komponenten im Wizard.
 */

export type SpotComponentRole = 'main_spot' | 'allonge';

export type SpotComponentDraft = {
    role: SpotComponentRole;
    label: string;
    length_seconds: number;
    sort: number;
};

export type ComponentCalculationStrategy = 'shared_total_length' | 'individual';

export const COMPONENT_STRATEGY_LABELS: Record<
    ComponentCalculationStrategy,
    string
> = {
    shared_total_length: 'Gemeinsame Gesamtlänge',
    individual: 'Komponenten einzeln berechnen',
};

export function totalComponentLength(components: SpotComponentDraft[]): number {
    return components.reduce(
        (sum, component) => sum + Math.max(0, component.length_seconds || 0),
        0,
    );
}

export function activateComponentsFromLength(
    lengthSeconds: number,
): SpotComponentDraft[] {
    const main = Math.max(1, Math.floor(lengthSeconds) || 30);

    return [
        {
            role: 'main_spot',
            label: 'Hauptspot',
            length_seconds: main,
            sort: 0,
        },
    ];
}

export function addAllonge(
    components: SpotComponentDraft[],
    allongeSeconds = 10,
): SpotComponentDraft[] {
    const base =
        components.length > 0
            ? components.filter((c) => c.role !== 'allonge')
            : activateComponentsFromLength(30);

    return [
        ...base.map((component, index) => ({
            ...component,
            sort: index,
        })),
        {
            role: 'allonge',
            label: 'Allonge',
            length_seconds: Math.max(1, allongeSeconds),
            sort: base.length,
        },
    ];
}

export function removeAllonge(
    components: SpotComponentDraft[],
): SpotComponentDraft[] {
    return components
        .filter((component) => component.role !== 'allonge')
        .map((component, index) => ({ ...component, sort: index }));
}

export function updateComponentLength(
    components: SpotComponentDraft[],
    role: SpotComponentRole,
    lengthSeconds: number,
): SpotComponentDraft[] {
    const nextLength = Math.max(1, Math.floor(lengthSeconds) || 1);

    return components.map((component) =>
        component.role === role
            ? { ...component, length_seconds: nextLength }
            : component,
    );
}

export function hasAllonge(components: SpotComponentDraft[]): boolean {
    return components.some((component) => component.role === 'allonge');
}

export function resolveStrategyFromRule(
    ruleStrategy: string | null | undefined,
): ComponentCalculationStrategy {
    if (ruleStrategy === 'individual') {
        return 'individual';
    }

    return 'shared_total_length';
}
