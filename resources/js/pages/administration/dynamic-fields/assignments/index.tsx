import { Head, Link } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type AssignmentRow = {
    id: number;
    field_set_key: string | null;
    field_set_name: string | null;
    target_layer_label: string;
    target_label: string;
    applies_to_process_label: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    updated_at: string | null;
    target_is_selectable: boolean;
};

function formatUpdatedAt(value: string | null): string {
    if (!value) {
        return '–';
    }

    try {
        return new Intl.DateTimeFormat('de-DE', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

export default function AssignmentIndex({
    assignments,
}: {
    assignments: AssignmentRow[];
}) {
    return (
        <>
            <Head title="Assignments" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Field-Set-Assignments"
                    description="Freie Feldsets global, an Oberkategorien oder Werbemitteln zuordnen. Herkunft und Konflikte vor der Aktivierung prüfen."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration/dynamische-felder">
                                    Dynamische Felder
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/dynamische-felder/assignments/neu"
                                    data-test="assignment-create-link"
                                >
                                    Assignment anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {assignments.length === 0 ? (
                    <EmptyState
                        title="Noch keine Assignments"
                        description="Legen Sie zuerst ein freies, aktiviertes Feldset an und ordnen Sie es anschließend einem Zielkontext zu."
                        action={
                            <Button asChild>
                                <Link href="/administration/dynamische-felder/assignments/neu">
                                    Erstes Assignment anlegen
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="assignment-index-table"
                    >
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Feldset
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Zielebene
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Ziel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Prozess
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sortierung
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Geändert
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/administration/dynamische-felder/assignments/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`assignment-link-${row.id}`}
                                            >
                                                {row.field_set_name ??
                                                    row.field_set_key ??
                                                    `Assignment #${row.id}`}
                                            </Link>
                                            {row.field_set_key ? (
                                                <div className="text-muted-foreground font-mono text-xs">
                                                    {row.field_set_key}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.target_layer_label}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.target_label}
                                            {!row.target_is_selectable ? (
                                                <div className="text-muted-foreground mt-1 text-xs">
                                                    Historisches/deaktiviertes
                                                    Ziel – weiterhin lesbar
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.applies_to_process_label}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.sort}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span
                                                className={
                                                    row.is_active
                                                        ? 'rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                                                        : 'bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-xs font-medium'
                                                }
                                                data-test={`assignment-status-${row.id}`}
                                            >
                                                {row.status_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatUpdatedAt(row.updated_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <SuccessState message="Aktive Assignments wirken über den Snapshot-Freeze auf neue Vorgänge. Historische Snapshots bleiben unverändert." />
            </div>
        </>
    );
}
