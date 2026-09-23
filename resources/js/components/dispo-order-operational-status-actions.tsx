import { router } from '@inertiajs/react';
import { useId, useMemo, useState } from 'react';
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

export type OperationalStatusTarget = {
    value: string;
    label: string;
    requires_reason: boolean;
};

type ActionResponse = {
    message: string;
    redirect: string;
};

const ACTION_LABELS: Record<string, string> = {
    in_progress: 'Bearbeitung starten',
    material_missing: 'Material fehlt',
    material_received: 'Material erhalten',
    disposed: 'Disponiert',
};

function actionLabel(target: OperationalStatusTarget, currentStatus: string): string {
    if (target.value === 'in_progress') {
        if (currentStatus === 'at_disposition') {
            return 'Bearbeitung starten';
        }
        if (currentStatus === 'disposed') {
            return 'Wieder öffnen';
        }
        return 'Zurück in Bearbeitung';
    }

    return ACTION_LABELS[target.value] ?? target.label;
}

export function DispoOrderOperationalStatusActions({
    orderId,
    lockVersion,
    status,
    canTransition,
    targets,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    canTransition: boolean;
    targets: OperationalStatusTarget[];
}) {
    const [reopenTarget, setReopenTarget] = useState<OperationalStatusTarget | null>(
        null,
    );
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reasonId = useId();

    const visibleTargets = useMemo(
        () => (canTransition ? targets : []),
        [canTransition, targets],
    );

    if (visibleTargets.length === 0) {
        return null;
    }

    async function runTransition(
        target: OperationalStatusTarget,
        body: Record<string, unknown> = {},
    ) {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/status`,
                {
                    lock_version: lockVersion,
                    target_status: target.value,
                    ...body,
                },
            );
            setReopenTarget(null);
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

    function onTargetClick(target: OperationalStatusTarget) {
        setError(null);
        if (target.requires_reason) {
            setReason('');
            setReopenTarget(target);
            return;
        }
        void runTransition(target);
    }

    return (
        <div
            className="flex flex-col gap-3"
            data-test="dispo-order-operational-status-actions"
        >
            {error && reopenTarget === null ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-operational-status-error"
                />
            ) : null}

            <div className="flex flex-wrap gap-2">
                {visibleTargets.map((target) => (
                    <Button
                        key={target.value}
                        type="button"
                        variant={
                            target.value === 'disposed' ? 'default' : 'outline'
                        }
                        data-test={`dispo-order-status-action-${target.value}`}
                        disabled={submitting}
                        onClick={() => onTargetClick(target)}
                    >
                        {actionLabel(target, status)}
                    </Button>
                ))}
            </div>

            <Dialog
                open={reopenTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setReopenTarget(null);
                        setReason('');
                        setError(null);
                    }
                }}
            >
                <DialogContent data-test="dispo-order-reopen-dialog">
                    <DialogHeader>
                        <DialogTitle>Wieder öffnen</DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird von „Disponiert“ zurück auf „In
                            Bearbeitung“ gesetzt. Bitte begründen Sie die
                            Wiederöffnung.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={reasonId}>Begründung</Label>
                        <textarea
                            id={reasonId}
                            className={formTextareaClass}
                            value={reason}
                            data-test="dispo-order-reopen-reason"
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-reopen-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => {
                                setReopenTarget(null);
                                setReason('');
                                setError(null);
                            }}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-reopen-confirm"
                            disabled={submitting || reason.trim() === ''}
                            onClick={() => {
                                if (reopenTarget === null) {
                                    return;
                                }
                                void runTransition(reopenTarget, {
                                    reason: reason.trim(),
                                });
                            }}
                        >
                            Wieder öffnen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
