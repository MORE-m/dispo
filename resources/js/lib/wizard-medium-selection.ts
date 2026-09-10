/**
 * ADV-001c3a: Wizard-Auswahl für neue Positionen bis ADV-001c4.
 *
 * Buchbarkeit allein reicht nicht – nur Spot Classic ist freigegeben.
 * Inventarregeln prüfen den Aufrufer zusätzlich.
 */
export type WizardSelectableMedium = {
    code: string;
    is_active: boolean;
    is_bookable_for_new_positions: boolean;
};

export function isSelectableForNewWizardPositions(
    medium: WizardSelectableMedium,
): boolean {
    return (
        medium.is_bookable_for_new_positions &&
        medium.is_active &&
        medium.code === 'spot_classic'
    );
}
