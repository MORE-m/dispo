import { useMemo, useState } from 'react';
import { FormField, formSelectClass, money } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    HOURS,
    addDays,
    emptyPlannerEntry,
    formatDateOnly,
    formatHour,
    isCompletePlannerEntry,
    parseDateOnly,
    shiftMonthAnchor,
    startOfWeekMonday,
    totalPlannerSpotCount,
    type PlannerEntryDraft,
} from '@/lib/pricing-calendar';

type EntryTotals = {
    line_gross?: string;
    second_price?: string;
};

export function SpotCalendarPlanner({
    positionIndex,
    entries,
    canEdit,
    fieldErrors,
    entryTotals,
    positionMediaGross,
    onChange,
}: {
    positionIndex: number;
    entries: PlannerEntryDraft[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    entryTotals?: EntryTotals[];
    positionMediaGross?: string | null;
    onChange: (entries: PlannerEntryDraft[]) => void;
}) {
    const spots = totalPlannerSpotCount(entries);
    const hasCompleteEntry = entries.some(isCompletePlannerEntry);

    const initialWeekAnchor = useMemo(() => {
        const firstDated = entries.find((entry) => entry.date.trim() !== '');
        if (firstDated) {
            const parsed = parseDateOnly(firstDated.date);
            if (parsed) {
                return startOfWeekMonday(parsed);
            }
        }

        return startOfWeekMonday(new Date());
    }, [entries]);

    const [weekAnchor, setWeekAnchor] = useState(initialWeekAnchor);

    function updateEntry(index: number, patch: Partial<PlannerEntryDraft>) {
        onChange(
            entries.map((entry, current) =>
                current === index ? { ...entry, ...patch } : entry,
            ),
        );
    }

    function shiftWeek(deltaDays: number) {
        setWeekAnchor((current) => addDays(current, deltaDays));
    }

    function shiftMonth(deltaMonths: number) {
        setWeekAnchor((current) => shiftMonthAnchor(current, deltaMonths));
    }

    function addEntry() {
        onChange([...entries, emptyPlannerEntry(formatDateOnly(weekAnchor))]);
    }

    const weekEnd = addDays(weekAnchor, 6);
    const weekLabel = `${formatDateOnly(weekAnchor)} – ${formatDateOnly(weekEnd)}`;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <p className="text-sm font-medium">Kalenderplaner</p>
                <p
                    className="text-sm"
                    data-test={`planner-total-spots-${positionIndex}`}
                >
                    <span className="text-muted-foreground">
                        Gesamtspotzahl{' '}
                    </span>
                    <span className="font-semibold tabular-nums">{spots}</span>
                </p>
            </div>

            {canEdit ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`planner-month-prev-${positionIndex}`}
                        onClick={() => shiftMonth(-1)}
                    >
                        Vorheriger Monat
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`planner-week-prev-${positionIndex}`}
                        onClick={() => shiftWeek(-7)}
                    >
                        Vorherige Woche
                    </Button>
                    <p
                        className="text-muted-foreground text-sm tabular-nums"
                        data-test={`planner-week-label-${positionIndex}`}
                    >
                        Referenzwoche: {weekLabel}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`planner-week-next-${positionIndex}`}
                        onClick={() => shiftWeek(7)}
                    >
                        Nächste Woche
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`planner-month-next-${positionIndex}`}
                        onClick={() => shiftMonth(1)}
                    >
                        Nächster Monat
                    </Button>
                </div>
            ) : (
                <p
                    className="text-muted-foreground text-sm tabular-nums"
                    data-test={`planner-week-label-${positionIndex}`}
                >
                    Referenzwoche: {weekLabel}
                </p>
            )}

            {!hasCompleteEntry ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`planner-empty-${positionIndex}`}
                >
                    Noch keine Kalendereinträge. Füge mindestens eine Zeile mit
                    Datum, Preisstunde und Spotanzahl hinzu.
                </p>
            ) : null}

            <div className="space-y-3">
                {entries.map((entry, entryIndex) => {
                    const complete = isCompletePlannerEntry(entry);
                    const totals = entryTotals?.[entryIndex];

                    return (
                        <div
                            key={`planner-${entryIndex}`}
                            className="border-border/60 bg-muted/15 space-y-3 rounded-lg border p-3"
                            data-test={`planner-entry-${positionIndex}-${entryIndex}`}
                        >
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,7rem)_auto]">
                                <FormField
                                    label="Datum"
                                    htmlFor={`planner-date-${positionIndex}-${entryIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.planner_entries.${entryIndex}.date`
                                        ]?.[0] ??
                                        fieldErrors[
                                            `positions.${positionIndex}.planner_entries`
                                        ]?.[0]
                                    }
                                >
                                    <Input
                                        id={`planner-date-${positionIndex}-${entryIndex}`}
                                        data-test={`planner-date-${positionIndex}-${entryIndex}`}
                                        type="date"
                                        value={entry.date}
                                        disabled={!canEdit}
                                        onChange={(event) =>
                                            updateEntry(entryIndex, {
                                                date: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    label="Preisstunde"
                                    htmlFor={`planner-hour-${positionIndex}-${entryIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.planner_entries.${entryIndex}.hour`
                                        ]?.[0]
                                    }
                                >
                                    <select
                                        id={`planner-hour-${positionIndex}-${entryIndex}`}
                                        data-test={`planner-hour-${positionIndex}-${entryIndex}`}
                                        className={formSelectClass}
                                        value={entry.hour}
                                        disabled={!canEdit}
                                        onChange={(event) => {
                                            const raw = event.target.value;
                                            updateEntry(entryIndex, {
                                                hour:
                                                    raw === ''
                                                        ? ''
                                                        : Number(raw),
                                            });
                                        }}
                                    >
                                        <option value="">–</option>
                                        {HOURS.map((hour) => (
                                            <option key={hour} value={hour}>
                                                {formatHour(hour)}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                <FormField
                                    label="Spots"
                                    htmlFor={`planner-spots-${positionIndex}-${entryIndex}`}
                                    error={
                                        fieldErrors[
                                            `positions.${positionIndex}.planner_entries.${entryIndex}.spot_count`
                                        ]?.[0]
                                    }
                                >
                                    <Input
                                        id={`planner-spots-${positionIndex}-${entryIndex}`}
                                        data-test={`planner-spots-${positionIndex}-${entryIndex}`}
                                        type="number"
                                        step={1}
                                        value={entry.spot_count}
                                        disabled={!canEdit}
                                        onChange={(event) => {
                                            const raw = event.target.value;
                                            updateEntry(entryIndex, {
                                                spot_count:
                                                    raw === ''
                                                        ? ''
                                                        : Number(raw),
                                            });
                                        }}
                                    />
                                </FormField>
                                {complete && totals?.line_gross ? (
                                    <div className="flex flex-col justify-end text-sm">
                                        <span className="text-muted-foreground">
                                            Zeilensumme
                                        </span>
                                        <span
                                            className="text-foreground font-medium tabular-nums"
                                            data-test={`planner-line-gross-${positionIndex}-${entryIndex}`}
                                        >
                                            {money(totals.line_gross)}
                                        </span>
                                    </div>
                                ) : (
                                    <div className="hidden lg:block" />
                                )}
                                {canEdit && entries.length > 1 ? (
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            data-test={`planner-remove-${positionIndex}-${entryIndex}`}
                                            onClick={() =>
                                                onChange(
                                                    entries.filter(
                                                        (_, current) =>
                                                            current !==
                                                            entryIndex,
                                                    ),
                                                )
                                            }
                                        >
                                            Entfernen
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                            {complete && totals?.line_gross ? (
                                <p className="text-muted-foreground text-xs lg:hidden">
                                    Zeilensumme{' '}
                                    <span
                                        className="text-foreground font-medium"
                                        data-test={`planner-line-gross-mobile-${positionIndex}-${entryIndex}`}
                                    >
                                        {money(totals.line_gross)}
                                    </span>
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
                    data-test={`planner-add-${positionIndex}`}
                    onClick={addEntry}
                >
                    Kalendereintrag hinzufügen
                </Button>
            ) : null}

            {positionMediaGross ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`planner-position-gross-${positionIndex}`}
                >
                    Positionsbrutto (Live):{' '}
                    <span className="text-foreground font-medium">
                        {money(positionMediaGross)}
                    </span>
                </p>
            ) : null}
        </div>
    );
}
