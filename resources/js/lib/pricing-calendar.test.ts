import { describe, expect, it } from 'vitest';
import {
    addDays,
    addMonths,
    cellFieldError,
    dayGroupFromDate,
    emptyPlannerEntry,
    entryTotalsByCellKey,
    formatDateOnly,
    formatHour,
    HOURS,
    initialTimingFieldsForMethod,
    isDateInPriceYear,
    isHourBookable,
    payloadPlannerEntries,
    plannerCellKey,
    positionTimingPayload,
    secondPriceForCell,
    shiftMonthAnchor,
    sortPlannerEntries,
    spotsOutsideWeek,
    startOfWeekMonday,
    totalPlannerSpotCount,
    upsertPlannerCellSpots,
    visibleHoursFromPriceListItems,
    weekDates,
    type PriceListHourItem,
} from '@/lib/pricing-calendar';

const sampleHours: PriceListHourItem[] = [
    { hour: 8, day_group: 'mo_fr', second_price: '2.0000' },
    { hour: 8, day_group: 'sa', second_price: '1.5000' },
    { hour: 14, day_group: 'mo_fr', second_price: '3.0000' },
    { hour: 14, day_group: 'so', second_price: '2.5000' },
];

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

    it('starts calendar drafts empty', () => {
        expect(initialTimingFieldsForMethod('calendar').planner_entries).toEqual(
            [],
        );
        expect(initialTimingFieldsForMethod('average').planner_entries).toEqual(
            [],
        );
    });

    it('builds planner cell keys and week dates Mon–Sun', () => {
        expect(plannerCellKey('2026-09-14', 8)).toBe('2026-09-14|8');
        expect(weekDates(new Date(2026, 8, 14))).toEqual([
            '2026-09-14',
            '2026-09-15',
            '2026-09-16',
            '2026-09-17',
            '2026-09-18',
            '2026-09-19',
            '2026-09-20',
        ]);
    });

    it('mirrors DayGroupFromDate for Mo–Fr, Sa and So', () => {
        expect(dayGroupFromDate('2026-09-14')).toBe('mo_fr');
        expect(dayGroupFromDate('2026-09-18')).toBe('mo_fr');
        expect(dayGroupFromDate('2026-09-19')).toBe('sa');
        expect(dayGroupFromDate('2026-09-20')).toBe('so');
    });

    it('upserts cell spots and removes empty or zero cells', () => {
        const withSpots = upsertPlannerCellSpots([], '2026-09-14', 8, 3);
        expect(withSpots).toEqual([
            { date: '2026-09-14', hour: 8, spot_count: 3 },
        ]);

        expect(
            upsertPlannerCellSpots(withSpots, '2026-09-14', 8, ''),
        ).toEqual([]);
        expect(upsertPlannerCellSpots(withSpots, '2026-09-14', 8, 0)).toEqual(
            [],
        );
    });

    it('sorts planner entries by date then hour', () => {
        expect(
            sortPlannerEntries([
                { date: '2026-09-15', hour: 8, spot_count: 1 },
                { date: '2026-09-14', hour: 14, spot_count: 2 },
                { date: '2026-09-14', hour: 8, spot_count: 3 },
            ]),
        ).toEqual([
            { date: '2026-09-14', hour: 8, spot_count: 3 },
            { date: '2026-09-14', hour: 14, spot_count: 2 },
            { date: '2026-09-15', hour: 8, spot_count: 1 },
        ]);
    });

    it('checks bookability and second price only for base day groups', () => {
        expect(isHourBookable(sampleHours, '2026-09-14', 8)).toBe(true);
        expect(isHourBookable(sampleHours, '2026-09-14', 9)).toBe(false);
        expect(isHourBookable(sampleHours, '2026-09-19', 14)).toBe(false);
        expect(isHourBookable(sampleHours, '2026-09-20', 14)).toBe(true);
        expect(secondPriceForCell(sampleHours, '2026-09-14', 8)).toBe('2.0000');
        expect(secondPriceForCell(sampleHours, '2026-09-19', 14)).toBeNull();
    });

    it('collects visible hours and price-year membership', () => {
        expect(visibleHoursFromPriceListItems(sampleHours)).toEqual([8, 14]);
        expect(isDateInPriceYear('2026-09-14', 2026)).toBe(true);
        expect(isDateInPriceYear('2027-01-01', 2026)).toBe(false);
        expect(isDateInPriceYear('2026-09-14', null)).toBe(true);
    });

    it('counts spots outside the visible week', () => {
        expect(
            spotsOutsideWeek(
                [
                    { date: '2026-09-14', hour: 8, spot_count: 2 },
                    { date: '2026-09-21', hour: 8, spot_count: 5 },
                ],
                weekDates(new Date(2026, 8, 14)),
            ),
        ).toBe(5);
    });

    it('indexes preview totals by date|hour not array index', () => {
        const map = entryTotalsByCellKey([
            { date: '2026-09-14', hour: 14, line_gross: '450.00' },
            { date: '2026-09-14', hour: 8, line_gross: '600.00' },
        ]);

        expect(map['2026-09-14|8']?.line_gross).toBe('600.00');
        expect(map['2026-09-14|14']?.line_gross).toBe('450.00');
    });

    it('resolves cell field errors via payload index', () => {
        const entries = [
            { date: '2026-09-14', hour: 8, spot_count: 2 },
            { date: '2026-09-14', hour: 14, spot_count: -1 },
        ];

        expect(
            cellFieldError(
                {
                    'positions.0.planner_entries.1.spot_count': [
                        'Die Spotanzahl darf nicht negativ sein.',
                    ],
                },
                0,
                entries,
                '2026-09-14',
                14,
            ),
        ).toBe('Die Spotanzahl darf nicht negativ sein.');
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
