import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type Row = {
    id: number;
    created_at: string | null;
    action: string;
    event_type: string | null;
    event_label: string;
    reason: string | null;
    reason_label: string;
    order_id: number | null;
    order_number: string | null;
    order_url: string | null;
    intended_recipient_user_id: number | null;
    intended_recipient_name: string | null;
    intended_recipient_email: string | null;
    notice: string;
};

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

function pageHref(page: number): string {
    return page <= 1
        ? '/administration/benachrichtigungen/unterdrueckt'
        : `/administration/benachrichtigungen/unterdrueckt?page=${page}`;
}

export default function NotificationOutboxSuppressed({
    rows,
    pagination,
    notice,
}: {
    activeTab: string;
    rows: Row[];
    pagination: Pagination;
    notice: string;
}) {
    return (
        <>
            <Head title="Unterdrückte Benachrichtigungen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Unterdrückte Benachrichtigungen"
                    description="Kein Versandversuch. Kein SMTP-Fehler. Kein erneuter Versuch."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration">Administration</Link>
                        </Button>
                    }
                />

                <div
                    className="flex flex-wrap gap-2"
                    data-test="notification-outbox-tabs"
                >
                    <Button variant="outline" asChild>
                        <Link href="/administration/benachrichtigungen">
                            Outbox
                        </Link>
                    </Button>
                    <Button asChild>
                        <Link href="/administration/benachrichtigungen/unterdrueckt">
                            Unterdrückt (kein Versand)
                        </Link>
                    </Button>
                </div>

                <p
                    className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                    data-test="notification-suppress-notice"
                >
                    {notice}
                </p>

                <div
                    className="overflow-x-auto rounded-xl border"
                    data-test="notification-suppress-table"
                >
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-3 py-2 font-medium">Zeit</th>
                                <th className="px-3 py-2 font-medium">
                                    Ereignis
                                </th>
                                <th className="px-3 py-2 font-medium">Grund</th>
                                <th className="px-3 py-2 font-medium">
                                    Auftrag
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Vorgesehener Empfänger
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        className="text-muted-foreground px-3 py-4"
                                        colSpan={5}
                                    >
                                        Keine unterdrückten Benachrichtigungen.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t"
                                        data-test={`notification-suppress-row-${row.id}`}
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {row.created_at ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.event_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.reason_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.order_url ? (
                                                <Link
                                                    href={row.order_url}
                                                    className="underline"
                                                >
                                                    {row.order_number ??
                                                        `ID ${row.order_id}`}
                                                </Link>
                                            ) : (
                                                (row.order_number ?? '—')
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.intended_recipient_name ||
                                            row.intended_recipient_email ? (
                                                <>
                                                    <div>
                                                        {row.intended_recipient_name ??
                                                            '—'}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {row.intended_recipient_email ??
                                                            (row.intended_recipient_user_id !=
                                                            null
                                                                ? `User #${row.intended_recipient_user_id}`
                                                                : '')}
                                                    </div>
                                                </>
                                            ) : row.intended_recipient_user_id !=
                                              null ? (
                                                `User #${row.intended_recipient_user_id}`
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div
                    className="text-muted-foreground flex flex-wrap items-center gap-3 text-sm"
                    data-test="notification-suppress-pagination"
                >
                    <span>
                        {pagination.total === 0
                            ? '0 Einträge'
                            : `${pagination.from}–${pagination.to} von ${pagination.total}`}
                    </span>
                    {pagination.current_page > 1 ? (
                        <Link
                            href={pageHref(pagination.current_page - 1)}
                            className="underline"
                        >
                            Zurück
                        </Link>
                    ) : null}
                    {pagination.current_page < pagination.last_page ? (
                        <Link
                            href={pageHref(pagination.current_page + 1)}
                            className="underline"
                        >
                            Weiter
                        </Link>
                    ) : null}
                </div>
            </div>
        </>
    );
}
