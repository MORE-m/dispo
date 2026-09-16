import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ErrorState, LoadingState } from '@/components/feedback/states';
import { money } from '@/components/form-field';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formatPlannerEntriesSummary } from '@/lib/dispo-planner-display';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import {
    firstValidationMessage,
    mapValidationErrors,
} from '@/lib/validation-errors';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { DispoOrderRevisionContext } from '@/types/dispo-order';

type SelectablePosition = {
    id: number;
    inventory_name: string;
    advertising_medium_name: string;
    length_seconds: number;
    total_spot_count: number;
    nn_invest: string;
    time_ranges: {
        start_hour: number;
        end_hour_exclusive: number;
        day_group?: string;
        spot_count: number;
    }[];
    planner_entries?: {
        date: string;
        hour: number;
        day_group?: string;
        spot_count: number;
    }[];
    already_adopted: boolean;
    adoptions: { dispo_order_id: number; dispo_order_number: string }[];
};

type PositionsResponse = {
    positions: SelectablePosition[];
    preferred_position_ids?: number[];
    revision?: {
        predecessor_id: number;
        predecessor_number: string;
        rejection_reason: string | null;
    };
};

function formatTimeRanges(ranges: SelectablePosition['time_ranges']): string {
    if (ranges.length === 0) {
        return '–';
    }

    return ranges
        .map(
            (range) =>
                `${formatHour(range.start_hour)}–${formatInclusiveEnd(range.end_hour_exclusive)} · ${range.spot_count} Spots`,
        )
        .join('; ');
}

function formatPositionTiming(position: SelectablePosition): string {
    if (position.planner_entries && position.planner_entries.length > 0) {
        return formatPlannerEntriesSummary(position.planner_entries);
    }

    return formatTimeRanges(position.time_ranges);
}

function defaultSelectedIds(
    positions: SelectablePosition[],
    preferredIds: number[] | undefined,
    isRevision: boolean,
): number[] {
    const available = new Set(positions.map((position) => position.id));

    if (isRevision && preferredIds && preferredIds.length > 0) {
        const preferred = preferredIds.filter((id) => available.has(id));
        if (preferred.length > 0) {
            return preferred;
        }
    }

    if (isRevision) {
        return positions.map((position) => position.id);
    }

    return positions
        .filter((position) => !position.already_adopted)
        .map((position) => position.id);
}

