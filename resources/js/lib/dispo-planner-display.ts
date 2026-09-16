import { formatHour, parseDateOnly } from '@/lib/pricing-calendar';

export type PlannerEntryDisplay = {
    date: string;
    hour: number;
    day_group?: string;
    spot_count: number;
    line_gross?: string | null;
};

const weekdayFormatter = new Intl.DateTimeFormat('de-DE', { weekday: 'short' });

const dayGroupLabels: Record<string, string> = {
    mo_fr: 'Mo–Fr',
    sa: 'Sa',
    so: 'So',
    mo_sa: 'Mo–Sa',
    mo_so: 'Mo–So',
};

export function dayGroupLabel(value: string | undefined): string {
    if (!value) {
        return '';
    }

    return dayGroupLabels[value] ?? value;
}

export function formatPlannerEntryLine(entry: PlannerEntryDisplay): string {
    const parsed = parseDateOnly(entry.date);
    const weekday = parsed ? weekdayFormatter.format(parsed) : '';
    const group = dayGroupLabel(entry.day_group);
    const dayPart = [entry.date, weekday, group].filter(Boolean).join(' · ');

    return `${dayPart} · ${formatHour(entry.hour)} · ${entry.spot_count} Spots`;
}

export function sortPlannerEntriesForDisplay(
    entries: PlannerEntryDisplay[],
): PlannerEntryDisplay[] {
    return [...entries].sort((left, right) => {
        const dateCompare = left.date.localeCompare(right.date);
        if (dateCompare !== 0) {
            return dateCompare;
        }

        return left.hour - right.hour;
    });
}

export function formatPlannerEntriesSummary(
    entries: PlannerEntryDisplay[],
): string {
    if (entries.length === 0) {
        return '–';
    }

    return sortPlannerEntriesForDisplay(entries)
        .map((entry) => formatPlannerEntryLine(entry))
        .join('; ');
}
