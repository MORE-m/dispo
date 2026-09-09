/**
 * DF-3.3b: hält Assignment-Prozessauswahl konsistent zur Feldset-Gültigkeit.
 * Serverseitige Guards bleiben die Autorität.
 */

export type AssignmentProcessValue = 'calculation' | 'dispo_order' | 'both';

const PREFERRED_DEFAULT: AssignmentProcessValue = 'calculation';

/**
 * Zulässige Assignment-Prozesse für eine Feldset-Gültigkeit.
 */
export function allowedAssignmentProcessValues(
    fieldSetAppliesTo: string | null | undefined,
): AssignmentProcessValue[] {
    switch (fieldSetAppliesTo) {
        case 'calculation':
            return ['calculation'];
        case 'dispo_order':
            return ['dispo_order'];
        case 'both':
            return ['calculation', 'dispo_order', 'both'];
        default:
            return [];
    }
}

/**
 * Liefert einen gültigen Prozesswert für das gewählte Feldset.
 * Behält den aktuellen Wert, falls er weiterhin zulässig ist.
 */
export function resolveAssignmentProcess(
    fieldSetAppliesTo: string | null | undefined,
    currentProcess?: string | null,
): string {
    const allowed = allowedAssignmentProcessValues(fieldSetAppliesTo);

    if (allowed.length === 0) {
        return '';
    }

    if (
        currentProcess !== null &&
        currentProcess !== undefined &&
        currentProcess !== '' &&
        allowed.includes(currentProcess as AssignmentProcessValue)
    ) {
        return currentProcess;
    }

    if (allowed.includes(PREFERRED_DEFAULT)) {
        return PREFERRED_DEFAULT;
    }

    return allowed[0]!;
}

/**
 * Filtert Prozess-Select-Optionen anhand der Feldset-Gültigkeit.
 */
export function filterAssignmentProcessOptions<T extends { value: string }>(
    processOptions: T[],
    fieldSetAppliesTo: string | null | undefined,
): T[] {
    const allowed = new Set(allowedAssignmentProcessValues(fieldSetAppliesTo));

    if (allowed.size === 0) {
        return [];
    }

    return processOptions.filter((option) =>
        allowed.has(option.value as AssignmentProcessValue),
    );
}
