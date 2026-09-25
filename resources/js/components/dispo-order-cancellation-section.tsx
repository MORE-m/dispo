import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export type CancellationSummary = {
    cancelled_by_name: string;
    cancelled_at: string;
    reason: string | null;
    from_status: string;
    from_status_label: string;
    available: boolean;
    unavailable_message: string | null;
};

type ActionResponse = {
    message: string;
    redirect: string;
};

function formatTimestamp(value: string): string {
    if (value === '') {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString('de-DE');
}

export function DispoOrderCancellationSection({
    orderId,
    lockVersion,
    status,
    canCancel,
    summary,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    canCancel: boolean;
    summary: CancellationSummary | null;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reasonId = useId();

    async function cancel() {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/stornieren`,
                {
                    lock_version: lockVersion,
                    reason: reason.trim(),
                },
            );
            setOpen(false);
            setReason('');
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

    if (status === 'cancelled' && summary) {
        return (
            <section
                className="border-border/70 space-y-2 rounded-xl border p-4 shadow-xs"
                data-test="dispo-order-cancellation-summary"
            >
                <h2 className="text-sm font-semibold">Storniert</h2>
                {summary.available ? (
                    <>
                        <p
                            className="text-sm"
                            data-test="dispo-order-cancelled-by"
                        >
                            Storniert von {summary.cancelled_by_name}
                        </p>
                        <p
                            className="text-muted-foreground text-xs"
                            data-test="dispo-order-cancelled-at"
                        >
                            {formatTimestamp(summary.cancelled_at)}
                        </p>
                        {summary.from_status_label ? (
                            <p
                                className="text-sm"
                                data-test="dispo-order-cancelled-from"
                            >
                                Vorheriger Status: {summary.from_status_label}
                            </p>
                        ) : null}
                        {summary.reason ? (
                            <p
                                className="text-sm"
                                data-test="dispo-order-cancelled-reason"
                            >
                                Begründung: {summary.reason}
                            </p>
                        ) : null}
                    </>
                ) : (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="dispo-order-cancellation-unavailable"
                    >
                        {summary.unavailable_message ??
                            'Historische Stornodaten nicht verfügbar.'}
                    </p>
                )}
            </section>
        );
    }

    if (!canCancel) {
        return null;
    }

    return (
        <section
            className="border-border/70 space-y-3 rounded-xl border border-red-200/80 p-4 shadow-xs"
            data-test="dispo-order-cancellation-section"
        >
            <h2 className="text-sm font-semibold text-red-900">Stornierung</h2>
            <p className="text-muted-foreground text-xs">
                Der Auftrag wird dauerhaft auf „Storniert“ gesetzt.
            </p>

            {error && !open ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-cancel-error"
                />
            ) : null}

            <Button
                type="button"
                variant="destructive"
                data-test="dispo-order-cancel-action"
                disabled={submitting}
                onClick={() => {
                    setError(null);
                    setReason('');
                    setOpen(true);
                }}
            >
                Auftrag stornieren
            </Button>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!next) {
                        setOpen(false);
                        setReason('');
                        setError(null);
                    }
                }}
            >
                <DialogContent data-test="dispo-order-cancel-dialog">
                    <DialogHeader>
                        <DialogTitle>Dispoauftrag stornieren</DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird auf „Storniert“ gesetzt. Dieser
                            Status kann in V1 nicht wieder geöffnet werden.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={reasonId}>Begründung</Label>
                        <textarea
                            id={reasonId}
                            className={formTextareaClass}
                            value={reason}
                            data-test="dispo-order-cancel-reason"
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-cancel-dialog-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => {
                                setOpen(false);
                                setReason('');
                                setError(null);
                            }}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="dispo-order-cancel-confirm"
                            disabled={submitting || reason.trim() === ''}
                            onClick={() => void cancel()}
                        >
                            Auftrag stornieren
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
