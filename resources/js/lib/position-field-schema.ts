/**
 * DF-3-REST-C2: Positions-Feldschema-Nachladen mit Generationen-Schutz.
 * Verhindert, dass verspätete Antworten eines vorherigen Inventars den
 * aktuellen Fingerprint überschreiben.
 */

export type PositionSchemaFetchTarget = {
    clientKey: string;
    advertisingMediumId: number;
    generation: number;
};

export function nextSchemaFetchGeneration(current: number | undefined): number {
    return (current ?? 0) + 1;
}

/**
 * Nur anwenden, wenn Client-Key, Medium und Generation noch zum aktuellen
 * Ladeversuch passen.
 */
export function shouldApplyPositionSchemaResponse(
    pending: PositionSchemaFetchTarget | null | undefined,
    response: PositionSchemaFetchTarget,
): boolean {
    if (pending == null) {
        return false;
    }

    return (
        pending.clientKey === response.clientKey &&
        pending.advertisingMediumId === response.advertisingMediumId &&
        pending.generation === response.generation
    );
}

export function positionNeedsFieldSchema(position: {
    advertising_medium_id: number;
    schema_fingerprint?: string | null;
    field_schema?: unknown;
}): boolean {
    return (
        position.advertising_medium_id > 0 &&
        (position.schema_fingerprint === null ||
            position.schema_fingerprint === undefined ||
            position.schema_fingerprint === '' ||
            position.field_schema == null)
    );
}

export function schemaLoadBlockMessage(
    loading: boolean,
    error: string | null,
): string | null {
    if (error) {
        return error;
    }
    if (loading) {
        return 'Positions-Feldschema wird noch geladen. Bitte kurz warten und erneut speichern.';
    }

    return null;
}
