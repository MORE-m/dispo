import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type RuleRow = {
    id: number;
    inventory_name: string;
    inventory_code: string | null;
    advertising_medium_name: string;
    advertising_medium_code: string | null;
    category_name: string | null;
    is_active: boolean;
    status_label: string;
    booking_code: string | null;
    planning_responsibility_label: string | null;
    hint_text: string | null;
    sort: number;
    is_operative_complete: boolean;
};

type Filters = {
    status: string;
    inventory_id: number | null;
    advertising_medium_id: number | null;
    category_id: number | null;
    planning_responsibility_key: string | null;
    booking_code: string | null;
};

type FilterOptions = {
    inventories: Array<{ id: number; name: string; code: string }>;
    media: Array<{
        id: number;
        name: string;
        code: string;
        category_id: number | null;
    }>;
    categories: Array<{ id: number; name: string; key: string }>;
    booking_codes: string[];
};

type PlanningOption = { key: string; label: string };

function filterHref(filters: Filters, patch: Partial<Filters>): string {
    const next = { ...filters, ...patch };
    const params = new URLSearchParams();
    if (next.status !== 'all') {
        params.set('status', next.status);
    }
    if (next.inventory_id != null) {
        params.set('inventory_id', String(next.inventory_id));
    }
    if (next.advertising_medium_id != null) {
        params.set('advertising_medium_id', String(next.advertising_medium_id));
    }
    if (next.category_id != null) {
        params.set('category_id', String(next.category_id));
    }
    if (next.planning_responsibility_key) {
        params.set(
            'planning_responsibility_key',
            next.planning_responsibility_key,
        );
    }
    if (next.booking_code) {
        params.set('booking_code', next.booking_code);
    }
    const query = params.toString();

    return query === ''
        ? '/administration/kombinationen'
        : `/administration/kombinationen?${query}`;
}

export default function CombinationIndex({
    rules,
    filters,
    filterOptions,
    planningOptions,
}: {
    rules: RuleRow[];
    filters: Filters;
    filterOptions: FilterOptions;
    planningOptions: PlanningOption[];
}) {
    return (
        <>
            <Head title="Kombinationstabelle" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Kombinationstabelle"
                    description="Inventar × Werbemittel mit Buchungskennzeichen und Einplanung. Keine Produktivmatrix-Seeds – Pflege je Zeile."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration">
                                    Administration
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/kombinationen/neu"
                                    data-test="combination-create-link"
                                >
                                    Kombination anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <div
                    className="flex flex-wrap gap-4 text-sm"
                    data-test="combination-filters"
                >
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
                            data-test="combination-filter-status"
                        >
                            <option value="all">Alle</option>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Inaktiv</option>
                        </select>
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
                            data-test="combination-filter-inventory"
                        >
                            <option value="">Alle</option>
                            {filterOptions.inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name} ({inventory.code})
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Oberkategorie
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.category_id ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        category_id: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    }),
                                )
                            }
                            data-test="combination-filter-category"
                        >
                            <option value="">Alle</option>
                            {filterOptions.categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Werbemittel
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.advertising_medium_id ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        advertising_medium_id: event.target
                                            .value
                                            ? Number(event.target.value)
                                            : null,
                                    }),
                                )
                            }
                            data-test="combination-filter-medium"
                        >
                            <option value="">Alle</option>
                            {filterOptions.media.map((medium) => (
                                <option key={medium.id} value={medium.id}>
                                    {medium.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Einplanung
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.planning_responsibility_key ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        planning_responsibility_key: event
                                            .target.value
                                            ? event.target.value
                                            : null,
                                    }),
                                )
                            }
                            data-test="combination-filter-planning"
                        >
                            <option value="">Alle</option>
                            {planningOptions.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        Kennzeichen
                        <select
                            className="rounded-md border px-2 py-1"
                            value={filters.booking_code ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        booking_code: event.target.value
                                            ? event.target.value
                                            : null,
                                    }),
                                )
                            }
                            data-test="combination-filter-booking"
                        >
                            <option value="">Alle</option>
                            {filterOptions.booking_codes.map((code) => (
                                <option key={code} value={code}>
                                    {code}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                {rules.length === 0 ? (
                    <EmptyState
                        title="Keine Kombinationen"
                        description="Legen Sie die erste Inventar-/Werbemittel-Kombination an. Die vollständige Produktivmatrix fehlt weiterhin und wird nicht erfunden."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table
                            className="w-full text-left text-sm"
                            data-test="combination-table"
                        >
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2">Inventar</th>
                                    <th className="px-3 py-2">Werbemittel</th>
                                    <th className="px-3 py-2">Kategorie</th>
                                    <th className="px-3 py-2">Kennzeichen</th>
                                    <th className="px-3 py-2">Einplanung</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((rule) => (
                                    <tr
                                        key={rule.id}
                                        className="border-t"
                                        data-test={`combination-row-${rule.id}`}
                                    >
                                        <td className="px-3 py-2">
                                            {rule.inventory_name}
                                            {rule.inventory_code
                                                ? ` (${rule.inventory_code})`
                                                : ''}
                                        </td>
                                        <td className="px-3 py-2">
                                            {rule.advertising_medium_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {rule.category_name ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 font-mono">
                                            {rule.booking_code ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {rule.planning_responsibility_label ??
                                                '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {rule.status_label}
                                            {!rule.is_operative_complete &&
                                            rule.is_active
                                                ? ' · unvollständig'
                                                : ''}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={`/administration/kombinationen/${rule.id}`}
                                                    data-test={`combination-open-${rule.id}`}
                                                >
                                                    Öffnen
                                                </Link>
                                            </Button>
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
