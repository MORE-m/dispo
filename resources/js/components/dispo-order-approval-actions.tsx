import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
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
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formatDateTime } from '@/lib/date-time';
import { formatFileSize } from '@/lib/format-file-size';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';
import type { CustomerConfirmationUploadSnapshot } from '@/types/dispo-order';

type ActionResponse = {
    message: string;
    redirect: string;
};

export function DispoOrderApprovalActions({
    orderId,
    lockVersion,
    canSubmit,
    canApprove,
    canReject,
    isCreator,
    status,
    requiresExceptionAcknowledgement = false,
    customerConfirmationMode = null,
    customerConfirmationUpload = null,
}: {
    orderId: number;
    lockVersion: number;
    canSubmit: boolean;
    canApprove: boolean;
    canReject: boolean;
    isCreator: boolean;
    status: string;
    requiresExceptionAcknowledgement?: boolean;
    customerConfirmationMode?: 'upload' | 'exception' | null;
    customerConfirmationUpload?: CustomerConfirmationUploadSnapshot | null;
}) {
    const [submitOpen, setSubmitOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [note, setNote] = useState('');
    const [reason, setReason] = useState('');
    const [exceptionAck, setExceptionAck] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reasonId = useId();
    const noteId = useId();
    const ackId = useId();

    const showCreatorHint =
        isCreator && status === 'awaiting_sales_approval' && !canApprove;

    const showUploadEvidence =
        customerConfirmationMode === 'upload' ||
        customerConfirmationUpload !== null;

    const showExceptionAck =
        requiresExceptionAcknowledgement && !showUploadEvidence;

    async function runAction(
        path: string,
        body: Record<string, unknown>,
        onSuccessClose: () => void,
    ) {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(path, {
                lock_version: lockVersion,
                ...body,
            });
            onSuccessClose();
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
        <div
            className="flex flex-col gap-3"
            data-test="dispo-order-approval-actions"
        >
            {error && !submitOpen && !approveOpen && !rejectOpen ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-approval-error"
                />
            ) : null}

            {canSubmit ? (
                <Button
                    type="button"
                    data-test="dispo-order-submit-open"
                    onClick={() => {
                        setError(null);
                        setSubmitOpen(true);
                    }}
                >
                    Zur Freigabe einreichen
                </Button>
            ) : null}

            {showCreatorHint ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test="four-eyes-creator-hint"
                >
                    Sie haben diesen Auftrag erstellt. Nach dem
                    Vier-Augen-Prinzip muss eine zweite Person genehmigen oder
                    ablehnen.
                </p>
            ) : null}

            {canApprove || canReject ? (
                <div className="flex flex-wrap gap-2">
                    {canApprove ? (
                        <Button
                            type="button"
                            data-test="dispo-order-approve-open"
                            onClick={() => {
                                setError(null);
                                setExceptionAck(false);
                                setApproveOpen(true);
                            }}
                        >
                            Genehmigen
                        </Button>
                    ) : null}
                    {canReject ? (
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="dispo-order-reject-open"
                            onClick={() => {
                                setError(null);
                                setRejectOpen(true);
                            }}
                        >
                            Ablehnen
                        </Button>
                    ) : null}
                </div>
            ) : null}

            <Dialog open={submitOpen} onOpenChange={setSubmitOpen}>
                <DialogContent data-test="dispo-order-submit-dialog">
                    <DialogHeader>
                        <DialogTitle>Zur Freigabe einreichen</DialogTitle>
                        <DialogDescription>
                            Der Dispoauftrag wird zur Vertriebsfreigabe
                            eingereicht. Eine zweite Person muss danach
                            genehmigen oder ablehnen. Ein direkter Übergang zur
                            Disposition ist nicht möglich.
                        </DialogDescription>
                    </DialogHeader>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-submit-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setSubmitOpen(false)}
                            disabled={submitting}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-submit-confirm"
                            disabled={submitting}
                            onClick={() =>
                                void runAction(
                                    `/dispoauftraege/${orderId}/einreichen`,
                                    {},
                                    () => setSubmitOpen(false),
                                )
                            }
                        >
                            {submitting ? 'Wird eingereicht…' : 'Einreichen'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={approveOpen} onOpenChange={setApproveOpen}>
                <DialogContent data-test="dispo-order-approve-dialog">
                    <DialogHeader>
                        <DialogTitle>Dispoauftrag genehmigen</DialogTitle>
                        <DialogDescription>
                            Nach der Genehmigung liegt der Auftrag bei der
                            Disposition. Eine optionale Notiz wird in der
                            Freigabehistorie gespeichert.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={noteId}>Notiz (optional)</Label>
                        <textarea
                            id={noteId}
                            className={formTextareaClass}
                            value={note}
                            maxLength={500}
                            data-test="dispo-order-approve-note"
                            onChange={(event) => setNote(event.target.value)}
                            disabled={submitting}
                        />
                    </div>
                    {showUploadEvidence && customerConfirmationUpload ? (
                        <div
                            className="space-y-1 text-sm"
                            data-test="approval-customer-confirmation-upload"
                        >
                            <p className="font-medium">
                                Kundenbestätigung (Upload)
                            </p>
                            <p>
                                {customerConfirmationUpload.original_filename}
                            </p>
                            <p className="text-muted-foreground">
                                {formatFileSize(
                                    customerConfirmationUpload.size_bytes,
                                )}
                                {customerConfirmationUpload.uploaded_by_name
                                    ? ` · ${customerConfirmationUpload.uploaded_by_name}`
                                    : ''}
                                {customerConfirmationUpload.uploaded_at
                                    ? ` · ${formatDateTime(customerConfirmationUpload.uploaded_at)}`
                                    : ''}
                            </p>
                            {customerConfirmationUpload.download_url ? (
                                <a
                                    href={
                                        customerConfirmationUpload.download_url
                                    }
                                    className="text-primary underline-offset-4 hover:underline"
                                    data-test="approval-customer-confirmation-download"
                                >
                                    Herunterladen
                                </a>
                            ) : null}
                        </div>
                    ) : null}
                    {showExceptionAck ? (
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id={ackId}
                                checked={exceptionAck}
                                disabled={submitting}
                                data-test="customer-confirmation-exception-ack"
                                onCheckedChange={(value) =>
                                    setExceptionAck(value === true)
                                }
                            />
                            <Label
                                htmlFor={ackId}
                                className="text-sm leading-snug font-normal"
                            >
                                Ausnahme ohne Kundenbestätigungs-Upload
                                ausdrücklich mitfreigeben
                            </Label>
                        </div>
                    ) : null}
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-approve-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setApproveOpen(false)}
                            disabled={submitting}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-approve-confirm"
                            disabled={
                                submitting ||
                                (showExceptionAck && !exceptionAck)
                            }
                            onClick={() =>
                                void runAction(
                                    `/dispoauftraege/${orderId}/genehmigen`,
                                    {
                                        note,
                                        ...(showExceptionAck
                                            ? {
                                                  customer_confirmation_exception_acknowledged:
                                                      exceptionAck,
                                              }
                                            : {}),
                                    },
                                    () => setApproveOpen(false),
                                )
                            }
                        >
                            {submitting ? 'Wird genehmigt…' : 'Genehmigen'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                <DialogContent data-test="dispo-order-reject-dialog">
                    <DialogHeader>
                        <DialogTitle>Dispoauftrag ablehnen</DialogTitle>
                        <DialogDescription>
                            Eine Ablehnung betrifft diesen Snapshot endgültig.
                            Der Auftrag bleibt unveränderbar im Status „Freigabe
                            abgelehnt“. Eine Korrektur erfolgt über eine neue
                            Nachbesserung der Kalkulation.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={reasonId}>Begründung</Label>
                        <textarea
                            id={reasonId}
                            className={formTextareaClass}
                            value={reason}
                            required
                            maxLength={2000}
                            data-test="dispo-order-reject-reason"
                            aria-invalid={
                                error !== null && reason.trim() === ''
                                    ? true
                                    : undefined
                            }
                            onChange={(event) => setReason(event.target.value)}
                            disabled={submitting}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-reject-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setRejectOpen(false)}
                            disabled={submitting}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="dispo-order-reject-confirm"
                            disabled={submitting}
                            onClick={() =>
                                void runAction(
                                    `/dispoauftraege/${orderId}/ablehnen`,
                                    { reason },
                                    () => setRejectOpen(false),
                                )
                            }
                        >
                            {submitting ? 'Wird abgelehnt…' : 'Ablehnen'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
