import { Head, Link, usePage } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type FieldSetRow = {
    id: number;
    key: string;
    name: string;
    lock_version: number;
    active_version: {
        id: number;
        version: number;
        status: string;
    } | null;
};

export default function FieldSetsIndex({
    fieldSets,
}: {
    fieldSets: FieldSetRow[];
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Feldsets" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Feldsets"
                    description="Kern-Feldsets system_calculation_core und system_dispo_order_core."
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
                {fieldSets.length === 0 ? (
                    <EmptyState title="Keine Feldsets" />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Schlüssel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Aktive Version
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {fieldSets.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/dynamische-felder/feldsets/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {row.key}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.active_version
                                                ? `v${row.active_version.version}`
                                                : '–'}
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
