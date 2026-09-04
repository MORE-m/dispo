import { Head, Link, usePage } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type DefinitionRow = {
    id: number;
    key: string;
    field_type: string;
    scope: string;
    applies_to: string;
    is_system: boolean;
    label: string | null;
    revision: number | null;
    reportable: boolean | null;
};

export default function DefinitionsIndex({
    definitions,
}: {
    definitions: DefinitionRow[];
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Felddefinitionen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Felddefinitionen"
                    description="Geschützte Systemfelder. Schlüssel und Typ sind unveränderlich."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder">
                                Zurück
                            </Link>
                        </Button>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {definitions.length === 0 ? (
                    <EmptyState title="Keine Definitionen" />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Schlüssel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Anzeigename
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Typ
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Scope
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Revision
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {definitions.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/dynamische-felder/definitionen/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {row.key}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.label ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.field_type}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.scope}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.revision ?? '–'}
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
