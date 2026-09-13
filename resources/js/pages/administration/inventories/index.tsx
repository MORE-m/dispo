import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type InventoryRow = {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    has_logo: boolean;
    calculation_positions_count: number;
    dispo_order_positions_count: number;
    price_lists_count: number;
    inventory_medium_rules_count: number;
};

type Filters = {
    status: string;
    type: string;
};

function filterHref(filters: Filters, patch: Partial<Filters>): string {
    const next = { ...filters, ...patch };
    const params = new URLSearchParams();
    if (next.status !== 'all') {
        params.set('status', next.status);
    }
    if (next.type !== 'all') {
        params.set('type', next.type);
    }
    const query = params.toString();

    return query === ''
        ? '/administration/inventare'
        : `/administration/inventare?${query}`;
}

export default function InventoryIndex({
    inventories,
    filters,
}: {
    inventories: InventoryRow[];
    filters: Filters;
}) {
    return (
        <>
            <Head title="Inventare" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Inventare"
                    description="Kurzcode und Typ bleiben nach dem Anlegen unveränderlich. Deaktivierung statt Löschung."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration">
                                    Administration
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/inventare/neu"
                                    data-test="inventory-create-link"
                                >
                                    Inventar anlegen
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
                            data-test="inventory-filter-status"
                        >
                            <option value="all">Alle</option>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Inaktiv</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Typ
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.type}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        type: event.target.value,
                                    }),
                                )
                            }
                            data-test="inventory-filter-type"
                        >
                            <option value="all">Alle</option>
                            <option value="sender">Sender</option>
                            <option value="kombi">Kombi</option>
                        </select>
                    </label>
                </div>

                {inventories.length === 0 ? (
                    <EmptyState
                        title="Noch keine Inventare"
                        description="Legen Sie das erste Inventar an."
                        action={
                            <Button asChild>
                                <Link href="/administration/inventare/neu">
                                    Anlegen
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="inventory-index-table"
                    >
                        <table className="w-full min-w-[880px] text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Code
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Typ
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sortierung
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Logo
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Bezüge
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {inventories.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t"
                                        data-test={`inventory-row-${row.id}`}
                                    >
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/inventare/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`inventory-link-${row.id}`}
                                            >
                                                {row.name}
                                            </Link>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2 font-mono text-xs">
                                            {row.code}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.type_label}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span
                                                data-test={`inventory-status-${row.id}`}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.sort}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.has_logo
                                                ? 'Vorhanden'
                                                : 'Kein Logo'}
                                        </td>
                                        <td className="px-4 py-2">
                                            Calc{' '}
                                            {row.calculation_positions_count} ·
                                            Dispo{' '}
                                            {row.dispo_order_positions_count} ·
                                            Preise {row.price_lists_count} ·
                                            Regeln{' '}
                                            {row.inventory_medium_rules_count}
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
