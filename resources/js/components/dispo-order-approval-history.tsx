import { SpecialApprovalReasonsList } from '@/components/special-approval-reasons-list';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/date-time';
import { formatFileSize } from '@/lib/format-file-size';
import type { ApprovalHistoryEntry } from '@/types/dispo-order';

function hasUploadEvidence(entry: ApprovalHistoryEntry): boolean {
    return (
        entry.customer_confirmation_mode === 'upload' ||
        entry.customer_confirmation_upload != null
    );
}

function hasExceptionEvidence(entry: ApprovalHistoryEntry): boolean {
    if (hasUploadEvidence(entry)) {
        return false;
    }

    return Boolean(
        entry.customer_confirmation_mode === 'exception' ||
        entry.customer_confirmation_without_upload,
    );
}

export function DispoOrderApprovalHistory({
    entries,
}: {
    entries: ApprovalHistoryEntry[];
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-approval-history"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Freigabehistorie
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {entries.map((entry) => (
                    <article
                        key={entry.id}
                        className="rounded-lg border p-4"
                        data-test={`approval-history-entry-${entry.id}`}
                    >
                        <p className="font-medium">
                            {entry.status_label} · {entry.kind_label}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Eingereicht von {entry.submitted_by_name} am{' '}
                            <span data-test="approval-submitted-at">
                                {formatDateTime(entry.submitted_at)}
                            </span>
                        </p>
                        {entry.decided_by_name ? (
                            <p className="text-muted-foreground mt-1 text-sm">
                                Entschieden von {entry.decided_by_name} am{' '}
                                <span data-test="approval-decided-at">
                                    {formatDateTime(entry.decided_at)}
                                </span>
                            </p>
                        ) : null}
                        <SpecialApprovalReasonsList
                            reasons={entry.special_approval_reasons}
                        />
                        {hasUploadEvidence(entry) &&
                        entry.customer_confirmation_upload ? (
                            <div
                                className="mt-2 space-y-1 text-sm"
                                data-test="approval-history-customer-confirmation-upload"
                            >
                                <p>Kundenbestätigung: Upload</p>
                                <p data-test="approval-history-upload-filename">
                                    {
                                        entry.customer_confirmation_upload
                                            .original_filename
                                    }
                                </p>
                                <p className="text-muted-foreground">
                                    {formatFileSize(
                                        entry.customer_confirmation_upload
                                            .size_bytes,
                                    )}
                                    {entry.customer_confirmation_upload
                                        .uploaded_by_name
                                        ? ` · ${entry.customer_confirmation_upload.uploaded_by_name}`
                                        : ''}
                                    {entry.customer_confirmation_upload
                                        .uploaded_at
                                        ? ` · ${formatDateTime(entry.customer_confirmation_upload.uploaded_at)}`
                                        : ''}
                                </p>
                                {entry.customer_confirmation_upload
                                    .download_url ? (
                                    <a
                                        href={
                                            entry.customer_confirmation_upload
                                                .download_url
                                        }
                                        className="text-primary underline-offset-4 hover:underline"
                                        data-test="approval-history-upload-download"
                                    >
                                        Herunterladen
                                    </a>
                                ) : null}
                            </div>
                        ) : null}
                        {hasExceptionEvidence(entry) ? (
                            <div
                                className="mt-2 space-y-1 text-sm"
                                data-test="approval-history-customer-confirmation"
                            >
                                <p>Kundenbestätigung: Ausnahme ohne Upload</p>
                                {entry.customer_confirmation_exception_reason ? (
                                    <p data-test="approval-history-exception-reason">
                                        Ausnahmegrund:{' '}
                                        {
                                            entry.customer_confirmation_exception_reason
                                        }
                                    </p>
                                ) : null}
                                {entry.status === 'approved' &&
                                entry.customer_confirmation_exception_acknowledged &&
                                entry.customer_confirmation_exception_acknowledged_by_name ? (
                                    <p data-test="approval-history-exception-ack">
                                        Ausnahme mitfreigegeben von{' '}
                                        {
                                            entry.customer_confirmation_exception_acknowledged_by_name
                                        }{' '}
                                        am{' '}
                                        {formatDateTime(
                                            entry.customer_confirmation_exception_acknowledged_at,
                                        )}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        {entry.decision_note ? (
                            <p
                                className="mt-2 text-sm"
                                data-test="approval-decision-note"
                            >
                                Notiz: {entry.decision_note}
                            </p>
                        ) : null}
                        {entry.rejection_reason ? (
                            <p
                                className="mt-2 text-sm"
                                data-test="approval-rejection-reason"
                            >
                                Begründung: {entry.rejection_reason}
                            </p>
                        ) : null}
                    </article>
                ))}
            </CardContent>
        </Card>
    );
}
