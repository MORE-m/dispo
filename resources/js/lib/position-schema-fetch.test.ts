import { describe, expect, it } from 'vitest';
import {
    SCHEMA_FETCH_FAILED_MESSAGE,
    SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS,
} from './position-field-schema';
import {
    beginPositionSchemaFetch,
    completePositionSchemaFetch,
    endSchemaFetchSession,
    planSchemaFetchRecovery,
    runOverlappingSchemaFetches,
    shouldClearLoadingAfterSchemaFetch,
    syncSchemaRecoveryScope,
} from './position-schema-fetch';

const schema = (fingerprint: string) => ({
    schema_fingerprint: fingerprint,
    fields: [],
});

describe('position-schema-fetch primary path (ohne Recovery)', () => {
    it('übernimmt HTTP-200 trotz späterem Effect-Cleanup (kein cancelled-Drop)', () => {
        const pending = new Map();
        const target = beginPositionSchemaFetch(pending, 'p1', 10);

        // Effect-Cleanup / Re-Render: Pending bleibt auf derselben Generation.
        const completion = completePositionSchemaFetch(pending, target, {
            ok: true,
            fieldSchema: schema('fp-ok'),
        });

        expect(completion).toEqual({
            action: 'apply',
            fingerprint: 'fp-ok',
            fieldSchema: schema('fp-ok'),
            clearLoading: true,
        });
        expect(shouldClearLoadingAfterSchemaFetch(pending, target)).toBe(true);
    });

    it('verwirft veraltete Antwort nach schnellem Werbemittelwechsel und lässt neueres Loading stehen', async () => {
        const result = await runOverlappingSchemaFetches({
            clientKey: 'p1',
            firstMediumId: 10,
            secondMediumId: 20,
            firstDelayMs: 40,
            secondDelayMs: 5,
            firstHttp: { ok: true, fieldSchema: schema('fp-old') },
            secondHttp: { ok: true, fieldSchema: schema('fp-new') },
        });

        expect(result.requestCount).toBe(2);
        expect(result.applied).toEqual([
            { mediumId: 20, fingerprint: 'fp-new' },
        ]);
        // Alte Generation darf Loading des neuen Versuchs nicht löschen.
        expect(result.loadingClearedFor).toEqual([20]);
    });

    it('ignoriert Generation-Mismatch ohne Apply und ohne Loading-Clear', () => {
        const pending = new Map();
        const first = beginPositionSchemaFetch(pending, 'p1', 10);
        const second = beginPositionSchemaFetch(pending, 'p1', 10);

        expect(second.generation).toBeGreaterThan(first.generation);

        const stale = completePositionSchemaFetch(pending, first, {
            ok: true,
            fieldSchema: schema('fp-stale'),
        });
        expect(stale.action).toBe('stale');
        expect(stale.clearLoading).toBe(false);
        expect(shouldClearLoadingAfterSchemaFetch(pending, first)).toBe(false);

        const fresh = completePositionSchemaFetch(pending, second, {
            ok: true,
            fieldSchema: schema('fp-fresh'),
        });
        expect(fresh.action).toBe('apply');
        if (fresh.action === 'apply') {
            expect(fresh.fingerprint).toBe('fp-fresh');
        }
    });

    it('behandelt echten Unmount via endSchemaFetchSession als stale ohne Apply', () => {
        const pending = new Map();
        const target = beginPositionSchemaFetch(pending, 'p1', 10);
        // Entspricht Wizard-Unmount-Cleanup (nicht Effect-Restart).
        endSchemaFetchSession(pending);

        const completion = completePositionSchemaFetch(pending, target, {
            ok: true,
            fieldSchema: schema('fp-unmounted'),
        });
        expect(completion.action).toBe('stale');
        expect(shouldClearLoadingAfterSchemaFetch(pending, target)).toBe(false);
        expect(pending.size).toBe(0);
    });

    it('lässt Effect-Restart gültige Antworten der neuen Generation zu', () => {
        const pending = new Map();
        const first = beginPositionSchemaFetch(pending, 'p1', 10);
        // Restart: kein endSchemaFetchSession – nur neue Generation.
        const second = beginPositionSchemaFetch(pending, 'p1', 10);

        expect(
            completePositionSchemaFetch(pending, first, {
                ok: true,
                fieldSchema: schema('fp-old'),
            }).action,
        ).toBe('stale');
        expect(
            completePositionSchemaFetch(pending, second, {
                ok: true,
                fieldSchema: schema('fp-new'),
            }),
        ).toMatchObject({ action: 'apply', fingerprint: 'fp-new' });
    });

    it('markiert fehlgeschlagene Requests und leere 200-Bodies als error', () => {
        const pending = new Map();
        const target = beginPositionSchemaFetch(pending, 'p1', 10);

        expect(
            completePositionSchemaFetch(pending, target, { ok: false }),
        ).toEqual({
            action: 'error',
            message: SCHEMA_FETCH_FAILED_MESSAGE,
            clearLoading: true,
        });

        const again = beginPositionSchemaFetch(pending, 'p1', 10);
        expect(
            completePositionSchemaFetch(pending, again, {
                ok: true,
                fieldSchema: { schema_fingerprint: null, fields: [] },
            }),
        ).toMatchObject({ action: 'error', clearLoading: true });
    });
});

