import { describe, expect, it } from 'vitest';
import {
    formatPlannerEntriesSummary,
    formatPlannerEntryLine,
    sortPlannerEntriesForDisplay,
} from '@/lib/dispo-planner-display';

describe('dispo-planner-display', () => {
    it('sorts entries by date then hour', () => {
        const sorted = sortPlannerEntriesForDisplay([
            { date: '2026-09-15', hour: 10, spot_count: 1 },
            { date: '2026-09-14', hour: 14, spot_count: 2 },
            { date: '2026-09-14', hour: 8, spot_count: 3 },
        ]);

        expect(sorted.map((entry) => `${entry.date}-${entry.hour}`)).toEqual([
            '2026-09-14-8',
            '2026-09-14-14',
            '2026-09-15-10',
        ]);
    });

    it('formats weekday, day group, hour and spots', () => {
        const line = formatPlannerEntryLine({
            date: '2026-09-19',
            hour: 9,
            day_group: 'sa',
            spot_count: 3,
        });

        expect(line).toContain('2026-09-19');
        expect(line).toContain('Sa');
        expect(line).toContain('09:00');
        expect(line).toContain('3 Spots');
    });

    it('joins multiple entries for summary text', () => {
        const summary = formatPlannerEntriesSummary([
            { date: '2026-09-14', hour: 8, day_group: 'mo_fr', spot_count: 10 },
            { date: '2026-09-14', hour: 14, day_group: 'mo_fr', spot_count: 5 },
        ]);

        expect(summary).toContain('; ');
        expect(summary).toContain('10 Spots');
        expect(summary).toContain('5 Spots');
    });
});
