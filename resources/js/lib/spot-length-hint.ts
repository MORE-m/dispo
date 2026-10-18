/**
 * SPT-010: Single-Spots über 45 Sekunden bleiben plan- und kalkulierbar.
 * Der Wizard zeigt nur einen Hinweis, keine Sperre und keinen Validierungsfehler.
 * Hinweis nur für Medium-Code `spot_single` (PO-SPOT-SINGLE-1).
 */
export const SPT_010_LONG_SPOT_HINT =
    'Hinweis: Single-Spots über 45 Sekunden bleiben plan- und kalkulierbar (SPT-010).';

export function spotLengthSpt010Hint(
    lengthSeconds: number,
    mediumCode?: string | null,
): string | undefined {
    if (mediumCode !== 'spot_single') {
        return undefined;
    }

    return lengthSeconds > 45 ? SPT_010_LONG_SPOT_HINT : undefined;
}
