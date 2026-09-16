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
        return [emptyPlannerEntry()];
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
            planner_entries: [emptyPlannerEntry()],
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