describe('position-schema-fetch recovery safety net', () => {
    it('plant keine Recovery während Loading oder wenn Schema vorhanden', () => {
        expect(
            planSchemaFetchRecovery({
                needsSchema: true,
                isLoading: true,
                attempts: 0,
            }),
        ).toEqual({ action: 'none' });
        expect(
            planSchemaFetchRecovery({
                needsSchema: false,
                isLoading: false,
                attempts: 2,
            }),
        ).toEqual({ action: 'none' });
    });

    it('begrenzt Retries und failt danach ohne Endlosschleife', () => {
        const first = planSchemaFetchRecovery({
            needsSchema: true,
            isLoading: false,
            attempts: 0,
        });
        expect(first).toEqual({ action: 'retry', nextAttempts: 1 });

        let attempts = 0;
        const seen: string[] = [];
        for (let i = 0; i < 10; i++) {
            const plan = planSchemaFetchRecovery({
                needsSchema: true,
                isLoading: false,
                attempts,
            });
            seen.push(plan.action);
            if (plan.action === 'retry') {
                attempts = plan.nextAttempts;
            }
            if (plan.action === 'fail') {
                expect(plan.message).toBe(SCHEMA_FETCH_FAILED_MESSAGE);
                break;
            }
        }
        expect(attempts).toBe(SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS);
        expect(seen.filter((a) => a === 'retry')).toHaveLength(
            SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS,
        );
        expect(seen[seen.length - 1]).toBe('fail');
        // weiterer Aufruf bleibt fail, kein weiteres retry
        expect(
            planSchemaFetchRecovery({
                needsSchema: true,
                isLoading: false,
                attempts,
            }).action,
        ).toBe('fail');
    });

    it('benötigt bei erfolgreichem Primär-Apply keine Recovery', async () => {
        const result = await runOverlappingSchemaFetches({
            clientKey: 'p1',
            firstMediumId: 7,
            secondMediumId: 7,
            firstDelayMs: 40,
            secondDelayMs: 5,
            firstHttp: { ok: true, fieldSchema: schema('fp-a') },
            secondHttp: { ok: true, fieldSchema: schema('fp-b') },
        });
        // Zwei Requests durch Generation-Bump, genau ein Apply (neueste Gen).
        expect(result.requestCount).toBe(2);
        expect(result.applied).toEqual([
            { mediumId: 7, fingerprint: 'fp-b' },
        ]);
        expect(
            planSchemaFetchRecovery({
                needsSchema: false,
                isLoading: false,
                attempts: 0,
            }),
        ).toEqual({ action: 'none' });
    });

    it('setzt Retry-Budget bei Scope-Wechsel (neues Medium/Position) zurück', () => {
        const exhausted = syncSchemaRecoveryScope(
            { scopeKey: 'p1:10', attempts: SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS },
            'p1:10',
        );
        expect(exhausted.attempts).toBe(SCHEMA_FETCH_RECOVERY_MAX_ATTEMPTS);
        expect(
            planSchemaFetchRecovery({
                needsSchema: true,
                isLoading: false,
                attempts: exhausted.attempts,
            }).action,
        ).toBe('fail');

        const switched = syncSchemaRecoveryScope(
            exhausted,
            'p1:20',
        );
        expect(switched).toEqual({ scopeKey: 'p1:20', attempts: 0 });
        expect(
            planSchemaFetchRecovery({
                needsSchema: true,
                isLoading: false,
                attempts: switched.attempts,
            }),
        ).toEqual({ action: 'retry', nextAttempts: 1 });
    });
});
