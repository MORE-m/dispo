export type DayGroupOption = {
    value: string;
    label: string;
};

export type TimeRangeDraft = {
    start_hour: number | '';
    end_hour_exclusive: number | '';
    day_group: string;
    spot_count: number | '';
};

export const START_HOURS = Array.from({ length: 24 }, (_, hour) => hour);
export const END_HOURS = Array.from({ length: 24 }, (_, hour) => hour + 1);

export function formatHour(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

export function formatInclusiveEnd(endHourExclusive: number): string {
    return `${String(endHourExclusive - 1).padStart(2, '0')}:59`;
}

export function timeRangeHint(
    startHour: number,
    endHourExclusive: number,
): string {
    return `Berechnet werden die Preisstunden ${formatHour(startHour)} bis ${formatInclusiveEnd(endHourExclusive)} Uhr.`;
}

export function isCompleteTimeRange(
    range: TimeRangeDraft,
): range is TimeRangeDraft & {
    start_hour: number;
    end_hour_exclusive: number;
    spot_count: number;
} {
    return (
        range.start_hour !== '' &&
        range.end_hour_exclusive !== '' &&
        range.day_group !== '' &&
        range.spot_count !== '' &&
        Number.isInteger(Number(range.spot_count)) &&
        Number(range.spot_count) >= 1 &&
        range.end_hour_exclusive > range.start_hour
    );
}

export function emptyTimeRange(): TimeRangeDraft {
    return {
        start_hour: 8,
        end_hour_exclusive: 12,
        day_group: 'mo_fr',
        spot_count: '',
    };
}

export type DistributionRangeDraft = Omit<TimeRangeDraft, 'spot_count'>;

export function emptyDistributionRange(): DistributionRangeDraft {
    return {
        start_hour: 6,
        end_hour_exclusive: 18,
        day_group: 'mo_fr',
    };
}

export function isCompleteDistributionRange(
    range: DistributionRangeDraft,
): range is DistributionRangeDraft & {
    start_hour: number;
    end_hour_exclusive: number;
} {
    return (
        range.start_hour !== '' &&
        range.end_hour_exclusive !== '' &&
        range.day_group !== '' &&
        range.end_hour_exclusive > range.start_hour
    );
}

export function payloadDistributionRanges(
    ranges: DistributionRangeDraft[],
): Array<{
    start_hour: number;
    end_hour_exclusive: number;
    day_group: string;
}> {
    return ranges.filter(isCompleteDistributionRange).map((range) => ({
        start_hour: range.start_hour,
        end_hour_exclusive: range.end_hour_exclusive,
        day_group: range.day_group,
    }));
}

export function totalSpotCount(ranges: TimeRangeDraft[]): number {
    return ranges.reduce((sum, range) => {
        if (!isCompleteTimeRange(range)) {
            return sum;
        }

        return sum + Number(range.spot_count);
    }, 0);
}

export function payloadTimeRanges(ranges: TimeRangeDraft[]): Array<{
    start_hour: number;
    end_hour_exclusive: number;
    day_group: string;
    spot_count: number;
}> {
    return ranges.filter(isCompleteTimeRange).map((range) => ({
        start_hour: range.start_hour,
        end_hour_exclusive: range.end_hour_exclusive,
        day_group: range.day_group,
        spot_count: Number(range.spot_count),
    }));
}
