import { SpecialApprovalReasonsList } from '@/components/special-approval-reasons-list';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ApprovalHistoryEntry } from '@/types/dispo-order';

function formatDate(iso: string | null): string {
    if (!iso) {
        return '–';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
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
                            {formatDate(entry.submitted_at)}
                        </p>
                        {entry.decided_by_name ? (
                            <p className="text-muted-foreground mt-1 text-sm">
                                Entschieden von {entry.decided_by_name} am{' '}
                                {formatDate(entry.decided_at)}
                            </p>
                        ) : null}
                        <SpecialApprovalReasonsList
                            reasons={entry.special_approval_reasons}
                        />
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
