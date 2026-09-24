import { router } from '@inertiajs/react';
import { useId, useMemo, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { Button } from '@/components/ui/button';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { JsonPostError, jsonPut } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export const INVOICE_END_MONTH_OPTIONS: { value: number; label: string }[] = [
    { value: 1, label: 'Januar' },
    { value: 2, label: 'Februar' },
    { value: 3, label: 'März' },
    { value: 4, label: 'April' },
    { value: 5, label: 'Mai' },
    { value: 6, label: 'Juni' },
    { value: 7, label: 'Juli' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'Oktober' },
    { value: 11, label: 'November' },
    { value: 12, label: 'Dezember' },
];

type ActionResponse = {
    message: string;
    redirect: string;
};

function monthLabel(value: number): string {
    return (
        INVOICE_END_MONTH_OPTIONS.find((option) => option.value === value)
            ?.label ?? String(value)
    );
}

export function DispoOrderInvoiceEndMonths({
    orderId,
    positionId,
    lockVersion,
    months,
    monthLabels,
    periodState,
    canEdit,
}: {
    orderId: number;
    positionId: number;
    lockVersion: number;
    months: number[] | null;
    monthLabels?: string[];
    periodState: 'open' | 'concrete' | 'invalid';
    canEdit: boolean;
}) {
    const [selected, setSelected] = useState<number[]>(() => months ?? []);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const groupId = useId();

    const readOnlyLabels = useMemo(() => {
        if (monthLabels && monthLabels.length > 0) {
            return monthLabels;
        }
        return (months ?? []).map(monthLabel);
    }, [monthLabels, months]);

    async function save(next: number[]) {
        if (submitting || !canEdit) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPut<ActionResponse>(
                `/dispoauftraege/${orderId}/positionen/${positionId}/rechnung-per-ende`,
                {
                    lock_version: lockVersion,
                    months: next,
                },
            );
            flushDispoOrderInertiaCache(orderId);
            router.visit(result.redirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : (firstValidationMessage(caught.fieldErrors) ??
                              caught.message),
                );
            } else {
                setError('Die Anfrage ist fehlgeschlagen.');
            }
            setSubmitting(false);
        }
    }

    function toggleMonth(value: number) {
        const next = selected.includes(value)
            ? selected.filter((month) => month !== value)
            : [...selected, value].sort((a, b) => a - b);
        setSelected(next);
    }

    return (
        <div
            className="mt-3 space-y-2"
            data-test={`dispo-order-invoice-end-${positionId}`}
        >
            <p className="text-sm font-medium">Rechnung per Ende</p>

            {periodState === 'open' ? (
                <p
                    className="text-muted-foreground text-xs"
                    data-test="dispo-order-invoice-end-hint-open"
                >
                    Bei offenem Zeitraum ist noch keine Auswahl erforderlich.
                </p>
            ) : null}

            {periodState === 'concrete' &&
            (months === null || months.length === 0) ? (
                <p
                    className="text-muted-foreground text-xs"
                    data-test="dispo-order-invoice-end-hint-required"
                >
                    Vor Abschluss muss mindestens ein Rechnungsmonat gewählt
                    werden.
                </p>
            ) : null}

            {canEdit ? (
                <>
                    <div
                        className="flex flex-wrap gap-1.5"
                        role="group"
                        aria-labelledby={groupId}
                        data-test="dispo-order-invoice-end-options"
                    >
                        <span id={groupId} className="sr-only">
                            Rechnungsmonate
                        </span>
                        {INVOICE_END_MONTH_OPTIONS.map((option) => {
                            const active = selected.includes(option.value);
                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    data-test={`dispo-order-invoice-end-month-${option.value}`}
                                    disabled={submitting}
                                    aria-pressed={active}
                                    className={
                                        active
                                            ? 'border-primary bg-primary/10 rounded-md border px-2 py-1 text-xs font-medium'
                                            : 'border-border/70 text-muted-foreground hover:bg-muted/40 rounded-md border px-2 py-1 text-xs'
                                    }
                                    onClick={() => toggleMonth(option.value)}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-invoice-end-error"
                        />
                    ) : null}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="dispo-order-invoice-end-save"
                        disabled={submitting}
                        onClick={() => void save(selected)}
                    >
                        Speichern
                    </Button>
                </>
            ) : (
                <p
                    className="text-sm"
                    data-test="dispo-order-invoice-end-readonly"
                >
                    {readOnlyLabels.length > 0
                        ? readOnlyLabels.join(', ')
                        : 'Noch nicht festgelegt'}
                </p>
            )}
        </div>
    );
}
