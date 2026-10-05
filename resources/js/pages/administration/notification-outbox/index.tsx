import { Head, Link, router } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type FilterOption = { value: string; label: string };

type Row = {
    id: number;
    created_at: string | null;
    last_attempt_at: string | null;
    event_type: string;
    event_label: string;
    status: string;
    status_label: string;
    attempt_count: number;
    recipient_name: string;
    recipient_email: string;
    order_number: string | null;
    order_id: number | null;
    order_url: string | null;
    error_text: string | null;
    error_truncated: boolean;
};

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Filters = {
    status: string;
    event_type: string;
};

function hrefFor(filters: Filters, page?: number): string {
    const params = new URLSearchParams();
    if (filters.status !== 'failed') {
        params.set('status', filters.status);
    } else {
        params.set('status', 'failed');
    }
    if (filters.event_type !== 'all') {
        params.set('event_type', filters.event_type);
    }
    if (page != null && page > 1) {
        params.set('page', String(page));
    }
    const query = params.toString();

    return query === ''
        ? '/administration/benachrichtigungen'
        : `/administration/benachrichtigungen?${query}`;
}

export default function NotificationOutboxIndex({
    filters,
    statusOptions,
    eventOptions,
    rows,
    pagination,
}: {
    activeTab: string;
    filters: Filters;
    statusOptions: FilterOption[];
    eventOptions: FilterOption[];
    rows: Row[];
    pagination: Pagination;
}) {
    const applyFilter = (patch: Partial<Filters>) => {
        router.get(
            hrefFor({ ...filters, ...patch }, 1),
            {},
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Benachrichtigungen / Outbox" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Benachrichtigungen / Outbox"
                    description="Lesende Admin-Sicht auf E-Mail-Zustellung. Keine Versandaktionen."
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
                    <Button asChild>
                        <Link href="/administration/benachrichtigungen">
                            Outbox
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href="/administration/benachrichtigungen/unterdrueckt">
                            Unterdrückt (kein Versand)
                        </Link>
                    </Button>
                </div>

                <div
                    className="flex flex-wrap gap-3"
                    data-test="notification-outbox-filters"
                >
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">Status</span>
                        <select
                            className="border-input bg-background rounded-md border px-2 py-1.5"
                            value={filters.status}
                            data-test="notification-outbox-status-filter"
                            onChange={(e) =>
                                applyFilter({ status: e.target.value })
                            }
                        >
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">Ereignis</span>
                        <select
                            className="border-input bg-background rounded-md border px-2 py-1.5"
                            value={filters.event_type}
                            data-test="notification-outbox-event-filter"
                            onChange={(e) =>
                                applyFilter({ event_type: e.target.value })
                            }
                        >
                            {eventOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div
                    className="overflow-x-auto rounded-xl border"
                    data-test="notification-outbox-table"
                >
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-3 py-2 font-medium">Zeit</th>
                                <th className="px-3 py-2 font-medium">
                                    Ereignis
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Auftrag
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Empfänger
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Versuche
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Fehler
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Detail
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        className="text-muted-foreground px-3 py-4"
                                        colSpan={8}
                                    >
                                        Keine Outbox-Einträge für diesen Filter.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t"
                                        data-test={`notification-outbox-row-${row.id}`}
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {row.last_attempt_at ??
                                                row.created_at ??
                                                '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.event_label}
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
                                            <div>{row.recipient_name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {row.recipient_email}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.status_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.attempt_count}
                                        </td>
                                        <td className="max-w-xs px-3 py-2">
                                            {row.error_text ? (
                                                <span>
                                                    {row.error_text}
                                                    {row.error_truncated
                                                        ? ' (gekürzt)'
                                                        : ''}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={`/administration/benachrichtigungen/${row.id}`}
                                                className="underline"
                                                data-test={`notification-outbox-detail-link-${row.id}`}
                                            >
                                                Öffnen
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div
                    className="text-muted-foreground flex flex-wrap items-center gap-3 text-sm"
                    data-test="notification-outbox-pagination"
                >
                    <span>
                        {pagination.total === 0
                            ? '0 Einträge'
                            : `${pagination.from}–${pagination.to} von ${pagination.total}`}
                    </span>
                    {pagination.current_page > 1 ? (
                        <Link
                            href={hrefFor(filters, pagination.current_page - 1)}
                            className="underline"
                        >
                            Zurück
                        </Link>
                    ) : null}
                    {pagination.current_page < pagination.last_page ? (
                        <Link
                            href={hrefFor(filters, pagination.current_page + 1)}
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
