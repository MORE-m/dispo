/**
 * BL-P5-02a: Spotproduktion als Zusatzzeile einer Spot-Classic-Durchschnittsposition.
 *
 * Der Client sendet nur Bezeichnung, Menge, Bemerkung und Sortierung. Einzelpreis, Flags und
 * Beträge kommen ausschließlich vom Server (Preview/Gespeichert) und werden nie zurückgeschickt.
 */
export type ProductionLineDraft = {
    client_key: string;
    production_type: 'spot_production';
    label: string;
    /** Eingabe als Text (Komma oder Punkt), Standard 0. */
    quantity: string;
    remark: string;
};

/** Server-Ergebnis je Zeile (read-only). */
export type ProductionLineResult = {
    client_key?: string | null;
    label?: string;
    quantity?: string;
    unit_price?: string;
    line_gross?: string;
    nn_invest?: string;
    is_discountable?: boolean;
    is_ae_eligible?: boolean;
    production_price_list_version?: string | null;
};

export type SavedProductionLine = {
    client_key: string | null;
    production_type?: string;
    label: string;
    quantity: string;
    remark: string | null;
    unit_price?: string;
    line_gross?: string;
};

export const DEFAULT_PRODUCTION_LABEL = 'Spotproduktion';

export function newProductionLine(
    clientKey: string = crypto.randomUUID(),
): ProductionLineDraft {
    return {
        client_key: clientKey,
        production_type: 'spot_production',
        label: DEFAULT_PRODUCTION_LABEL,
        quantity: '0',
        remark: '',
    };
}

export function productionLinesFromSaved(
    lines: SavedProductionLine[] | null | undefined,
): ProductionLineDraft[] {
    return (lines ?? []).map((line) => ({
        client_key: line.client_key ?? crypto.randomUUID(),
        production_type: 'spot_production' as const,
        label: line.label,
        quantity: normalizeQuantityForDisplay(line.quantity),
        remark: line.remark ?? '',
    }));
}

/** "2.0000" → "2", "2.5000" → "2.5". */
export function normalizeQuantityForDisplay(raw: string): string {
    if (!raw.includes('.')) {
        return raw;
    }

    return raw.replace(/0+$/, '').replace(/\.$/, '');
}

/** Payload ohne Preis/Flags/Beträge (Server lehnt diese Felder ab). */
export function productionLinesPayload(
    lines: ProductionLineDraft[] | undefined,
): Array<{
    client_key: string;
    production_type: 'spot_production';
    label: string;
    quantity: string;
    remark: string | null;
    sort: number;
}> {
    return (lines ?? []).map((line, sort) => ({
        client_key: line.client_key,
        production_type: line.production_type,
        label: line.label.trim() === '' ? DEFAULT_PRODUCTION_LABEL : line.label,
        quantity: line.quantity.trim() === '' ? '0' : line.quantity.trim(),
        remark: line.remark.trim() === '' ? null : line.remark,
        sort,
    }));
}

/**
 * Produktion ist nur für Spot Classic × Durchschnitt (normaler Preisabschluss) zulässig.
 * Bestehende Zeilen bleiben bei anderen Auswahlen sichtbar; der Server blockiert, bis sie entfernt sind.
 */
export function productionSupported(input: {
    mediumKind: string | null | undefined;
    methodKey: string | null | undefined;
    settlementMode: string | null | undefined;
}): boolean {
    return (
        (input.mediumKind ?? 'spot_classic') === 'spot_classic' &&
        input.methodKey === 'average' &&
        (input.settlementMode ?? 'normal') === 'normal'
    );
}
