/**
 * Orchestrierung für Positions-Feldschema-Fetches (testbar ohne Wizard-Mount).
 * Primärschutz: Generation + Apply trotz Effect-Cleanup.
 * Recovery: nur begrenztes Sicherheitsnetz, kein Ersatz für den Primärpfad.
 */

import {
    SCHEMA_FETCH_FAILED_MESSAGE,
    SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS,
    nextSchemaFetchGeneration,
    shouldApplyPositionSchemaResponse,
    shouldClearSchemaLoadingForGeneration,
    type PositionSchemaFetchTarget,
} from './position-field-schema';

export type SchemaFieldSchemaPayload = {
    schema_fingerprint?: string | null;
    fields?: unknown;
    [key: string]: unknown;
};

export type SchemaFetchHttpResult =
    | { ok: true; fieldSchema?: SchemaFieldSchemaPayload | null }
    | { ok: false; error?: string };

export type SchemaFetchCompletion =
    | {
          action: 'apply';
          fingerprint: string;
          fieldSchema: SchemaFieldSchemaPayload;
          clearLoading: true;
      }
    | { action: 'stale'; clearLoading: false }
    | { action: 'error'; message: string; clearLoading: true };

export type SchemaRecoveryPlan =
    | { action: 'none' }
    | { action: 'retry'; nextAttempts: number }
    | { action: 'fail'; message: string };

export function beginPositionSchemaFetch(
    pendingMap: Map<string, PositionSchemaFetchTarget>,
    clientKey: string,
    advertisingMediumId: number,
): PositionSchemaFetchTarget {
    const generation = nextSchemaFetchGeneration(
        pendingMap.get(clientKey)?.generation,
    );
    const target: PositionSchemaFetchTarget = {
        clientKey,
        advertisingMediumId,
        generation,
    };
    pendingMap.set(clientKey, target);

    return target;
}

/**
 * Wertet eine HTTP-Antwort / einen Fehler gegen den aktiven Pending-Target aus.
 * Stale Generationen werden verworfen, ohne Loading des neueren Versuchs zu löschen.
 */
export function completePositionSchemaFetch(
    pendingMap: Map<string, PositionSchemaFetchTarget>,
    target: PositionSchemaFetchTarget,
    http: SchemaFetchHttpResult,
): SchemaFetchCompletion {
    const pending = pendingMap.get(target.clientKey);

    if (!shouldApplyPositionSchemaResponse(pending, target)) {
        return { action: 'stale', clearLoading: false };
    }

    if (!http.ok) {
        return {
            action: 'error',
            message: http.error ?? SCHEMA_FETCH_FAILED_MESSAGE,
            clearLoading: true,
        };
    }

    const fieldSchema = http.fieldSchema ?? null;
    const fingerprint = fieldSchema?.schema_fingerprint ?? null;
    if (!fieldSchema || !fingerprint) {
        return {
            action: 'error',
            message: SCHEMA_FETCH_FAILED_MESSAGE,
            clearLoading: true,
        };
    }

    return {
        action: 'apply',
        fingerprint,
        fieldSchema,
        clearLoading: true,
    };
}

export function shouldClearLoadingAfterSchemaFetch(
    pendingMap: Map<string, PositionSchemaFetchTarget>,
    target: PositionSchemaFetchTarget,
): boolean {
    return shouldClearSchemaLoadingForGeneration(
        pendingMap.get(target.clientKey),
        target,
    );
}

/**
 * Recovery nur wenn Fingerprint fehlt und kein Fetch läuft.
 * Begrenzt auf SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS.
 */
export function planSchemaFetchRecovery(input: {
    needsSchema: boolean;
    isLoading: boolean;
    attempts: number;
    maxAttempts?: number;
}): SchemaRecoveryPlan {
    const max = input.maxAttempts ?? SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS;
    if (!input.needsSchema || input.isLoading) {
        return { action: 'none' };
    }
    if (input.attempts >= max) {
        return { action: 'fail', message: SCHEMA_FETCH_FAILED_MESSAGE };
    }

    return { action: 'retry', nextAttempts: input.attempts + 1 };
}

/**
 * Simuliert überlappende Fetches (Werbemittelwechsel / Effect-Restart).
 * Für Regressionstests: Primärpfad muss ohne Recovery zum aktuellen Medium führen.
 */
export async function runOverlappingSchemaFetches(input: {
    clientKey: string;
    firstMediumId: number;
    secondMediumId: number;
    firstDelayMs: number;
    secondDelayMs: number;
    firstHttp: SchemaFetchHttpResult;
    secondHttp: SchemaFetchHttpResult;
}): Promise<{
    applied: Array<{ mediumId: number; fingerprint: string }>;
    requestCount: number;
    loadingClearedFor: number[];
}> {
    const pending = new Map<string, PositionSchemaFetchTarget>();
    const applied: Array<{ mediumId: number; fingerprint: string }> = [];
    const loadingClearedFor: number[] = [];
    let requestCount = 0;

    const runOne = async (
        mediumId: number,
        delayMs: number,
        http: SchemaFetchHttpResult,
    ) => {
        const target = beginPositionSchemaFetch(
            pending,
            input.clientKey,
            mediumId,
        );
        requestCount += 1;
        await new Promise((resolve) => setTimeout(resolve, delayMs));
        const completion = completePositionSchemaFetch(pending, target, http);
        if (shouldClearLoadingAfterSchemaFetch(pending, target)) {
            loadingClearedFor.push(mediumId);
        }
        if (completion.action === 'apply') {
            applied.push({
                mediumId: target.advertisingMediumId,
                fingerprint: completion.fingerprint,
            });
        }
    };

    await Promise.all([
        runOne(input.firstMediumId, input.firstDelayMs, input.firstHttp),
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 5));
            await runOne(
                input.secondMediumId,
                input.secondDelayMs,
                input.secondHttp,
            );
        })(),
    ]);

    return { applied, requestCount, loadingClearedFor };
}
