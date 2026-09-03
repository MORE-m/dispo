import { describe, expect, it } from 'vitest';
import {
    DISPLAY_TIMEZONE,
    formatDateOnly,
    formatDateTime,
} from '@/lib/date-time';

describe('formatDateTime', () => {
    it('uses Europe/Berlin as display timezone', () => {
        expect(DISPLAY_TIMEZONE).toBe('Europe/Berlin');
    });

    it('converts summer-time UTC to Berlin (UTC+2)', () => {
        expect(formatDateTime('2026-09-03T19:17:00Z')).toBe(
            '03.09.2026, 21:17',
        );
        expect(formatDateTime('2026-09-03T19:17:00+00:00')).toBe(
            '03.09.2026, 21:17',
        );
    });

    it('converts winter-time UTC to Berlin (UTC+1)', () => {
        expect(formatDateTime('2026-01-15T12:00:00Z')).toBe(
            '15.01.2026, 13:00',
        );
    });

    it('accepts explicit offsets', () => {
        expect(formatDateTime('2026-09-03T21:17:00+02:00')).toBe(
            '03.09.2026, 21:17',
        );
    });

    it('returns dash for empty or invalid values', () => {
        expect(formatDateTime(null)).toBe('–');
        expect(formatDateTime(undefined)).toBe('–');
        expect(formatDateTime('')).toBe('–');
        expect(formatDateTime('not-a-date')).toBe('–');
    });
});

describe('formatDateOnly', () => {
    it('formats YYYY-MM-DD without timezone shift', () => {
        expect(formatDateOnly('2026-09-03')).toBe('03.09.2026');
        expect(formatDateOnly('2026-01-15T00:00:00Z')).toBe('15.01.2026');
    });

    it('does not shift calendar day near timezone boundaries', () => {
        // Rein fachliches Datum: niemals über Date-Parsing verschieben.
        expect(formatDateOnly('2026-09-03')).toBe('03.09.2026');
        expect(formatDateOnly('2026-12-31')).toBe('31.12.2026');
    });

    it('returns dash for empty or invalid values', () => {
        expect(formatDateOnly(null)).toBe('–');
        expect(formatDateOnly('')).toBe('–');
        expect(formatDateOnly('03.09.2026')).toBe('–');
    });
});
