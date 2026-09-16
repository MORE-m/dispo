import {
    emptyTimeRange,
    formatHour,
    payloadTimeRanges,
    totalSpotCount,
    type TimeRangeDraft,
} from '@/lib/pricing-time';

export type PlannerEntryDraft = {
    date: string;
    hour: number | '';
    spot_count: number | '';
};

export type PriceListHourItem = {
    hour: number;
    day_group: string;
    second_price: string;
};

export type DayGroupBase = 'mo_fr' | 'sa' | 'so';

export type EntryTotals = {
    line_gross?: string;
    second_price?: string;
};

export const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

export { formatHour };

export function emptyPlannerEntry(date = ''): PlannerEntryDraft {
    return {
        date,
        hour: '',
        spot_count: '',
    };
}

export function draftPlannerEntries(
    position: {
        planner_entries?: Array<{
            date: string;
            hour: number;
            spot_count: number;
        }>;
    },
    methodKey: string | null,
): PlannerEntryDraft[] {
    if (position.planner_entries?.length) {
        return position.planner_entries.map((entry) => ({
            date: entry.date,
            hour: entry.hour,
            spot_count: entry.spot_count,
        }));
    }

    if (isCalendarCalculationMethod(methodKey)) {
        return [];
    }

    return [];
}

export function isCompletePlannerEntry(
    entry: PlannerEntryDraft,
): entry is PlannerEntryDraft & {
    date: string;
    hour: number;
    spot_count: number;
} {
    return (
        entry.date.trim() !== '' &&
        entry.hour !== '' &&
        entry.spot_count !== '' &&
        Number.isInteger(Number(entry.hour)) &&
        Number(entry.hour) >= 0 &&
        Number(entry.hour) <= 23 &&
        Number.isInteger(Number(entry.spot_count)) &&
        Number(entry.spot_count) >= 1
    );
}

export function payloadPlannerEntries(
    drafts: PlannerEntryDraft[],
): Array<{ date: string; hour: number; spot_count: number }> {
    return drafts.filter(isCompletePlannerEntry).map((entry) => ({
        date: entry.date,
        hour: entry.hour,
        spot_count: Number(entry.spot_count),
    }));
}

/** Nicht-leere Entwurfszeilen für Preview/Save (Server-Validierung). */
export function plannerEntriesForPayload(drafts: PlannerEntryDraft[]): Array<{
    date: string | null;
    hour: number | null;
    spot_count: number | null;
}> {
    return drafts
        .filter(
            (entry) =>
                entry.date.trim() !== '' ||
                entry.hour !== '' ||
                entry.spot_count !== '',
        )
        .map((entry) => ({
            date: entry.date.trim() !== '' ? entry.date : null,
            hour: entry.hour === '' ? null : Number(entry.hour),
            spot_count:
                entry.spot_count === '' ? null : Number(entry.spot_count),
        }));
}

export function totalPlannerSpotCount(drafts: PlannerEntryDraft[]): number {
    return drafts.reduce((sum, entry) => {
        if (!isCompletePlannerEntry(entry)) {
            return sum;
        }

        return sum + Number(entry.spot_count);
    }, 0);
}

export function parseDateOnly(iso: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso.trim());
    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(year, month - 1, day);
    if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null;
    }

    return date;
}

export function formatDateOnly(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/** Montag als Wochenbeginn (lokal, Europe/Berlin-kompatibel für reine Datumsfelder). */
export function startOfWeekMonday(date: Date): Date {
    const normalized = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    );
    const weekday = normalized.getDay();
    const diff = weekday === 0 ? -6 : 1 - weekday;
    normalized.setDate(normalized.getDate() + diff);

    return normalized;
}

export function addDays(date: Date, days: number): Date {
    const next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    next.setDate(next.getDate() + days);

    return next;
}

