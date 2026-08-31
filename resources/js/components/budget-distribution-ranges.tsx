import { FormField, formSelectClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    END_HOURS,
    START_HOURS,
    formatHour,
    timeRangeHint,
    type DayGroupOption,
    type DistributionRangeDraft,
} from '@/lib/pricing-time';

export function BudgetDistributionRanges({
    ranges,
    dayGroups,
    canEdit,
    fieldErrors,
    onChange,
}: {
    ranges: DistributionRangeDraft[];
    dayGroups: DayGroupOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    onChange: (ranges: DistributionRangeDraft[]) => void;
}) {
    function updateRange(
        index: number,
        patch: Partial<DistributionRangeDraft>,
    ) {
        onChange(
            ranges.map((range, current) =>
                current === index ? { ...range, ...patch } : range,
            ),
        );
    }

    return (
        <div className="space-y-3" data-test="budget-distribution-ranges">
            <p className="text-sm font-medium">Erlaubte Verteilungszeiträume</p>
            <p className="text-muted-foreground text-sm">
                Der Budgetplaner verteilt Spots nur in diesen Zeiträumen. Die
                Spotanzahl wird automatisch ermittelt.
            </p>
            <div className="space-y-3">
                {ranges.map((range, rangeIndex) => (
                    <div
                        key={`budget-range-${rangeIndex}`}
                        className="border-border/60 bg-muted/15 space-y-3 rounded-lg border p-3"
                    >
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <FormField
                                label="Beginn"
                                htmlFor={`budget-start-${rangeIndex}`}
                                error={
                                    fieldErrors[
                                        `budget_distribution_ranges.${rangeIndex}.start_hour`
                                    ]?.[0]
                                }
                            >
                                <select
                                    id={`budget-start-${rangeIndex}`}
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
                                htmlFor={`budget-end-${rangeIndex}`}
                                error={
                                    fieldErrors[
                                        `budget_distribution_ranges.${rangeIndex}.end_hour_exclusive`
                                    ]?.[0]
                                }
                            >
                                <select
                                    id={`budget-end-${rangeIndex}`}
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
                                htmlFor={`budget-day-${rangeIndex}`}
                                error={
                                    fieldErrors[
                                        `budget_distribution_ranges.${rangeIndex}.day_group`
                                    ]?.[0]
                                }
                            >
                                <select
                                    id={`budget-day-${rangeIndex}`}
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
                            <div className="flex items-end">
                                {ranges.length > 1 && canEdit ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            onChange(
                                                ranges.filter(
                                                    (_, index) =>
                                                        index !== rangeIndex,
                                                ),
                                            )
                                        }
                                    >
                                        Entfernen
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                        {typeof range.start_hour === 'number' &&
                        typeof range.end_hour_exclusive === 'number' &&
                        range.end_hour_exclusive > range.start_hour ? (
                            <p className="text-muted-foreground text-xs">
                                {timeRangeHint(
                                    range.start_hour,
                                    range.end_hour_exclusive,
                                )}
                            </p>
                        ) : null}
                    </div>
                ))}
            </div>
            {canEdit ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        onChange([
                            ...ranges,
                            {
                                start_hour: 8,
                                end_hour_exclusive: 12,
                                day_group: 'mo_fr',
                            },
                        ])
                    }
                >
                    Zeitraum hinzufügen
                </Button>
            ) : null}
        </div>
    );
}