export function DispoOrderCreateDialog({
    calculationId,
    open,
    onOpenChange,
    revision = null,
}: {
    calculationId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    revision?: DispoOrderRevisionContext | null;
}) {
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [positions, setPositions] = useState<SelectablePosition[]>([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [activeRevision, setActiveRevision] = useState<{
        predecessor_id: number;
        predecessor_number: string;
        rejection_reason: string | null;
    } | null>(revision);
    const inFlight = useRef(false);

    const isRevision = activeRevision !== null;

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(null);
        setActiveRevision(revision);

        fetch(`/kalkulationen/${calculationId}/dispoauftraege/positionen`, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Positionen konnten nicht geladen werden.');
                }

                return (await response.json()) as PositionsResponse;
            })
            .then((data) => {
                if (cancelled) {
                    return;
                }

                const nextRevision = data.revision ?? revision;
                setActiveRevision(nextRevision);
                setPositions(data.positions);
                setSelectedIds(
                    defaultSelectedIds(
                        data.positions,
                        data.preferred_position_ids,
                        nextRevision !== null,
                    ),
                );
            })
            .catch((loadError: Error) => {
                if (!cancelled) {
                    setError(loadError.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [calculationId, open, revision]);

    const allSelected = useMemo(
        () => positions.length > 0 && selectedIds.length === positions.length,
        [positions.length, selectedIds.length],
    );

    function togglePosition(id: number, checked: boolean) {
        setSelectedIds((current) =>
            checked
                ? [...new Set([...current, id])]
                : current.filter((value) => value !== id),
        );
    }

    function toggleAll(checked: boolean) {
        setSelectedIds(checked ? positions.map((position) => position.id) : []);
    }

    function handleSubmit() {
        if (inFlight.current || submitting || selectedIds.length === 0) {
            return;
        }

        inFlight.current = true;
        setSubmitting(true);
        setError(null);

        const payload =
            activeRevision !== null
                ? {
                      position_ids: selectedIds,
                      revises_dispo_order_id: activeRevision.predecessor_id,
                  }
                : {
                      position_ids: selectedIds,
                  };

        flushDispoOrderInertiaCache();
        router.post(`/kalkulationen/${calculationId}/dispoauftraege`, payload, {
            preserveScroll: true,
            invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            onSuccess: () => {
                onOpenChange(false);
            },
            onError: (errors) => {
                setError(
                    firstValidationMessage(mapValidationErrors(errors)) ??
                        (isRevision
                            ? 'Korrigierter Dispoauftrag konnte nicht angelegt werden.'
                            : 'Dispoauftrag konnte nicht angelegt werden.'),
                );
            },
            onFinish: () => {
                inFlight.current = false;
                setSubmitting(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                data-test="dispo-order-create-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {isRevision
                            ? 'Korrigierten Dispoauftrag erstellen'
                            : 'Dispoauftrag anlegen'}
                    </DialogTitle>
                    <DialogDescription>
                        {isRevision
                            ? `Wählen Sie die Positionen für die Korrektur von ${activeRevision.predecessor_number}. Bereits übernommene Positionen dürfen für diese Nachbesserung erneut gewählt werden.`
                            : 'Wählen Sie die Kalkulationspositionen, die in den Dispoauftrag übernommen werden sollen.'}
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <LoadingState label="Positionen werden geladen …" />
                ) : error && positions.length === 0 ? (
                    <ErrorState message={error} />
                ) : (
                    <div className="space-y-4">
                        {error ? <ErrorState message={error} /> : null}
                        {positions.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Keine übernehmbaren Positionen vorhanden.
                            </p>
                        ) : (
                            <>
                                <div className="flex items-center gap-2 border-b pb-3">
                                    <Checkbox
                                        id="select-all-positions"
                                        checked={allSelected}
                                        onCheckedChange={(checked) =>
                                            toggleAll(checked === true)
                                        }
                                        data-test="dispo-order-select-all"
                                    />
                                    <Label htmlFor="select-all-positions">
                                        Alle Positionen auswählen
                                    </Label>
                                </div>
                                <ul className="space-y-3">
                                    {positions.map((position) => {
                                        const checked = selectedIds.includes(
                                            position.id,
                                        );
                                        const inputId = `dispo-position-${position.id}`;

                                        return (
                                            <li
                                                key={position.id}
                                                className="rounded-lg border p-4"
                                                data-test={`dispo-order-position-${position.id}`}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <Checkbox
                                                        id={inputId}
                                                        checked={checked}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            togglePosition(
                                                                position.id,
                                                                value === true,
                                                            )
                                                        }
                                                        data-test={`dispo-order-position-checkbox-${position.id}`}
                                                    />
                                                    <div className="min-w-0 flex-1 space-y-1">
                                                        <Label
                                                            htmlFor={inputId}
                                                            className="text-sm font-medium"
                                                        >
                                                            {
                                                                position.inventory_name
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                position.advertising_medium_name
                                                            }
                                                        </Label>
                                                        <p className="text-muted-foreground text-xs">
                                                            {
                                                                position.total_spot_count
                                                            }{' '}
                                                            Spots ·{' '}
                                                            {
                                                                position.length_seconds
                                                            }
                                                            s ·{' '}
                                                            {formatPositionTiming(
                                                                position,
                                                            )}
                                                        </p>
                                                        <p className="text-primary text-sm font-semibold tabular-nums">
                                                            {money(
                                                                position.nn_invest,
                                                            )}{' '}
                                                            N/N
                                                        </p>
                                                        {position.already_adopted ? (
                                                            <p
                                                                className="text-xs font-medium text-orange-700"
                                                                data-test={`dispo-order-position-adopted-${position.id}`}
                                                            >
                                                                {isRevision
                                                                    ? 'Bereits in einem früheren Dispoauftrag enthalten'
                                                                    : 'Bereits übernommen'}
                                                                {position.adoptions
                                                                    .map(
                                                                        (
                                                                            adoption,
                                                                        ) =>
                                                                            ` ${adoption.dispo_order_number}`,
                                                                    )
                                                                    .join(',')}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Abbrechen
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSubmit}
                        disabled={
                            submitting ||
                            loading ||
                            selectedIds.length === 0 ||
                            positions.length === 0
                        }
                        data-test="dispo-order-submit"
                    >
                        {submitting
                            ? isRevision
                                ? 'Wird erstellt …'
                                : 'Wird angelegt …'
                            : isRevision
                              ? 'Korrigierten Dispoauftrag erstellen'
                              : 'Dispoauftrag anlegen'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export type { SelectablePosition };
