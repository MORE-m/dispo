import { Head, Link, usePage } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type DefinitionRow = {
    id: number;
    key: string;
    field_type: string;
    scope: string;
    applies_to: string;
    is_system: boolean;
    is_active: boolean;
    label: string | null;
    revision: number | null;
    reportable: boolean | null;
    type: 'system' | 'custom';
};

function DefinitionsTable({
    rows,
    emptyTitle,
}: {
    rows: DefinitionRow[];
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
                        <th className="px-4 py-2 font-medium">Schlüssel</th>
                        <th className="px-4 py-2 font-medium">Anzeigename</th>
                        <th className="px-4 py-2 font-medium">Typ</th>
                        <th className="px-4 py-2 font-medium">Scope</th>
                        <th className="px-4 py-2 font-medium">Gilt für</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Revision</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-t">
                            <td className="px-4 py-2">
                                <Link
                                    href={`/administration/dynamische-felder/definitionen/${row.id}`}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {row.key}
                                </Link>
                            </td>
                            <td className="px-4 py-2">{row.label ?? '–'}</td>
                            <td className="px-4 py-2">{row.field_type}</td>
                            <td className="px-4 py-2">{row.scope}</td>
                            <td className="px-4 py-2">{row.applies_to}</td>
                            <td className="px-4 py-2">
                                {row.is_active ? (
                                    <Badge variant="secondary">aktiv</Badge>
                                ) : (
                                    <Badge variant="outline">inaktiv</Badge>
                                )}
                            </td>
                            <td className="px-4 py-2">{row.revision ?? '–'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function DefinitionsIndex({
    systemDefinitions,
    customDefinitions,
    filter,
}: {
    definitions: DefinitionRow[];
    systemDefinitions: DefinitionRow[];
    customDefinitions: DefinitionRow[];
    filter: { type: string };
}) {
    const flash = usePage().props.flash;
    const activeFilter =
        filter.type === 'custom' || filter.type === 'system'
            ? filter.type
            : 'all';

    return (
        <>
            <Head title="Felddefinitionen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Felddefinitionen"
                    description="Systemfelder revisionieren und eigene Header-Textfelder verwalten."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild>
                                <Link href="/administration/dynamische-felder/definitionen/neu">
                                    Eigenes Feld anlegen
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

                <div className="flex flex-wrap gap-2">
                    <Button
                        variant={activeFilter === 'all' ? 'default' : 'outline'}
                        size="sm"
                        asChild
                    >
                        <Link href="/administration/dynamische-felder/definitionen">
                            Alle
                        </Link>
                    </Button>
                    <Button
                        variant={
                            activeFilter === 'system' ? 'default' : 'outline'
                        }
                        size="sm"
                        asChild
                    >
                        <Link href="/administration/dynamische-felder/definitionen?type=system">
                            System
                        </Link>
                    </Button>
                    <Button
                        variant={
                            activeFilter === 'custom' ? 'default' : 'outline'
                        }
                        size="sm"
                        asChild
                    >
                        <Link href="/administration/dynamische-felder/definitionen?type=custom">
                            Eigene Felder
                        </Link>
                    </Button>
                </div>

                {activeFilter === 'all' || activeFilter === 'system' ? (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            Systemfelder
                        </h2>
                        <DefinitionsTable
                            rows={systemDefinitions}
                            emptyTitle="Keine Systemfelder"
                        />
                    </section>
                ) : null}

                {activeFilter === 'all' || activeFilter === 'custom' ? (
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold">
                                Eigene Felder
                            </h2>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/administration/dynamische-felder/definitionen/neu">
                                    Neu anlegen
                                </Link>
                            </Button>
                        </div>
                        <DefinitionsTable
                            rows={customDefinitions}
                            emptyTitle="Keine eigenen Felder"
                        />
                    </section>
                ) : null}
            </div>
        </>
    );
}
