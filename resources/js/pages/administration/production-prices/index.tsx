import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type InventoryOption = {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
};

type ProductionPriceListRow = {
    id: number;
    name: string;
    production_type_label: string;
    year: number;
    version: string;
    status: string;
    status_label: string;
    unit_price: string;
    is_discountable: boolean;
    is_ae_eligible: boolean;
    inventory_name: string | null;
    inventory_type_label: string | null;
};

type Filters = {
    status: string;
    year: number | null;
    inventory_id: number | null;
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
    if (next.inventory_id) {
        params.set('inventory_id', String(next.inventory_id));
    }
    const query = params.toString();

    return query === ''
        ? '/administration/produktionspreise'
        : `/administration/produktionspreise?${query}`;
}

export default function ProductionPriceListIndex({
    productionPriceLists,
    inventories,
    filters,
    currentYear,
}: {
    productionPriceLists: ProductionPriceListRow[];
    inventories: InventoryOption[];
    filters: Filters;
    currentYear: number;
}) {
    return (
        <>
            <Head title="Produktionspreise" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Produktionspreise"
                    description="Inventarspezifische Spotproduktionspreise. Höchstens eine aktive Version je Inventar und Kalenderjahr."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration">
                                    Administration
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/produktionspreise/neu"
                                    data-test="production-price-create-link"
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
                            data-test="production-price-filter-status"
                        >
                            <option value="all">Alle</option>
                            <option value="draft">Entwurf</option>
                            <option value="active">Aktiv</option>
                            <option value="archived">Archiviert</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Jahr
                        <input
                            type="number"
                            className="w-24 rounded-md border px-2 py-1"
                            defaultValue={filters.year ?? ''}
                            placeholder={String(currentYear)}
                            data-test="production-price-filter-year"
                            onBlur={(event) => {
                                const raw = event.target.value.trim();
                                router.get(
                                    filterHref(filters, {
                                        year:
                                            raw === ''
                                                ? null
                                                : Number(raw) || null,
                                    }),
                                );
                            }}
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        Inventar
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.inventory_id ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        inventory_id: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    }),
                                )
                            }
                            data-test="production-price-filter-inventory"
                        >
                            <option value="">Alle</option>
                            {inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name} ({inventory.type_label})
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                {productionPriceLists.length === 0 ? (
                    <EmptyState
                        title="Keine Produktionspreise"
                        description="Legen Sie einen Entwurf an oder kopieren Sie eine vorhandene Version."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table
                            className="w-full text-left text-sm"
                            data-test="production-price-index-table"
                        >
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Inventar
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Art
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Jahr
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Version
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Preis (€)
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Rabatt
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        AE
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {productionPriceLists.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-3 py-2">
                                            <Link
                                                href={`/administration/produktionspreise/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`production-price-row-${row.id}`}
                                            >
                                                {row.inventory_name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.production_type_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.year}
                                        </td>
                                        <td className="px-3 py-2 font-mono">
                                            {row.version}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.status_label}
                                        </td>
                                        <td className="px-3 py-2 text-right font-mono">
                                            {row.unit_price}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.is_discountable
                                                ? 'Ja'
                                                : 'Nein'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.is_ae_eligible ? 'Ja' : 'Nein'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.name}
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
