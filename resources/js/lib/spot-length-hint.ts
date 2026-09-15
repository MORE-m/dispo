/**
 * SPT-010: Single-Spots über 45 Sekunden bleiben plan- und kalkulierbar.
 * Der Wizard zeigt nur einen Hinweis, keine Sperre und keinen Validierungsfehler.
 */
export const SPT_010_LONG_SPOT_HINT =
    'Hinweis: Single-Spots über 45 Sekunden bleiben plan- und kalkulierbar (SPT-010).';

export function spotLengthSpt010Hint(
    lengthSeconds: number,
): string | undefined {
    return lengthSeconds > 45 ? SPT_010_LONG_SPOT_HINT : undefined;
}