/** Erster Tag des Zielmonats (Monats-/Jahresüberlauf über Date-Konstruktor). */
export function addMonths(date: Date, months: number): Date {
    return new Date(date.getFullYear(), date.getMonth() + months, 1);
}

/** Monatsnavigation für die Referenzwoche: 1. des Zielmonats, dann Montag dieser Woche. */
export function shiftMonthAnchor(weekAnchor: Date, deltaMonths: number): Date {
    return startOfWeekMonday(addMonths(weekAnchor, deltaMonths));
}

export function plannerCellKey(date: string, hour: number): string {
    return `${date}|${hour}`;
}

/** Spiegelt DayGroupFromDate (Europe/Berlin-Datumsfelder, lokal ausgewertet). */
export function dayGroupFromDate(dateIso: string): DayGroupBase | null {
    const date = parseDateOnly(dateIso);
    if (!date) {
        return null;
    }

    const weekday = date.getDay();
    if (weekday === 0) {
        return 'so';
    }
    if (weekday === 6) {
        return 'sa';
    }

    return 'mo_fr';
}

export function weekDates(weekAnchor: Date): string[] {
    return Array.from({ length: 7 }, (_, offset) =>
        formatDateOnly(addDays(weekAnchor, offset)),
    );
}

export function sortPlannerEntries(
    entries: PlannerEntryDraft[],
): PlannerEntryDraft[] {
    return [...entries].sort((left, right) => {
        const dateCmp = left.date.localeCompare(right.date);
        if (dateCmp !== 0) {
            return dateCmp;
        }

        const hourLeft = left.hour === '' ? -1 : Number(left.hour);
        const hourRight = right.hour === '' ? -1 : Number(right.hour);

        return hourLeft - hourRight;
    });
}

/** Leeres Feld oder 0 entfernt die Zelle aus planner_entries. */
export function upsertPlannerCellSpots(
    entries: PlannerEntryDraft[],
    date: string,
    hour: number,
    spotCount: number | '',
): PlannerEntryDraft[] {
    const withoutCell = entries.filter(
        (entry) => !(entry.date === date && entry.hour === hour),
    );

    if (spotCount === '' || spotCount === 0) {
        return sortPlannerEntries(withoutCell);
    }

    return sortPlannerEntries([
        ...withoutCell,
        { date, hour, spot_count: spotCount },
    ]);
}

export function isHourBookable(
    items: PriceListHourItem[],
    date: string,
    hour: number,
): boolean {
    const dayGroup = dayGroupFromDate(date);
    if (dayGroup === null) {
        return false;
    }

    return items.some(
        (item) => item.hour === hour && item.day_group === dayGroup,
    );
}

export function secondPriceForCell(
    items: PriceListHourItem[],
    date: string,
    hour: number,
): string | null {
    const dayGroup = dayGroupFromDate(date);
    if (dayGroup === null) {
        return null;
    }

    const match = items.find(
        (item) => item.hour === hour && item.day_group === dayGroup,
    );

    return match?.second_price ?? null;
}

export function visibleHoursFromPriceListItems(
    items: PriceListHourItem[],
): number[] {
    return [...new Set(items.map((item) => item.hour))].sort((a, b) => a - b);
}

export function isDateInPriceYear(
    dateIso: string,
    priceYear: number | null | undefined,
): boolean {
    if (priceYear == null) {
        return true;
    }

    const date = parseDateOnly(dateIso);
    if (!date) {
        return false;
    }

    return date.getFullYear() === priceYear;
}

export function spotsOutsideWeek(
    entries: PlannerEntryDraft[],
    datesInWeek: string[],
): number {
    const weekSet = new Set(datesInWeek);

    return entries.reduce((sum, entry) => {
        if (!isCompletePlannerEntry(entry) || weekSet.has(entry.date)) {
            return sum;
        }

        return sum + Number(entry.spot_count);
    }, 0);
}

