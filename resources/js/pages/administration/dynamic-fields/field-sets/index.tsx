import { Head, Link, usePage } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type FieldSetRow = {
    id: number;
    key: string;
    name: string;
    is_system: boolean;
    applies_to: string;
    is_assignable: boolean;
    lock_version: number;
    has_draft: boolean;
    usability_label: string;
    active_version: {
        id: number;
        version: number;
        status: string;
    } | null;
};

function appliesToLabel(value: string): string {
    switch (value) {
        case 'calculation':
            return 'Kalkulation';
        case 'dispo_order':
            return 'Dispoauftrag';
        case 'both':
            return 'Beide';
        default:
            return value;
    }
}

function FieldSetTable({
    rows,
    emptyTitle,
}: {
    rows: FieldSetRow[];
    emptyTitle: string;
}) {
    if (rows.length === 0) {
        return <EmptyState title={emptyTitle} />;
    }

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-left text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        <th className="px-4 py-2 font-medium">Name</th>
                        <th className="px-4 py-2 font-medium">Schlüssel</th>
                        <th className="px-4 py-2 font-medium">Gültigkeit</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">
                            Aktive Version
                        </th>
                        <th className="px-4 py-2 font-medium">Entwurf</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-t">
                            <td className="px-4 py-2">
                                <Link
                                    href={`/administration/dynamische-felder/feldsets/${row.id}`}
                                    className="font-medium underline-offset-4 hover:underline"
                                    data-test={`fieldset-link-${row.key}`}
                                >
                                    {row.name}
                                </Link>
                            </td>
                            <td className="px-4 py-2 font-mono text-xs">
                                {row.key}
                            </td>
                            <td className="px-4 py-2">
                                {appliesToLabel(row.applies_to)}
                            </td>
                            <td className="px-4 py-2">{row.usability_label}</td>
                            <td className="px-4 py-2">
                                {row.active_version
                                    ? `v${row.active_version.version}`
                                    : '–'}
                            </td>
                            <td className="px-4 py-2">
                                {row.has_draft ? 'ja' : '–'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function FieldSetsIndex({
    coreFieldSets,
    freeFieldSets,
}: {
    coreFieldSets: FieldSetRow[];
    freeFieldSets: FieldSetRow[];
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Feldsets" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Feldsets"
                    description="Kern-Feldsets und frei anlegbare Feldsets. Freie Feldsets wirken noch nicht auf Kalkulation oder Dispo."
                    actions={
                        <div className="flex gap-2">
                            <Button asChild data-test="fieldset-create-link">
                                <Link href="/administration/dynamische-felder/feldsets/neu">
                                    Feldset anlegen
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/administration/dynamische-felder">
                                    Zurück
                                </Link>
                            </Button>
                        </div>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}

                <section
                    className="space-y-3"
                    data-test="fieldset-core-section"
                >
                    <h2 className="text-lg font-semibold">Kern-Feldsets</h2>
                    <FieldSetTable
                        rows={coreFieldSets}
                        emptyTitle="Keine Kern-Feldsets"
                    />
                </section>

                <section
                    className="space-y-3"
                    data-test="fieldset-free-section"
                >
                    <h2 className="text-lg font-semibold">Freie Feldsets</h2>
                    <FieldSetTable
                        rows={freeFieldSets}
                        emptyTitle="Noch keine freien Feldsets"
                    />
                </section>
            </div>
        </>
    );
}
