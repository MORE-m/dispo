import { Head, Link, usePage } from '@inertiajs/react';
import { DispoOrderStatusBadge } from '@/components/dispo-order-status-badge';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import { money } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { formatDateTime } from '@/lib/date-time';

type OrderRow = {
    id: number;
    number: string;
    customer_name: string | null;
    campaign: string | null;
    product_title: string | null;
    source_calculation_number: string;
    calculation_id: number;
    positions_count: number;
    nn_invest: string;
    status: string;
    status_label: string;
    approval_kind: string;
    approval_kind_label: string;
    requires_special_approval: boolean;
    creator_name: string | null;
    created_at: string | null;
    submitted_at: string | null;
};

export default function DispoOrdersIndex({ orders }: { orders: OrderRow[] }) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Dispoaufträge" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader title="Dispoaufträge" />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {orders.length === 0 ? (
                    <div data-test="dispo-orders-empty">
                        <EmptyState
                            title="Keine Dispoaufträge"
                            description="Aus einer gespeicherten Kalkulation können berechtigte Nutzer einen Entwurf anlegen."
                        />
                    </div>
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="dispo-orders-table"
                    >
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Nummer
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Kunde
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Kampagne / Titel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Quellkalkulation
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        N/N
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Positionen
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Freigabe
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Ersteller
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Eingereicht
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Erstellt
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t"
                                        data-test={`dispo-order-row-${row.id}`}
                                    >
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/dispoauftraege/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.customer_name ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.campaign ??
                                                row.product_title ??
                                                '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/kalkulationen/${row.calculation_id}`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {row.source_calculation_number}
                                            </Link>
                                        </td>
                                        <td
                                            className="px-4 py-2 tabular-nums"
                                            data-test={`dispo-order-nn-${row.id}`}
                                        >
                                            {money(row.nn_invest)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.positions_count}
                                        </td>
                                        <td className="px-4 py-2">
                                            <DispoOrderStatusBadge
                                                status={row.status}
                                                label={row.status_label}
                                            />
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.requires_special_approval ? (
                                                <span
                                                    data-test={`dispo-order-special-${row.id}`}
                                                >
                                                    Sonderfreigabe
                                                </span>
                                            ) : (
                                                row.approval_kind_label
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.creator_name ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatDateTime(row.submitted_at)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatDateTime(row.created_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

DispoOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Dispoaufträge', href: '/dispoauftraege' }],
};