export function entryTotalsByCellKey(
    totals:
        | Array<{
              date: string;
              hour: number;
              line_gross?: string;
              second_price?: string;
          }>
        | undefined,
): Record<string, EntryTotals> {
    const map: Record<string, EntryTotals> = {};

    for (const entry of totals ?? []) {
        map[plannerCellKey(entry.date, entry.hour)] = {
            line_gross: entry.line_gross,
            second_price: entry.second_price,
        };
    }

    return map;
}

export function cellFieldError(
    fieldErrors: Record<string, string[]>,
    positionIndex: number,
    entries: PlannerEntryDraft[],
    date: string,
    hour: number,
): string | undefined {
    const payload = plannerEntriesForPayload(entries);
    const index = payload.findIndex(
        (entry) => entry.date === date && entry.hour === hour,
    );

    if (index < 0) {
        return fieldErrors[`positions.${positionIndex}.planner_entries`]?.[0];
    }

    return (
        fieldErrors[
            `positions.${positionIndex}.planner_entries.${index}.spot_count`
        ]?.[0] ??
        fieldErrors[
            `positions.${positionIndex}.planner_entries.${index}.date`
        ]?.[0] ??
        fieldErrors[
            `positions.${positionIndex}.planner_entries.${index}.hour`
        ]?.[0] ??
        fieldErrors[`positions.${positionIndex}.planner_entries`]?.[0]
    );
}

export function spotsForCell(
    entries: PlannerEntryDraft[],
    date: string,
    hour: number,
): number | '' {
    const match = entries.find(
        (entry) => entry.date === date && entry.hour === hour,
    );

    return match?.spot_count ?? '';
}

export function daySpotSum(entries: PlannerEntryDraft[], date: string): number {
    return entries.reduce((sum, entry) => {
        if (!isCompletePlannerEntry(entry) || entry.date !== date) {
            return sum;
        }

        return sum + Number(entry.spot_count);
    }, 0);
}

export function hourSpotSum(
    entries: PlannerEntryDraft[],
    hour: number,
    datesInWeek: string[],
): number {
    const weekSet = new Set(datesInWeek);

    return entries.reduce((sum, entry) => {
        if (
            !isCompletePlannerEntry(entry) ||
            entry.hour !== hour ||
            !weekSet.has(entry.date)
        ) {
            return sum;
        }

        return sum + Number(entry.spot_count);
    }, 0);
}

export function isCalendarCalculationMethod(
    methodKey: string | null | undefined,
): boolean {
    return methodKey === 'calendar';
}

export function initialTimingFieldsForMethod(methodKey: string | null): {
    time_ranges: TimeRangeDraft[];
    planner_entries: PlannerEntryDraft[];
    total_spot_count: number;
} {
    if (isCalendarCalculationMethod(methodKey)) {
        return {
            time_ranges: [],
            planner_entries: [],
            total_spot_count: 0,
        };
    }

    return {
        time_ranges: [emptyTimeRange()],
        planner_entries: [],
        total_spot_count: 0,
    };
}

export function positionTimingPayload(
    methodKey: string | null,
    timeRanges: TimeRangeDraft[],
    plannerEntries: PlannerEntryDraft[],
): {
    time_ranges: ReturnType<typeof payloadTimeRanges>;
    planner_entries: ReturnType<typeof plannerEntriesForPayload>;
    total_spot_count: number;
} {
    if (isCalendarCalculationMethod(methodKey)) {
        // Teilweise ausgefüllte / ungültige Zeilen mitsenden, damit Preview/Store
        // Server-Validierung (z. B. negative Spotanzahl) sichtbar machen kann.
        return {
            time_ranges: [],
            planner_entries: plannerEntriesForPayload(plannerEntries),
            total_spot_count: totalPlannerSpotCount(plannerEntries),
        };
    }

    const time_ranges = payloadTimeRanges(timeRanges);

    return {
        time_ranges,
        planner_entries: [],
        total_spot_count: totalSpotCount(timeRanges),
    };
}
