import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type MediaRow = {
    id: number;
    name: string;
    code: string;
    kind: string;
    category_name: string | null;
    category_key: string | null;
    is_active: boolean;
    status_label: string;
    is_discountable: boolean;
    is_ae_eligible: boolean;
    default_length_seconds: number;
    sort: number;
    calculation_positions_count: number;
    assignments_active_count: number;
};

type CategoryOption = {
    id: number;
    key: string;
    name: string;
    is_active: boolean;
    label: string;
};

export default function MediaIndex({
    media,
    filters,
    filterOptions,
}: {
    media: MediaRow[];
    filters: { category_id: number | null; is_active: string | null };
    filterOptions: { categories: CategoryOption[] };
}) {
    function applyFilters(next: { category_id?: string; is_active?: string }) {
        const params: Record<string, string> = {};
        const categoryId =
            next.category_id !== undefined
                ? next.category_id
                : filters.category_id?.toString() || '';
        const isActive =
            next.is_active !== undefined
                ? next.is_active
                : filters.is_active || '';
        if (categoryId) {
            params.category_id = categoryId;
        }
        if (isActive === '0' || isActive === '1') {
            params.is_active = isActive;
        }
        router.get('/administration/katalog/werbemittel', params, {
            preserveState: true,
            replace: true,
        });
    }

    return (
        <>
            <Head title="Werbemittel" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Werbemittel"
                    description="Technischer Code unveränderlich. Spot Classic nur in der Oberkategorie Spots."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration/katalog">
                                    Katalog
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/katalog/werbemittel/neu"
                                    data-test="medium-create-link"
                                >
                                    Werbemittel anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <div
                    className="flex flex-wrap gap-3"
                    data-test="medium-filters"
                >
                    <label className="text-sm">
                        Oberkategorie
                        <select
                            className="border-input bg-background ml-2 rounded-md border px-2 py-1"
                            value={filters.category_id?.toString() ?? ''}
                            onChange={(e) =>
                                applyFilters({ category_id: e.target.value })
                            }
                            data-test="medium-filter-category"
                        >
                            <option value="">Alle</option>
                            {filterOptions.categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Status
                        <select
                            className="border-input bg-background ml-2 rounded-md border px-2 py-1"
                            value={filters.is_active ?? ''}
                            onChange={(e) =>
                                applyFilters({ is_active: e.target.value })
                            }
                            data-test="medium-filter-active"
                        >
                            <option value="">Alle</option>
                            <option value="1">Aktiv</option>
                            <option value="0">Inaktiv</option>
                        </select>
                    </label>
                </div>

                {media.length === 0 ? (
                    <EmptyState
                        title="Keine Werbemittel"
                        description="Legen Sie ein Werbemittel an oder passen Sie die Filter an."
                        action={
                            <Button asChild>
                                <Link href="/administration/katalog/werbemittel/neu">
                                    Anlegen
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="medium-index-table"
                    >
                        <table className="w-full min-w-[860px] text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Code
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Art
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Oberkategorie
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Rabatt/AE
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Verwendung
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {media.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/katalog/werbemittel/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`medium-link-${row.id}`}
                                            >
                                                {row.name}
                                            </Link>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2 font-mono text-xs">
                                            {row.code}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.kind}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.category_name}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span
                                                data-test={`medium-status-${row.id}`}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.is_discountable
                                                ? 'Rabatt'
                                                : '–'}
                                            {' / '}
                                            {row.is_ae_eligible ? 'AE' : '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            Calc{' '}
                                            {row.calculation_positions_count} ·
                                            Assignments{' '}
                                            {row.assignments_active_count}
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
