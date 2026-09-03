import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ErrorState, LoadingState } from '@/components/feedback/states';
import { money } from '@/components/form-field';
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
    already_adopted: boolean;
    adoptions: { dispo_order_id: number; dispo_order_number: string }[];
};

type PositionsResponse = {
    positions: SelectablePosition[];
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

export function DispoOrderCreateDialog({
    calculationId,
    open,
    onOpenChange,
}: {
    calculationId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [positions, setPositions] = useState<SelectablePosition[]>([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(null);

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

                setPositions(data.positions);
                setSelectedIds(
                    data.positions
                        .filter((position) => !position.already_adopted)
                        .map((position) => position.id),
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
    }, [calculationId, open]);

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
        if (submitting || selectedIds.length === 0) {
            return;
        }

        setSubmitting(true);
        setError(null);

        router.post(
            `/kalkulationen/${calculationId}/dispoauftraege`,
            { position_ids: selectedIds },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                },
                onError: (errors) => {
                    setError(
                        firstValidationMessage(mapValidationErrors(errors)) ??
                            'Dispoauftrag konnte nicht angelegt werden.',
                    );
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                data-test="dispo-order-create-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Dispoauftrag anlegen</DialogTitle>
                    <DialogDescription>
                        Wählen Sie die Kalkulationspositionen, die in den
                        Dispoauftrag übernommen werden sollen.
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
                                                            {formatTimeRanges(
                                                                position.time_ranges,
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
                                                                Bereits
                                                                übernommen
                                                                {position.adoptions
                                                                    .map(
                                                                        (
                                                                            adoption,
                                                                        ) =>
                                                                            adoption.dispo_order_number,
                                                                    )
                                                                    .join(', ')}
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
                            ? 'Wird angelegt …'
                            : 'Dispoauftrag anlegen'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export type { SelectablePosition };
