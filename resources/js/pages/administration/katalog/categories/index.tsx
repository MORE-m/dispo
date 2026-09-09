import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type CategoryRow = {
    id: number;
    key: string;
    name: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    media_total_count: number;
    media_active_count: number;
    assignments_active_count: number;
    assignments_inactive_count: number;
};

export default function CategoryIndex({
    categories,
}: {
    categories: CategoryRow[];
}) {
    return (
        <>
            <Head title="Oberkategorien" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Oberkategorien"
                    description="Technische Keys sind nach dem Anlegen unveränderlich. Deaktivierung statt Löschung."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration/katalog">
                                    Katalog
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/katalog/oberkategorien/neu"
                                    data-test="category-create-link"
                                >
                                    Oberkategorie anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {categories.length === 0 ? (
                    <EmptyState
                        title="Noch keine Oberkategorien"
                        description="Legen Sie die erste Oberkategorie an."
                        action={
                            <Button asChild>
                                <Link href="/administration/katalog/oberkategorien/neu">
                                    Anlegen
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="category-index-table"
                    >
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Key
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sortierung
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Werbemittel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Assignments
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {categories.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/katalog/oberkategorien/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`category-link-${row.id}`}
                                            >
                                                {row.name}
                                            </Link>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2 font-mono text-xs">
                                            {row.key}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.sort}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span
                                                data-test={`category-status-${row.id}`}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.media_active_count} aktiv /{' '}
                                            {row.media_total_count} gesamt
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.assignments_active_count} aktiv
                                            / {row.assignments_inactive_count}{' '}
                                            inaktiv
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
