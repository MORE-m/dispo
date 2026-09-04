import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import { SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Revision = {
    id: number;
    revision: number;
    label: string;
    help_text: string | null;
    group_key: string | null;
    sort_default: number;
    reportable: boolean;
    created_at?: string | null;
};

type Definition = {
    id: number;
    key: string;
    field_type: string;
    scope: string;
    applies_to: string;
    is_system: boolean;
    is_key_protected: boolean;
    current_revision: Revision | null;
    revisions: Revision[];
};

export default function DefinitionShow({
    definition,
}: {
    definition: Definition;
}) {
    const flash = usePage().props.flash;
    const current = definition.current_revision;
    const form = useForm({
        label: current?.label ?? '',
        help_text: current?.help_text ?? '',
        group_key: current?.group_key ?? '',
        sort_default: current?.sort_default ?? 0,
        reportable: current?.reportable ?? true,
    });

    return (
        <>
            <Head title={`Definition ${definition.key}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={definition.key}
                    description={`Typ ${definition.field_type} · Scope ${definition.scope} · ${definition.applies_to}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder/definitionen">
                                Zurück zur Liste
                            </Link>
                        </Button>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}

                <section className="max-w-xl space-y-4 rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Neue Revision</h2>
                    <p className="text-muted-foreground text-sm">
                        Schlüssel und Feldtyp bleiben unveränderlich. Neue
                        Revisionen gelten erst nach Pin in einem Entwurf und
                        dessen Aktivierung für neue Vorgänge.
                    </p>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                `/administration/dynamische-felder/definitionen/${definition.id}/revisionen`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <FormField
                            label="Anzeigename"
                            htmlFor="label"
                            error={form.errors.label}
                        >
                            <Input
                                id="label"
                                value={form.data.label}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            label="Hilfetext"
                            htmlFor="help_text"
                            error={form.errors.help_text}
                        >
                            <textarea
                                id="help_text"
                                rows={3}
                                value={form.data.help_text}
                                onChange={(e) =>
                                    form.setData('help_text', e.target.value)
                                }
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            />
                        </FormField>
                        <FormField
                            label="Gruppe"
                            htmlFor="group_key"
                            error={form.errors.group_key}
                        >
                            <Input
                                id="group_key"
                                value={form.data.group_key}
                                onChange={(e) =>
                                    form.setData('group_key', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Standardsortierung"
                            htmlFor="sort_default"
                            error={form.errors.sort_default}
                        >
                            <Input
                                id="sort_default"
                                type="number"
                                min={0}
                                value={form.data.sort_default}
                                onChange={(e) =>
                                    form.setData(
                                        'sort_default',
                                        Number(e.target.value),
                                    )
                                }
                                required
                            />
                        </FormField>
                        <div className="flex items-center gap-2">
                            <input
                                id="reportable"
                                type="checkbox"
                                checked={form.data.reportable}
                                onChange={(e) =>
                                    form.setData('reportable', e.target.checked)
                                }
                                className="size-4 rounded border"
                            />
                            <Label htmlFor="reportable">Reportfähig</Label>
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Revision anlegen
                        </Button>
                    </form>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Historie</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Rev
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Anzeigename
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Hilfe
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Reportfähig
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {definition.revisions.map((revision) => (
                                    <tr key={revision.id} className="border-t">
                                        <td className="px-4 py-2">
                                            {revision.revision}
                                            {current?.id === revision.id
                                                ? ' (aktuell)'
                                                : ''}
                                        </td>
                                        <td className="px-4 py-2">
                                            {revision.label}
                                        </td>
                                        <td className="px-4 py-2">
                                            {revision.help_text ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {revision.reportable
                                                ? 'ja'
                                                : 'nein'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}
