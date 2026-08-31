import {
    FormField,
    formatSecondPrice,
    formSelectClass,
    money,
} from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    END_HOURS,
    START_HOURS,
    formatHour,
    isCompleteTimeRange,
    timeRangeHint,
    totalSpotCount,
    type DayGroupOption,
    type TimeRangeDraft,
} from '@/lib/pricing-time';

type RangeTotals = {
    average_second_price?: string;
    range_gross?: string;
};

export function PriceTimeRanges({
    positionIndex,
    ranges,
    dayGroups,
    canEdit,
    fieldErrors,
    rangeTotals,
    legacyTotalSpotCount,
    needsRedistribution,
    onChange,
}: {
    positionIndex: number;
    ranges: TimeRangeDraft[];
    dayGroups: DayGroupOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    rangeTotals?: RangeTotals[];
    legacyTotalSpotCount?: number | null;
    needsRedistribution?: boolean;
    onChange: (ranges: TimeRangeDraft[]) => void;
}) {
    const spots = totalSpotCount(ranges);
    const assigned = ranges.reduce((sum, range) => {
        return (
            sum + (typeof range.spot_count === 'number' ? range.spot_count : 0)
        );
    }, 0);

    function updateRange(index: number, patch: Partial<TimeRangeDraft>) {
        onChange(
            ranges.map((range, current) =>
                current === index ? { ...range, ...patch } : range,
            ),
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <p className="text-sm font-medium">Preiszeiträume</p>
                <p
                    className="text-sm"
                    data-test={`position-total-spots-${positionIndex}`}
                >
                    <span className="text-muted-foreground">
                        Gesamtspotzahl{' '}
                    </span>
                    <span className="font-semibold tabular-nums">{spots}</span>
                </p>
            </div>

            {needsRedistribution && legacyTotalSpotCount ? (
                <p className="text-primary text-sm">
                    Bitte verteile die bisherige Gesamtspotzahl auf die
                    Preiszeiträume. Offen:{' '}
                    <span className="font-medium tabular-nums">
                        {legacyTotalSpotCount}
                    </span>{' '}
                    Spots, bisher vergeben:{' '}
                    <span className="font-medium tabular-nums">{assigned}</span>
                    .
                </p>
            ) : null}

            <div className="space-y-3">
                {ranges.map((range, rangeIndex) => {
                    const complete = isCompleteTimeRange(range);
                    const totals = rangeTotals?.[rangeIndex];

                    return (
                        <div
                            key={`${range.day_group}-${rangeIndex}`}
                            className="border-border/60 bg-muted/15 space-y-3 rounded-lg border p-3"
                            data-test={`time-range-${positionIndex}-${rangeIndex}`}
                        >
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,7rem)_auto]">
                                <FormField
                                    label="Beginn"
                                    htmlFor={`range-start-${positionIndex}-${rangeIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.time_ranges.${rangeIndex}.start_hour`
                                        ]?.[0]
                                    }
                                >
                                    <select
                                        id={`range-start-${positionIndex}-${rangeIndex}`}
                                        data-test={`range-start-${positionIndex}-${rangeIndex}`}
                                        className={formSelectClass}
                                        value={range.start_hour}
                                        disabled={!canEdit}
                                        onChange={(event) =>
                                            updateRange(rangeIndex, {
                                                start_hour: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {START_HOURS.map((hour) => (
                                            <option key={hour} value={hour}>
                                                {formatHour(hour)}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                <FormField
                                    label="Ende"
                                    htmlFor={`range-end-${positionIndex}-${rangeIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.time_ranges.${rangeIndex}.end_hour_exclusive`
                                        ]?.[0]
                                    }
                                >
                                    <select
                                        id={`range-end-${positionIndex}-${rangeIndex}`}
                                        data-test={`range-end-${positionIndex}-${rangeIndex}`}
                                        className={formSelectClass}
                                        value={range.end_hour_exclusive}
                                        disabled={!canEdit}
                                        onChange={(event) =>
                                            updateRange(rangeIndex, {
                                                end_hour_exclusive: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {END_HOURS.map((hour) => (
                                            <option key={hour} value={hour}>
                                                {formatHour(hour)}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                <FormField
                                    label="Tagesgruppe"
                                    htmlFor={`range-day-${positionIndex}-${rangeIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.time_ranges.${rangeIndex}.day_group`
                                        ]?.[0]
                                    }
                                >
                                    <select
                                        id={`range-day-${positionIndex}-${rangeIndex}`}
                                        data-test={`range-day-${positionIndex}-${rangeIndex}`}
                                        className={formSelectClass}
                                        value={range.day_group}
                                        disabled={!canEdit}
                                        onChange={(event) =>
                                            updateRange(rangeIndex, {
                                                day_group: event.target.value,
                                            })
                                        }
                                    >
                                        {dayGroups.map((group) => (
                                            <option
                                                key={group.value}
                                                value={group.value}
                                            >
                                                {group.label}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                <FormField
                                    label="Spots"
                                    htmlFor={`range-spots-${positionIndex}-${rangeIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.time_ranges.${rangeIndex}.spot_count`
                                        ]?.[0]
                                    }
                                >
                                    <Input
                                        id={`range-spots-${positionIndex}-${rangeIndex}`}
                                        data-test={`range-spots-${positionIndex}-${rangeIndex}`}
                                        type="number"
                                        min={1}
                                        step={1}
                                        value={range.spot_count}
                                        disabled={!canEdit}
                                        onChange={(event) => {
                                            const raw = event.target.value;
                                            updateRange(rangeIndex, {
                                                spot_count:
                                                    raw === ''
                                                        ? ''
                                                        : Number(raw),
                                            });
                                        }}
                                    />
                                </FormField>
                                {canEdit && ranges.length > 1 ? (
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            data-test={`range-remove-${positionIndex}-${rangeIndex}`}
                                            onClick={() =>
                                                onChange(
                                                    ranges.filter(
                                                        (_, current) =>
                                                            current !==
                                                            rangeIndex,
                                                    ),
                                                )
                                            }
                                        >
                                            Entfernen
                                        </Button>
                                    </div>
                                ) : null}
                            </div>

                            {complete ? (
                                <p className="text-muted-foreground text-xs">
                                    {timeRangeHint(
                                        range.start_hour,
                                        range.end_hour_exclusive,
                                    )}
                                    {totals?.average_second_price &&
                                    totals.range_gross ? (
                                        <>
                                            {' '}
                                            Ø{' '}
                                            <span className="text-foreground font-medium">
                                                {formatSecondPrice(
                                                    totals.average_second_price,
                                                )}
                                            </span>
                                            /s · Zeitraumssumme{' '}
                                            <span className="text-foreground font-medium">
                                                {money(totals.range_gross)}
                                            </span>
                                        </>
                                    ) : null}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            {canEdit ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test={`range-add-${positionIndex}`}
                    onClick={() =>
                        onChange([
                            ...ranges,
                            {
                                start_hour: 14,
                                end_hour_exclusive: 18,
                                day_group: 'mo_fr',
                                spot_count: '',
                            },
                        ])
                    }
                >
                    Preiszeitraum hinzufügen
                </Button>
            ) : null}
        </div>
    );
}

export function dayGroupLabel(
    value: string,
    dayGroups: DayGroupOption[],
): string {
    return dayGroups.find((group) => group.value === value)?.label ?? value;
}
