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

export type CompletionCheck = {
    key: string;
    label: string;
    passed: boolean;
    violations: { message: string }[];
};

export type CompletionReadiness = {
    ready: boolean;
    checks: CompletionCheck[];
};

export type CompletionSummary = {
    completed_by_name: string;
    completed_at: string;
    is_completion_override: boolean;
    override_reason: string | null;
    override_violations: {
        key: string;
        label: string;
        messages: string[];
    }[];
};

type ActionResponse = {
    message: string;
    redirect: string;
};

function formatTimestamp(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString('de-DE');
}

export function DispoOrderCompletionSection({
    orderId,
    lockVersion,
    status,
    canComplete,
    canForceComplete,
    readiness,
    summary,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    canComplete: boolean;
    canForceComplete: boolean;
    readiness: CompletionReadiness | null;
    summary: CompletionSummary | null;
}) {
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [overrideReason, setOverrideReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reasonId = useId();

    if (status !== 'disposed' && status !== 'completed') {
        return null;
    }

    async function complete(body: Record<string, unknown> = {}) {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/abschliessen`,
                {
                    lock_version: lockVersion,
                    ...body,
                },
            );
            setOverrideOpen(false);
            setOverrideReason('');
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

    if (status === 'completed' && summary) {
        return (
            <section
                className="border-border/70 space-y-2 rounded-xl border p-4 shadow-xs"
                data-test="dispo-order-completion-summary"
            >
                <h2 className="text-sm font-semibold">Abschluss</h2>
                <p className="text-sm" data-test="dispo-order-completed-by">
                    Abgeschlossen von {summary.completed_by_name}
                </p>
                <p
                    className="text-muted-foreground text-xs"
                    data-test="dispo-order-completed-at"
                >
                    {formatTimestamp(summary.completed_at)}
                </p>
                {summary.is_completion_override ? (
                    <div
                        className="space-y-1 text-sm"
                        data-test="dispo-order-completion-override-info"
                    >
                        <p className="font-medium">
                            Mit Admin-Override abgeschlossen
                        </p>
                        {summary.override_reason ? (
                            <p data-test="dispo-order-completion-override-reason">
                                Begründung: {summary.override_reason}
                            </p>
                        ) : null}
                        {summary.override_violations.length > 0 ? (
                            <ul
                                className="text-muted-foreground list-disc space-y-1 pl-5 text-xs"
                                data-test="dispo-order-completion-override-violations"
                            >
                                {summary.override_violations.map((item) => (
                                    <li key={item.key}>
                                        {item.label}
                                        {item.messages.length > 0
                                            ? `: ${item.messages.join('; ')}`
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ) : null}
            </section>
        );
    }

    if (status !== 'disposed' || readiness === null) {
        return null;
    }

    return (
        <section
            className="border-border/70 space-y-3 rounded-xl border p-4 shadow-xs"
            data-test="dispo-order-completion-section"
        >
            <h2 className="text-sm font-semibold">Abschluss</h2>

            <ul
                className="space-y-1.5 text-sm"
                data-test="dispo-order-completion-checks"
            >
                {readiness.checks.map((check) => (
                    <li
                        key={check.key}
                        data-test={`dispo-order-completion-check-${check.key}`}
                        className="flex flex-col gap-0.5"
                    >
                        <span>
                            {check.label}:{' '}
                            <span
                                className={
                                    check.passed
                                        ? 'text-emerald-700'
                                        : 'text-amber-800'
                                }
                            >
                                {check.passed ? '✓' : 'Problem'}
                            </span>
                        </span>
                        {!check.passed
                            ? check.violations.map((violation) => (
                                  <span
                                      key={violation.message}
                                      className="text-muted-foreground text-xs"
                                  >
                                      {violation.message}
                                  </span>
                              ))
                            : null}
                    </li>
                ))}
            </ul>

            {error && !overrideOpen ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-completion-error"
                />
            ) : null}

            <div className="flex flex-wrap gap-2">
                {canComplete && readiness.ready ? (
                    <Button
                        type="button"
                        data-test="dispo-order-complete-action"
                        disabled={submitting}
                        onClick={() => void complete()}
                    >
                        Auftrag abschließen
                    </Button>
                ) : null}

                {canForceComplete && !readiness.ready ? (
                    <Button
                        type="button"
                        variant="outline"
                        data-test="dispo-order-force-complete-action"
                        disabled={submitting}
                        onClick={() => {
                            setError(null);
                            setOverrideReason('');
                            setOverrideOpen(true);
                        }}
                    >
                        Trotzdem abschließen
                    </Button>
                ) : null}

                {canComplete && !readiness.ready && !canForceComplete ? (
                    <p
                        className="text-muted-foreground text-xs"
                        data-test="dispo-order-completion-blocked"
                    >
                        Abschluss nicht möglich, solange Prüfungen offen sind.
                    </p>
                ) : null}
            </div>

            <Dialog
                open={overrideOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setOverrideOpen(false);
                        setOverrideReason('');
                        setError(null);
                    }
                }}
            >
                <DialogContent data-test="dispo-order-force-complete-dialog">
                    <DialogHeader>
                        <DialogTitle>Trotzdem abschließen</DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird trotz offener Abschlussprüfungen
                            auf „Abgeschlossen“ gesetzt. Bitte begründen Sie den
                            Override.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={reasonId}>Begründung</Label>
                        <textarea
                            id={reasonId}
                            className={formTextareaClass}
                            value={overrideReason}
                            data-test="dispo-order-force-complete-reason"
                            onChange={(event) =>
                                setOverrideReason(event.target.value)
                            }
                            rows={4}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-force-complete-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => {
                                setOverrideOpen(false);
                                setOverrideReason('');
                                setError(null);
                            }}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-force-complete-confirm"
                            disabled={
                                submitting || overrideReason.trim() === ''
                            }
                            onClick={() =>
                                void complete({
                                    override_reason: overrideReason.trim(),
                                })
                            }
                        >
                            Trotzdem abschließen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
