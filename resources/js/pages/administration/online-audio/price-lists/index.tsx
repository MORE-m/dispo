import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { oaStatusLabel } from '@/lib/oa-tkp-admin';

type PriceListRow = {
    id: number;
    year: number;
    name: string;
    version: string;
    revision_number: number;
    status: string;
    status_label?: string;
    editable?: boolean;
    items_count: number;
    valid_from?: string | null;
    published_at?: string | null;
    archived_at?: string | null;
};

type Filters = {
    status: string;
    year: number | null;
};

function filterHref(filters: Filters, patch: Partial<Filters>): string {
    const next = { ...filters, ...patch };
    const params = new URLSearchParams();
    if (next.status !== 'all') {
        params.set('status', next.status);
    }
    if (next.year !== null && next.year !== undefined) {
        params.set('year', String(next.year));
    }
    const query = params.toString();

    return query === ''
        ? '/administration/online-audio/preislisten'
        : `/administration/online-audio/preislisten?${query}`;
}

export default function OaPriceListIndex({
    priceLists,
    filters,
    years,
    requiredPairsCount,
    currentYear,
}: {
    priceLists: PriceListRow[];
    filters: Filters;
    years: number[];
    requiredPairsCount: number;
    currentYear: number;
}) {
    return (
        <>
            <Head title="OA-TKP-Preislisten" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-price-lists-index"
            >
                <PageHeader
                    title="OA-TKP-Preislisten"
                    description={`Jahresrevisionen mit ${requiredPairsCount} erforderlichen Medium×Inventar-Paaren. Entwurf anlegen, Matrix pflegen, prüfen und aktivieren.`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration/online-audio">
                                    Online Audio
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/online-audio/preislisten/neu"
                                    data-test="oa-price-list-create-link"
                                >
                                    Entwurf anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <div className="flex flex-wrap gap-4 text-sm">
                    <label className="flex items-center gap-2">
                        Status
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.status}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        status: event.target.value,
                                    }),
                                )
                            }
                            data-test="oa-price-list-filter-status"
                        >
                            <option value="all">Alle</option>
                            <option value="draft">Entwurf</option>
                            <option value="active">Aktiv</option>
                            <option value="archived">Archiviert</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Jahr
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.year ?? ''}
                            onChange={(event) => {
                                const raw = event.target.value;
                                router.get(
                                    filterHref(filters, {
                                        year:
                                            raw === ''
                                                ? null
                                                : Number(raw) || null,
                                    }),
                                );
                            }}
                            data-test="oa-price-list-filter-year"
                        >
                            <option value="">Alle ({currentYear})</option>
                            {years.map((year) => (
                                <option key={year} value={year}>
                                    {year}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                {priceLists.length === 0 ? (
                    <EmptyState
                        title="Keine OA-TKP-Preislisten"
                        description="Legen Sie einen Entwurf an oder passen Sie die Filter an."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Jahr
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Revision
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Version
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Positionen
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Gültig ab
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Veröffentlicht
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {priceLists.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-3 py-2">
                                            {row.year}
                                        </td>
                                        <td className="px-3 py-2 font-mono">
                                            {row.revision_number}
                                        </td>
                                        <td className="px-3 py-2 font-mono">
                                            {row.version}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.status_label ??
                                                oaStatusLabel(row.status)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={`/administration/online-audio/preislisten/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {row.name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {row.items_count}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.valid_from ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.published_at
                                                ? new Date(
                                                      row.published_at,
                                                  ).toLocaleString('de-DE')
                                                : '—'}
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
