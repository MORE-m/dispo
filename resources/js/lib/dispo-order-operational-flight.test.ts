import { describe, expect, it } from 'vitest';
import {
    isValidIsoDate,
    resolveHandoffFlightForAdopt,
    validateOperationalFlightDraft,
} from '@/lib/dispo-order-operational-flight';

describe('dispo-order-operational-flight helpers', () => {
    it('accepts valid ISO dates and rejects invalid ones', () => {
        expect(isValidIsoDate('2026-05-01')).toBe(true);
        expect(isValidIsoDate('2026-02-29')).toBe(false);
        expect(isValidIsoDate('2024-02-29')).toBe(true);
        expect(isValidIsoDate('01.05.2026')).toBe(false);
        expect(isValidIsoDate('')).toBe(false);
    });

    it('resolves closed handoff flight for adopt only', () => {
        expect(
            resolveHandoffFlightForAdopt({
                periodOpen: false,
                periodOpenCaptured: true,
                flight: { start: '2026-05-01', end: '2026-05-31' },
                flightCaptured: true,
            }),
        ).toEqual({ start: '2026-05-01', end: '2026-05-31' });

        expect(
            resolveHandoffFlightForAdopt({
                periodOpen: true,
                periodOpenCaptured: true,
                flight: { start: '2026-05-01', end: '2026-05-31' },
                flightCaptured: true,
            }),
        ).toBeNull();

        expect(
            resolveHandoffFlightForAdopt({
                periodOpen: false,
                periodOpenCaptured: true,
                flight: { start: '2026-05-31', end: '2026-05-01' },
                flightCaptured: true,
            }),
        ).toBeNull();

        expect(
            resolveHandoffFlightForAdopt({
                periodOpen: false,
                periodOpenCaptured: true,
                flight: { start: null, end: '2026-05-31' },
                flightCaptured: true,
            }),
        ).toBeNull();
    });

    it('validates draft pairs for client guidance', () => {
        expect(validateOperationalFlightDraft('', '')).toEqual({
            ok: true,
            start: null,
            end: null,
        });

        expect(
            validateOperationalFlightDraft('2026-05-01', '2026-05-31'),
        ).toEqual({
            ok: true,
            start: '2026-05-01',
            end: '2026-05-31',
        });

        const endBeforeStart = validateOperationalFlightDraft(
            '2026-05-31',
            '2026-05-01',
        );
        expect(endBeforeStart.ok).toBe(false);
        if (!endBeforeStart.ok) {
            expect(endBeforeStart.fieldErrors.operational_flight_end).toMatch(
                /nicht vor dem Startdatum/,
            );
        }

        const half = validateOperationalFlightDraft('2026-05-01', '');
        expect(half.ok).toBe(false);
        if (!half.ok) {
            expect(half.fieldErrors.operational_flight_end).toBeTruthy();
        }
    });
});
