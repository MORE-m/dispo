import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/date-time';

export type StatusHistoryEntry = {
    id: number;
    from_status: string;
    from_status_label: string;
    to_status: string;
    to_status_label: string;
    changed_by_name: string;
    changed_at: string;
    reason: string | null;
    is_reopen: boolean;
    is_completion_override?: boolean;
    completion_override_violations?: {
        key?: string;
        label?: string;
        violations?: { message: string }[];
    }[];
    lock_version_after: number;
};

export function DispoOrderStatusHistory({
    entries,
}: {
    entries: StatusHistoryEntry[];
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-status-history"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Statushistorie
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 px-5 py-4">
                {entries.map((entry) => (
                    <article
                        key={entry.id}
                        className="rounded-lg border p-3 text-sm"
                        data-test={`status-history-entry-${entry.id}`}
                    >
                        <p className="font-medium">
                            {entry.from_status_label} → {entry.to_status_label}
                            {entry.is_reopen ? ' (Wiederöffnung)' : ''}
                            {entry.is_completion_override
                                ? ' (Admin-Override)'
                                : ''}
                        </p>
                        <p className="text-muted-foreground mt-1">
                            {entry.changed_by_name} ·{' '}
                            <span data-test="status-changed-at">
                                {formatDateTime(entry.changed_at)}
                            </span>
                        </p>
                        {entry.reason ? (
                            <p
                                className="mt-2"
                                data-test="status-history-reason"
                            >
                                Begründung: {entry.reason}
                            </p>
                        ) : null}
                    </article>
                ))}
            </CardContent>
        </Card>
    );
}
