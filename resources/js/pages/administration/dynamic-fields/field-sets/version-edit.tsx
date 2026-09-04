import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonPost, jsonPut } from '@/lib/json-post';

type AvailableRevision = {
    id: number;
    revision: number;
    label: string;
};

type Membership = {
    id: number;
    field_definition_id: number;
    field_definition_revision_id: number;
    key: string | null;
    label: string | null;
    sort: number;
    required_override: boolean | null;
    visible_override: boolean | null;
    available_revisions: AvailableRevision[];
};

type RuleRow = {
    id: number;
    sort: number;
    condition: Record<string, unknown>;
    action: Record<string, unknown>;
};

type Props = {
    fieldSet: {
        id: number;
        key: string;
        name: string;
        lock_version: number;
    };
    version: {
        id: number;
        version: number;
        status: string;
        editable: boolean;
        fields: Membership[];
        rules: RuleRow[];
    };
};

export default function FieldSetVersionEdit({ fieldSet, version }: Props) {
    const flash = usePage().props.flash;
    const [fields, setFields] = useState(version.fields);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    function syncFormFields(next: Membership[]) {
        setFields(next);
    }

    function membershipPayload() {
        return fields.map((field) => ({
            id: field.id,
            field_definition_revision_id: field.field_definition_revision_id,
            sort: field.sort,
            required_override: field.required_override,
            visible_override: field.visible_override,
        }));
    }

    async function save() {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPut<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}`,
                {
                    lock_version: fieldSet.lock_version,
                    fields: membershipPayload(),
                },
            );
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : caught.message ||
                              'Der Entwurf konnte nicht gespeichert werden.',
                );
            } else {
                setError('Der Entwurf konnte nicht gespeichert werden.');
            }
            setBusy(false);
        }
    }

    async function pinCurrent() {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/aktuelle-revisionen`,
                { lock_version: fieldSet.lock_version },
            );
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : caught.message ||
                              'Die Revisionen konnten nicht übernommen werden.',
                );
            } else {
                setError('Die Revisionen konnten nicht übernommen werden.');
            }
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Feldset-Version ${version.version}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`${fieldSet.name} · Version ${version.version}`}
                    description={`Status: ${version.status}. Regeln sind in DF-3.1 nur lesbar.`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/vorschau`}
                                >
                                    Vorschau
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}`}
                                >
                                    Zurück
                                </Link>
                            </Button>
                        </div>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {error ? <ErrorState message={error} /> : null}

                {version.editable ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            onClick={() => void save()}
                            disabled={busy}
                        >
                            Entwurf speichern
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void pinCurrent()}
                            disabled={busy}
                        >
                            Aktuelle Definition-Revisionen übernehmen
                        </Button>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Aktive und archivierte Versionen sind unveränderlich.
                    </p>
                )}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Felder</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Schlüssel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Revision
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sortierung
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Pflicht
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sichtbar
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((field, index) => (
                                    <tr key={field.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <div className="font-medium">
                                                {field.key}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {field.label}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2">
                                            {version.editable ? (
                                                <select
                                                    className="border-input bg-background rounded-md border px-2 py-1"
                                                    value={
                                                        field.field_definition_revision_id
                                                    }
                                                    onChange={(e) => {
                                                        const next = [
                                                            ...fields,
                                                        ];
                                                        next[index] = {
                                                            ...field,
                                                            field_definition_revision_id:
                                                                Number(
                                                                    e.target
                                                                        .value,
                                                                ),
                                                        };
                                                        syncFormFields(next);
                                                    }}
                                                >
                                                    {field.available_revisions.map(
                                                        (revision) => (
                                                            <option
                                                                key={
                                                                    revision.id
                                                                }
                                                                value={
                                                                    revision.id
                                                                }
                                                            >
                                                                r
                                                                {
                                                                    revision.revision
                                                                }
                                                                :{' '}
                                                                {revision.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            ) : (
                                                `r${field.available_revisions.find((r) => r.id === field.field_definition_revision_id)?.revision ?? '–'}`
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {version.editable ? (
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    className="w-24"
                                                    value={field.sort}
                                                    onChange={(e) => {
                                                        const next = [
                                                            ...fields,
                                                        ];
                                                        next[index] = {
                                                            ...field,
                                                            sort: Number(
                                                                e.target.value,
                                                            ),
                                                        };
                                                        syncFormFields(next);
                                                    }}
                                                />
                                            ) : (
                                                field.sort
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {version.editable ? (
                                                <select
                                                    className="border-input bg-background rounded-md border px-2 py-1"
                                                    value={
                                                        field.required_override ===
                                                        null
                                                            ? ''
                                                            : field.required_override
                                                              ? '1'
                                                              : '0'
                                                    }
                                                    onChange={(e) => {
                                                        const value =
                                                            e.target.value;
                                                        const next = [
                                                            ...fields,
                                                        ];
                                                        next[index] = {
                                                            ...field,
                                                            required_override:
                                                                value === ''
                                                                    ? null
                                                                    : value ===
                                                                      '1',
                                                        };
                                                        syncFormFields(next);
                                                    }}
                                                >
                                                    <option value="">
                                                        Standard
                                                    </option>
                                                    <option value="1">
                                                        Pflicht
                                                    </option>
                                                    <option value="0">
                                                        Optional
                                                    </option>
                                                </select>
                                            ) : field.required_override ===
                                              null ? (
                                                'Standard'
                                            ) : field.required_override ? (
                                                'Pflicht'
                                            ) : (
                                                'Optional'
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {version.editable ? (
                                                <select
                                                    className="border-input bg-background rounded-md border px-2 py-1"
                                                    value={
                                                        field.visible_override ===
                                                        null
                                                            ? ''
                                                            : field.visible_override
                                                              ? '1'
                                                              : '0'
                                                    }
                                                    onChange={(e) => {
                                                        const value =
                                                            e.target.value;
                                                        const next = [
                                                            ...fields,
                                                        ];
                                                        next[index] = {
                                                            ...field,
                                                            visible_override:
                                                                value === ''
                                                                    ? null
                                                                    : value ===
                                                                      '1',
                                                        };
                                                        syncFormFields(next);
                                                    }}
                                                >
                                                    <option value="">
                                                        Standard
                                                    </option>
                                                    <option value="1">
                                                        Sichtbar
                                                    </option>
                                                    <option value="0">
                                                        Unsichtbar
                                                    </option>
                                                </select>
                                            ) : field.visible_override ===
                                              null ? (
                                                'Standard'
                                            ) : field.visible_override ? (
                                                'Sichtbar'
                                            ) : (
                                                'Unsichtbar'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Regeln (nur Lesen)
                    </h2>
                    {version.rules.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Keine Regeln in dieser Version.
                        </p>
                    ) : (
                        <ul className="space-y-2 rounded-xl border p-4 text-sm">
                            {version.rules.map((rule) => (
                                <li key={rule.id}>
                                    #{rule.sort}:{' '}
                                    <code className="text-xs">
                                        {JSON.stringify(rule.condition)} →{' '}
                                        {JSON.stringify(rule.action)}
                                    </code>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
