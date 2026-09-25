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

type ActionResponse = {
    message: string;
    redirect: string;
};

export function DispoOrderCompletedReopenSection({
    orderId,
    lockVersion,
    canReopenCompleted,
}: {
    orderId: number;
    lockVersion: number;
    canReopenCompleted: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reasonId = useId();

    if (!canReopenCompleted) {
        return null;
    }

    async function reopen() {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/wieder-oeffnen`,
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

    return (
        <section
            className="border-border/70 space-y-3 rounded-xl border p-4 shadow-xs"
            data-test="dispo-order-completed-reopen-section"
        >
            <h2 className="text-sm font-semibold">Wieder öffnen</h2>
            <p className="text-muted-foreground text-xs">
                Der Auftrag wird zurück in „In Bearbeitung“ gesetzt.
            </p>

            {error && !open ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-reopen-completed-error"
                />
            ) : null}

            <Button
                type="button"
                variant="outline"
                data-test="dispo-order-reopen-completed-action"
                disabled={submitting}
                onClick={() => {
                    setError(null);
                    setReason('');
                    setOpen(true);
                }}
            >
                Wieder öffnen
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
                <DialogContent data-test="dispo-order-reopen-completed-dialog">
                    <DialogHeader>
                        <DialogTitle>
                            Abgeschlossenen Auftrag wieder öffnen
                        </DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird zurück in „In Bearbeitung“ gesetzt.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={reasonId}>Begründung</Label>
                        <textarea
                            id={reasonId}
                            className={formTextareaClass}
                            value={reason}
                            data-test="dispo-order-reopen-completed-reason"
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-reopen-completed-dialog-error"
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
                            data-test="dispo-order-reopen-completed-confirm"
                            disabled={submitting || reason.trim() === ''}
                            onClick={() => void reopen()}
                        >
                            Wieder öffnen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
