import { describe, expect, it } from 'vitest';
import {
    addDays,
    addMonths,
    emptyPlannerEntry,
    formatDateOnly,
    formatHour,
    HOURS,
    payloadPlannerEntries,
    positionTimingPayload,
    shiftMonthAnchor,
    startOfWeekMonday,
    totalPlannerSpotCount,
} from '@/lib/pricing-calendar';

describe('pricing-calendar', () => {
    it('exports hours 0–23 with formatHour labels', () => {
        expect(HOURS).toHaveLength(24);
        expect(HOURS[0]).toBe(0);
        expect(HOURS[23]).toBe(23);
        expect(formatHour(8)).toBe('08:00');
    });

    it('filters incomplete planner rows from payload', () => {
        const payload = payloadPlannerEntries([
            emptyPlannerEntry(),
            { date: '2026-09-14', hour: 8, spot_count: 3 },
            { date: '2026-09-14', hour: '', spot_count: 2 },
        ]);

        expect(payload).toEqual([
            { date: '2026-09-14', hour: 8, spot_count: 3 },
        ]);
    });

    it('sums spot counts only for complete rows', () => {
        expect(
            totalPlannerSpotCount([
                { date: '2026-09-14', hour: 8, spot_count: 10 },
                { date: '2026-09-15', hour: 14, spot_count: '' },
            ]),
        ).toBe(10);
    });

    it('uses Monday as week start and addDays shifts calendar dates', () => {
        const monday = startOfWeekMonday(new Date(2026, 8, 16));
        expect(formatDateOnly(monday)).toBe('2026-09-14');
        expect(formatDateOnly(addDays(monday, 7))).toBe('2026-09-21');
    });

    it('addMonths lands on the first day of the target month across year boundaries', () => {
        expect(formatDateOnly(addMonths(new Date(2026, 0, 31), 1))).toBe(
            '2026-02-01',
        );
        expect(formatDateOnly(addMonths(new Date(2026, 11, 15), 1))).toBe(
            '2027-01-01',
        );
    });

    it('shiftMonthAnchor uses Monday week containing the first of the target month', () => {
        const anchor = shiftMonthAnchor(new Date(2026, 8, 14), 1);
        expect(formatDateOnly(anchor)).toBe('2026-09-28');

        const previous = shiftMonthAnchor(new Date(2026, 2, 10), -1);
        expect(formatDateOnly(previous)).toBe('2026-01-26');
    });

    it('branches preview payload between calendar and average', () => {
        const calendar = positionTimingPayload(
            'calendar',
            [{ start_hour: 8, end_hour_exclusive: 12, day_group: 'mo_fr', spot_count: 5 }],
            [{ date: '2026-09-14', hour: 8, spot_count: 4 }],
        );
        expect(calendar.time_ranges).toEqual([]);
        expect(calendar.planner_entries).toEqual([
            { date: '2026-09-14', hour: 8, spot_count: 4 },
        ]);

        const invalid = positionTimingPayload(
            'calendar',
            [],
            [{ date: '2026-09-14', hour: 8, spot_count: -1 }],
        );
        expect(invalid.planner_entries).toEqual([
            { date: '2026-09-14', hour: 8, spot_count: -1 },
        ]);
        expect(calendar.total_spot_count).toBe(4);

        const average = positionTimingPayload(
            'average',
            [{ start_hour: 8, end_hour_exclusive: 12, day_group: 'mo_fr', spot_count: 5 }],
            [{ date: '2026-09-14', hour: 8, spot_count: 4 }],
        );
        expect(average.planner_entries).toEqual([]);
        expect(average.time_ranges).toHaveLength(1);
        expect(average.total_spot_count).toBe(5);
    });
});
