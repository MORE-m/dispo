/**
 * ADV-001c4b: Wizard-Auswahl für neue Positionen.
 *
 * Buchbarkeit kommt aus den Backend-Props (is_active +
 * is_bookable_for_new_positions). Inventarregeln prüft der Aufrufer zusätzlich.
 * Kein Code-/Kind-/Engine-Hardcode im Frontend.
 */
export type WizardSelectableMedium = {
    is_active: boolean;
    is_bookable_for_new_positions: boolean;
};

export function isSelectableForNewWizardPositions(
    medium: WizardSelectableMedium,
): boolean {
    return medium.is_bookable_for_new_positions && medium.is_active;
}
