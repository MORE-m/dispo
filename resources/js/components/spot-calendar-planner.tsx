import { useMemo, useState } from 'react';
import { money } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    addDays,
    cellFieldError,
    daySpotSum,
    entryTotalsByCellKey,
    formatDateOnly,
    formatHour,
    hourSpotSum,
    isDateInPriceYear,
    isHourBookable,
    parseDateOnly,
    shiftMonthAnchor,
    spotsForCell,
    spotsOutsideWeek,
    startOfWeekMonday,
    totalPlannerSpotCount,
    upsertPlannerCellSpots,
    visibleHoursFromPriceListItems,
    weekDates,
    type PlannerEntryDraft,
    type PriceListHourItem,
} from '@/lib/pricing-calendar';

const WEEKDAY_LABELS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as const;

export function SpotCalendarPlanner({
    positionIndex,
    entries,
    canEdit,
    fieldErrors,
    entryTotals,
    positionMediaGross,
    priceListHours,
    priceYear,
    onChange,
}: {
    positionIndex: number;
    entries: PlannerEntryDraft[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    entryTotals?: Array<{
        date: string;
        hour: number;
        line_gross?: string;
        second_price?: string;
    }>;
    positionMediaGross?: string | null;
    priceListHours: PriceListHourItem[];
    priceYear: number | null;
    onChange: (entries: PlannerEntryDraft[]) => void;
}) {
    const spots = totalPlannerSpotCount(entries);
    const hasCompleteEntry = spots > 0;
    const hours = useMemo(
        () => visibleHoursFromPriceListItems(priceListHours),
        [priceListHours],
    );
    const totalsByKey = useMemo(
        () => entryTotalsByCellKey(entryTotals),
        [entryTotals],
    );

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
    const dates = weekDates(weekAnchor);
    const weekEnd = addDays(weekAnchor, 6);
    const weekLabel = `${formatDateOnly(weekAnchor)} – ${formatDateOnly(weekEnd)}`;
    const outsideWeek = spotsOutsideWeek(entries, dates);

    function shiftWeek(deltaDays: number) {
        setWeekAnchor((current) => addDays(current, deltaDays));
    }

    function shiftMonth(deltaMonths: number) {
        setWeekAnchor((current) => shiftMonthAnchor(current, deltaMonths));
    }

    function goToCurrentWeek() {
        setWeekAnchor(startOfWeekMonday(new Date()));
    }

    function setCellSpots(date: string, hour: number, raw: string) {
        const spotCount = raw === '' ? '' : Number(raw);
        onChange(upsertPlannerCellSpots(entries, date, hour, spotCount));
    }

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

            {priceYear != null ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`planner-price-year-${positionIndex}`}
                >
                    Preisjahr {priceYear}. Jahresübergreifende Planung braucht
                    getrennte Positionen.
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                {canEdit ? (
                    <>
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
                    </>
                ) : null}
                <p
                    className="text-muted-foreground text-sm tabular-nums"
                    data-test={`planner-week-label-${positionIndex}`}
                >
                    Woche: {weekLabel}
                </p>
                {canEdit ? (
                    <>
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
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test={`planner-today-${positionIndex}`}
                            onClick={goToCurrentWeek}
                        >
                            Aktuelle Woche
                        </Button>
                    </>
                ) : null}
            </div>

            {!hasCompleteEntry ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`planner-empty-${positionIndex}`}
                >
                    Noch keine Kalendereinträge. Trage Spotanzahlen in buchbare
                    Zellen der Woche ein.
                </p>
            ) : null}

            {outsideWeek > 0 ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`planner-outside-week-${positionIndex}`}
                >
                    {outsideWeek} Spots außerhalb der sichtbaren Woche bleiben
                    erhalten.
                </p>
            ) : null}

            {hours.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Für die gewählte Preisliste liegen keine buchbaren
                    Preisstunden vor.
                </p>
            ) : (
                <div
                    className="overflow-x-auto"
                    data-test={`planner-grid-${positionIndex}`}
                >
                    <table className="border-border/60 w-full min-w-[44rem] border-collapse text-sm">
                        <thead>
                            <tr>
                                <th
                                    scope="col"
                                    className="bg-background border-border/60 sticky left-0 z-10 border px-2 py-2 text-left font-medium"
                                >
                                    Stunde
                                </th>
                                {dates.map((date, dayIndex) => {
                                    const inYear = isDateInPriceYear(
                                        date,
                                        priceYear,
                                    );

                                    return (
                                        <th
                                            key={date}
                                            scope="col"
                                            className="border-border/60 border px-2 py-2 text-center font-medium"
                                            data-test={`planner-day-header-${positionIndex}-${date}`}
                                        >
                                            <span className="block">
                                                {WEEKDAY_LABELS[dayIndex]}
                                            </span>
                                            <span className="text-muted-foreground block text-xs tabular-nums">
                                                {date}
                                            </span>
                                            {!inYear ? (
                                                <span className="text-muted-foreground mt-1 block text-xs font-normal">
                                                    Außerhalb Preisjahr{' '}
                                                    {priceYear}
                                                </span>
                                            ) : null}
                                        </th>
                                    );
                                })}
                                <th
                                    scope="col"
                                    className="border-border/60 border px-2 py-2 text-center font-medium"
                                >
                                    Summe
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {hours.map((hour) => (
                                <tr
                                    key={hour}
                                    data-test={`planner-hour-row-${positionIndex}-${hour}`}
                                >
                                    <th
                                        scope="row"
                                        className="bg-background border-border/60 sticky left-0 z-10 border px-2 py-1.5 text-left font-medium tabular-nums"
                                    >
                                        {formatHour(hour)}
                                    </th>
                                    {dates.map((date) => {
                                        const inYear = isDateInPriceYear(
                                            date,
                                            priceYear,
                                        );
                                        const bookable =
                                            inYear &&
                                            isHourBookable(
                                                priceListHours,
                                                date,
                                                hour,
                                            );
                                        const value = spotsForCell(
                                            entries,
                                            date,
                                            hour,
                                        );
                                        const error = cellFieldError(
                                            fieldErrors,
                                            positionIndex,
                                            entries,
                                            date,
                                            hour,
                                        );
                                        const gross =
                                            totalsByKey[`${date}|${hour}`]
                                                ?.line_gross;
                                        const inputId = `planner-cell-spots-${positionIndex}-${date}-${hour}`;
                                        const disabledReason = !inYear
                                            ? `Datum ${date} liegt außerhalb des Preisjahres ${priceYear}.`
                                            : !bookable
                                              ? `Stunde ${formatHour(hour)} am ${date} ist nicht buchbar.`
                                              : undefined;

                                        return (
                                            <td
                                                key={`${date}-${hour}`}
                                                className="border-border/60 border px-1.5 py-1 align-top"
                                                data-test={`planner-cell-${positionIndex}-${date}-${hour}`}
                                            >
                                                <label
                                                    className="sr-only"
                                                    htmlFor={inputId}
                                                >
                                                    Spots am {date} um{' '}
                                                    {formatHour(hour)}
                                                </label>
                                                <Input
                                                    id={inputId}
                                                    data-test={inputId}
                                                    type="number"
                                                    step={1}
                                                    min={0}
                                                    value={value}
                                                    disabled={
                                                        !canEdit || !bookable
                                                    }
                                                    aria-disabled={
                                                        !bookable
                                                            ? true
                                                            : undefined
                                                    }
                                                    title={disabledReason}
                                                    aria-describedby={
                                                        disabledReason
                                                            ? `${inputId}-hint`
                                                            : error
                                                              ? `${inputId}-error`
                                                              : undefined
                                                    }
                                                    className="h-8 text-center tabular-nums"
                                                    onChange={(event) =>
                                                        setCellSpots(
                                                            date,
                                                            hour,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                {!bookable && disabledReason ? (
                                                    <span
                                                        id={`${inputId}-hint`}
                                                        className="sr-only"
                                                    >
                                                        {disabledReason}
                                                    </span>
                                                ) : null}
                                                {gross ? (
                                                    <p
                                                        className="text-muted-foreground mt-1 text-center text-xs tabular-nums"
                                                        data-test={`planner-cell-gross-${positionIndex}-${date}-${hour}`}
                                                    >
                                                        {money(gross)}
                                                    </p>
                                                ) : null}
                                                {error ? (
                                                    <p
                                                        id={`${inputId}-error`}
                                                        className="text-destructive mt-1 text-xs"
                                                        role="alert"
                                                    >
                                                        {error}
                                                    </p>
                                                ) : null}
                                            </td>
                                        );
                                    })}
                                    <td
                                        className="border-border/60 border px-2 py-1 text-center font-medium tabular-nums"
                                        data-test={`planner-hour-sum-${positionIndex}-${hour}`}
                                    >
                                        {hourSpotSum(entries, hour, dates)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <th
                                    scope="row"
                                    className="bg-background border-border/60 sticky left-0 z-10 border px-2 py-2 text-left font-medium"
                                >
                                    Tagessumme
                                </th>
                                {dates.map((date) => (
                                    <td
                                        key={`sum-${date}`}
                                        className="border-border/60 border px-2 py-2 text-center font-medium tabular-nums"
                                        data-test={`planner-day-sum-${positionIndex}-${date}`}
                                    >
                                        {daySpotSum(entries, date)}
                                    </td>
                                ))}
                                <td className="border-border/60 border px-2 py-2 text-center font-medium tabular-nums">
                                    {spots}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}

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
