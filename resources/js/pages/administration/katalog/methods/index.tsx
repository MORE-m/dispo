import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type RegistryPair = {
    engine_profile_key: string;
    pair_status: string;
    current_released_version: string | null;
};

type MethodRow = {
    id: number;
    key: string;
    name: string;
    help_text: string | null;
    sort: number;
    is_active: boolean;
    status_label: string;
    registry_pairs: RegistryPair[];
    registry_summary: string;
    active_category_assignments_count: number;
    active_medium_assignments_count: number;
    lock_version: number;
};

export default function MethodsIndex({
    methods,
    boundaryNote,
}: {
    methods: MethodRow[];
    boundaryNote: string;
}) {
    return (
        <>
            <Head title="Berechnungsmethoden" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Berechnungsmethoden"
                    description="Systemdefinierte Stammdaten für Kalkulationsmethoden (ADV-001c3b1)."
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href="/administration/katalog"
                                data-test="methods-back-hub"
                            >
                                Zum Katalog
                            </Link>
                        </Button>
                    }
                />

                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="methods-boundary-note"
                >
                    {boundaryNote}
                </p>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40 border-b">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Key</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Registry
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Kat.-Zuord.
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Med.-Zuord.
                                </th>
                                <th className="px-3 py-2 font-medium">Sort</th>
                                <th className="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {methods.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b last:border-0"
                                    data-test={`method-row-${row.key}`}
                                >
                                    <td className="px-3 py-2">{row.name}</td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {row.key}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span
                                            data-test={`method-status-${row.id}`}
                                        >
                                            {row.status_label}
                                        </span>
                                    </td>
                                    <td
                                        className="text-muted-foreground px-3 py-2 text-xs"
                                        data-test={`method-registry-${row.id}`}
                                    >
                                        {row.registry_summary}
                                    </td>
                                    <td
                                        className="px-3 py-2"
                                        data-test={`method-cat-count-${row.id}`}
                                    >
                                        {row.active_category_assignments_count}
                                    </td>
                                    <td
                                        className="px-3 py-2"
                                        data-test={`method-med-count-${row.id}`}
                                    >
                                        {row.active_medium_assignments_count}
                                    </td>
                                    <td className="px-3 py-2">{row.sort}</td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/administration/katalog/berechnungsmethoden/${row.id}`}
                                                data-test={`method-open-${row.id}`}
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
            </div>
        </>
    );
}
