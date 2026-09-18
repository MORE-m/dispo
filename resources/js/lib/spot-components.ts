/**
 * BL-P4-02c / AT-04 / BL-P4-02e: Hilfsfunktionen für Spot-Komponenten im Wizard.
 */

export type SpotComponentRole = 'main_spot' | 'allonge' | 'reminder';

export type SpotComponentProfile = 'tandem' | 'tridem';

export type SpotComponentDraft = {
    role: SpotComponentRole;
    label: string;
    length_seconds: number;
    sort: number;
};

export type ProfileSlot = {
    role: string;
    label: string;
    display_label?: string;
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

const DEFAULT_MAIN_LENGTH = 20;
const DEFAULT_REMINDER_LENGTH = 10;

export function isForcedProfile(
    profile: SpotComponentProfile | null | undefined,
): profile is SpotComponentProfile {
    return profile === 'tandem' || profile === 'tridem';
}

export function derivedAirings(
    unitCount: number,
    unitCountPerUnit: number,
): number {
    return Math.max(0, Math.floor(unitCount) || 0) * unitCountPerUnit;
}

function defaultLengthForRole(role: string): number {
    return role === 'main_spot' ? DEFAULT_MAIN_LENGTH : DEFAULT_REMINDER_LENGTH;
}

function lengthFromPreviousSlot(
    previous: SpotComponentDraft[] | undefined,
    role: string,
    sort: number,
): number {
    if (!previous?.length) {
        return defaultLengthForRole(role);
    }

    const exact = previous.find(
        (component) => component.role === role && component.sort === sort,
    );
    if (exact) {
        return exact.length_seconds;
    }

    if (role === 'main_spot') {
        const main = previous.find(
            (component) => component.role === 'main_spot',
        );
        if (main) {
            return main.length_seconds;
        }
    }

    if (role === 'reminder') {
        const reminders = previous
            .filter((component) => component.role === 'reminder')
            .sort((a, b) => a.sort - b.sort);
        const reminderIndex = sort - 2;
        if (reminderIndex >= 0 && reminders[reminderIndex]) {
            return reminders[reminderIndex].length_seconds;
        }
    }

    return defaultLengthForRole(role);
}

export function buildProfileComponents(
    _profile: SpotComponentProfile,
    slots: ProfileSlot[],
    previous?: SpotComponentDraft[],
): SpotComponentDraft[] {
    return slots.map((slot) => ({
        role: slot.role as SpotComponentRole,
        label: slot.label,
        length_seconds: lengthFromPreviousSlot(previous, slot.role, slot.sort),
        sort: slot.sort,
    }));
}

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

export function updateComponentLengthBySort(
    components: SpotComponentDraft[],
    sort: number,
    lengthSeconds: number,
): SpotComponentDraft[] {
    const nextLength = Math.max(1, Math.floor(lengthSeconds) || 1);

    return components.map((component) =>
        component.sort === sort
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
