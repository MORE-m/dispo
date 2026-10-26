/**
 * PO-CALC-TKP-1D3d-2b-2: Client-Hilfen für den operativen OA-Kampagnenzeitraum.
 * Servervalidierung bleibt maßgeblich.
 */

export type OperationalFlightPeriod = {
    start: string;
    end: string;
};

export type HandoffFlightSourceInput = {
    periodOpen: boolean | null | undefined;
    periodOpenCaptured: boolean;
    flight: { start: string | null; end: string | null } | null | undefined;
    flightCaptured: boolean;
};

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

export function isValidIsoDate(value: string): boolean {
    const match = ISO_DATE.exec(value.trim());
    if (match === null) {
        return false;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return false;
    }

    const probe = new Date(Date.UTC(year, month - 1, day));
    return (
        probe.getUTCFullYear() === year &&
        probe.getUTCMonth() === month - 1 &&
        probe.getUTCDate() === day
    );
}

export function normalizeIsoDateInput(value: string): string | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    return isValidIsoDate(trimmed) ? trimmed.slice(0, 10) : null;
}

export function isValidOperationalFlightPair(
    start: string | null,
    end: string | null,
): boolean {
    if (start === null || end === null) {
        return false;
    }

    return end >= start;
}

/**
 * Positionsbezogener Handoff-Flight (Calc-Origin), nur wenn geschlossen und vollständig.
 * Derived Campaign Period wird bewusst nicht verwendet.
 */
export function resolveHandoffFlightForAdopt(
    input: HandoffFlightSourceInput,
): OperationalFlightPeriod | null {
    if (!input.periodOpenCaptured || input.periodOpen !== false) {
        return null;
    }

    if (!input.flightCaptured || input.flight == null) {
        return null;
    }

    const start = normalizeIsoDateInput(input.flight.start ?? '');
    const end = normalizeIsoDateInput(input.flight.end ?? '');
    if (!isValidOperationalFlightPair(start, end)) {
        return null;
    }

    return { start: start!, end: end! };
}

export type ClientFlightValidation =
    | { ok: true; start: string | null; end: string | null }
    | { ok: false; fieldErrors: Record<string, string> };

/**
 * Clientseitige Führung vor dem Speichern (beide leer = entfernen, sonst beide gültig).
 */
export function validateOperationalFlightDraft(
    startRaw: string,
    endRaw: string,
): ClientFlightValidation {
    const startTrimmed = startRaw.trim();
    const endTrimmed = endRaw.trim();

    if (startTrimmed === '' && endTrimmed === '') {
        return { ok: true, start: null, end: null };
    }

    const fieldErrors: Record<string, string> = {};

    if (startTrimmed === '') {
        fieldErrors.operational_flight_start =
            'Startdatum ist erforderlich, wenn ein verbindlicher Zeitraum gespeichert wird.';
    } else if (!isValidIsoDate(startTrimmed)) {
        fieldErrors.operational_flight_start =
            'Startdatum ist kein gültiges Datum.';
    }

    if (endTrimmed === '') {
        fieldErrors.operational_flight_end =
            'Enddatum ist erforderlich, wenn ein verbindlicher Zeitraum gespeichert wird.';
    } else if (!isValidIsoDate(endTrimmed)) {
        fieldErrors.operational_flight_end =
            'Enddatum ist kein gültiges Datum.';
    }

    if (Object.keys(fieldErrors).length > 0) {
        return { ok: false, fieldErrors };
    }

    const start = startTrimmed.slice(0, 10);
    const end = endTrimmed.slice(0, 10);

    if (end < start) {
        return {
            ok: false,
            fieldErrors: {
                operational_flight_end:
                    'Das Enddatum darf nicht vor dem Startdatum liegen.',
            },
        };
    }

    return { ok: true, start, end };
}
