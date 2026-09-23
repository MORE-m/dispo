import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/date-time';

export type CommunicationEntry = {
    id: number;
    type: string;
    type_label: string;
    body: string;
    created_by_name: string;
    created_at: string | null;
    parent_id: number | null;
};

export function DispoOrderCommunicationHistory({
    entries,
}: {
    entries: CommunicationEntry[];
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-communication-history"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Kommunikation
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 px-5 py-4">
                {entries.map((entry) => {
                    const isResponse = entry.type === 'sales_inquiry_response';

                    return (
                        <article
                            key={entry.id}
                            className={`rounded-lg border p-3 text-sm ${
                                isResponse ? 'ml-4 border-l-4' : ''
                            }`}
                            data-test={`communication-entry-${entry.id}`}
                            data-type={entry.type}
                            data-parent-id={entry.parent_id ?? undefined}
                        >
                            <p className="font-medium">{entry.type_label}</p>
                            <p className="text-muted-foreground mt-1">
                                {entry.created_by_name}
                                {entry.created_at ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <span data-test="communication-created-at">
                                            {formatDateTime(entry.created_at)}
                                        </span>
                                    </>
                                ) : null}
                            </p>
                            <p
                                className="mt-2 max-w-full break-words whitespace-pre-wrap"
                                data-test="communication-body"
                            >
                                {entry.body}
                            </p>
                        </article>
                    );
                })}
            </CardContent>
        </Card>
    );
}
