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

/**
 * Loading-Flag nur löschen, wenn dieser Fetch noch der aktive Versuch ist.
 * Sonst kann ein verspätetes finally eines älteren Generationen-Laufs den
 * Ladezustand eines neueren Versuchs vorzeitig beenden (CI: stuck Schema).
 */
export function shouldClearSchemaLoadingForGeneration(
    pending: PositionSchemaFetchTarget | null | undefined,
    completed: PositionSchemaFetchTarget,
): boolean {
    return shouldApplyPositionSchemaResponse(pending, completed);
}

/** Max. automatische Retries, wenn Fingerprint fehlt aber kein Fetch mehr läuft. */
export const SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS = 3;

export const SCHEMA_FETCH_FAILED_MESSAGE =
    'Positions-Feldschema konnte nicht geladen werden. Speichern ist ohne Fingerprint nicht möglich.';

export function positionNeedsFieldSchema(position: {
    advertising_medium_id: number | null;
    schema_fingerprint?: string | null;
    field_schema?: unknown;
}): boolean {
    const mediumId = position.advertising_medium_id;
    return (
        mediumId !== null &&
        mediumId > 0 &&
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
