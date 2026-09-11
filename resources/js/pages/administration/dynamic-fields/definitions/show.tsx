import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import FieldDefinitionOptionsEditor from '@/components/administration/field-definition-options-editor';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { JsonPostError, jsonDelete, jsonPost, jsonPut } from '@/lib/json-post';
import type { OptionRow } from '@/lib/field-definition-options-draft';

type Revision = {
    id: number;
    revision: number;
    label: string;
    help_text: string | null;
    group_key: string | null;
    sort_default: number;
    reportable: boolean;
    validation_json?: { max_length?: number } | null;
    created_at?: string | null;
};

type Membership = {
    id: number;
    field_set_id: number | null;
    field_set_key: string | null;
    field_set_name: string | null;
    version_id: number | null;
    version: number | null;
    status: string | null;
    sort: number;
    required_override: boolean | null;
    visible_override: boolean | null;
    field_definition_revision_id?: number | null;
};

type Definition = {
    id: number;
    key: string;
    field_type: string;
    scope: string;
    applies_to: string;
    is_system: boolean;
    is_key_protected: boolean;
    is_active: boolean;
    lock_version: number;
    is_used: boolean;
    max_length: number | null;
    current_revision: Revision | null;
    revisions: Revision[];
    memberships: Membership[];
    options: OptionRow[];
    options_fingerprint: string | null;
    can_manage_options: boolean;
};

function isChoiceType(fieldType: string): boolean {
    return fieldType === 'select' || fieldType === 'multi_select';
}

function maxLengthFromRevision(revision: Revision | null): string {
    const value = revision?.validation_json?.max_length;
    return value === undefined || value === null ? '' : String(value);
}

export default function DefinitionShow({
    definition: initialDefinition,
    routes,
    optionsBoundaryNote,
}: {
    definition: Definition;
    routes: {
        optionsPreview: string;
        optionsReplace: string;
    } | null;
    optionsBoundaryNote: string;
}) {
    const flash = usePage().props.flash;
    const [definition, setDefinition] = useState(initialDefinition);
    const current = definition.current_revision;
    const isCustom = !definition.is_system;
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const [structural, setStructural] = useState({
        label: current?.label ?? '',
        field_type: definition.field_type,
        applies_to: definition.applies_to,
        help_text: current?.help_text ?? '',
        group_key: current?.group_key ?? '',
        sort_default: current?.sort_default ?? 0,
        reportable: current?.reportable ?? false,
        max_length:
            definition.max_length === null ? '' : String(definition.max_length),
    });

    const [revision, setRevision] = useState({
        label: current?.label ?? '',
        help_text: current?.help_text ?? '',
        group_key: current?.group_key ?? '',
        sort_default: current?.sort_default ?? 0,
        reportable: current?.reportable ?? true,
        max_length: maxLengthFromRevision(current),
    });

    function handleCaught(caught: unknown, fallback: string) {
        if (caught instanceof JsonPostError) {
            if (caught.isConflict) {
                setError(
                    caught.message ||
                        'Die Daten wurden zwischenzeitlich geändert. Bitte die Seite neu laden.',
                );
            } else {
                setError(caught.message || fallback);
            }
            const mapped: Record<string, string> = {};
            for (const [key, messages] of Object.entries(caught.fieldErrors)) {
                if (messages[0]) {
                    mapped[key] = messages[0];
                }
            }
            setFieldErrors(mapped);
        } else {
            setError(fallback);
        }
        setBusy(false);
    }

    async function saveStructural(event: FormEvent) {
        event.preventDefault();
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setFieldErrors({});

        try {
            const payload: Record<string, unknown> = {
                lock_version: definition.lock_version,
                label: structural.label,
                field_type: structural.field_type,
                applies_to: structural.applies_to,
                help_text:
                    structural.help_text.trim() === ''
                        ? null
                        : structural.help_text,
                group_key:
                    structural.group_key.trim() === ''
                        ? null
                        : structural.group_key,
                sort_default: structural.sort_default,
                reportable: structural.reportable,
            };
            if (!isChoiceType(structural.field_type)) {
                payload.max_length =
                    structural.max_length === ''
                        ? null
                        : Number(structural.max_length);
            }
            await jsonPut<{ redirect: string }>(
                `/administration/dynamische-felder/definitionen/${definition.id}`,
                payload,
            );
            router.reload();
        } catch (caught) {
            handleCaught(
                caught,
                'Die Definition konnte nicht gespeichert werden.',
            );
        }
    }

    async function saveRevision(event: FormEvent) {
        event.preventDefault();
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setFieldErrors({});

        try {
            const payload: Record<string, unknown> = {
                lock_version: definition.lock_version,
                label: revision.label,
                help_text:
                    revision.help_text.trim() === ''
                        ? null
                        : revision.help_text,
                group_key:
                    revision.group_key.trim() === ''
                        ? null
                        : revision.group_key,
                sort_default: revision.sort_default,
                reportable: revision.reportable,
            };
            if (!isChoiceType(definition.field_type)) {
                payload.max_length =
                    revision.max_length === ''
                        ? null
                        : Number(revision.max_length);
            }
            await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/definitionen/${definition.id}/revisionen`,
                payload,
            );
            router.reload();
        } catch (caught) {
            handleCaught(caught, 'Die Revision konnte nicht angelegt werden.');
        }
    }

    async function runLifecycle(
        action: 'deactivate' | 'reactivate' | 'delete',
    ) {
        if (busy) {
            return;
        }

        const confirmMessage =
            action === 'delete'
                ? 'Feld wirklich löschen? Nur möglich ohne Memberships, Snapshots und Werte.'
                : action === 'deactivate'
                  ? 'Feld deaktivieren?'
                  : 'Feld reaktivieren?';

        if (!window.confirm(confirmMessage)) {
            return;
        }

        setBusy(true);
        setError(null);
        setFieldErrors({});

        try {
            if (action === 'delete') {
                const result = await jsonDelete<{ redirect: string }>(
                    `/administration/dynamische-felder/definitionen/${definition.id}`,
                    { lock_version: definition.lock_version },
                );
                router.visit(result.redirect);
                return;
            }

            const path =
                action === 'deactivate'
                    ? `/administration/dynamische-felder/definitionen/${definition.id}/deaktivieren`
                    : `/administration/dynamische-felder/definitionen/${definition.id}/reaktivieren`;
            await jsonPost(path, { lock_version: definition.lock_version });
            router.reload();
        } catch (caught) {
            handleCaught(caught, 'Die Aktion ist fehlgeschlagen.');
        }
    }

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
                {error ? <ErrorState message={error} /> : null}

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant="secondary">
                        {isCustom ? 'Eigenes Feld' : 'Systemfeld'}
                    </Badge>
                    <Badge
                        variant={definition.is_active ? 'secondary' : 'outline'}
                        data-test="field-definition-active-badge"
                    >
                        Feld {definition.is_active ? 'aktiv' : 'inaktiv'}
                    </Badge>
                    <span className="text-muted-foreground">
                        Sperrversion {definition.lock_version}
                    </span>
                    {isCustom ? (
                        <span className="text-muted-foreground">
                            {definition.is_used
                                ? 'Bereits verwendet (strukturell gesperrt)'
                                : 'Noch unbenutzt'}
                        </span>
                    ) : null}
                </div>

                {isCustom && !definition.is_used ? (
                    <section className="max-w-xl space-y-4 rounded-xl border p-4">
                        <h2 className="text-base font-semibold">
                            Strukturelle Bearbeitung
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Solange das Feld unbenutzt ist, können Typ, Geltung
                            und Metadaten geändert werden. Der Schlüssel bleibt
                            fest.
                        </p>
                        <form className="space-y-4" onSubmit={saveStructural}>
                            <FormField
                                label="Anzeigename"
                                htmlFor="structural-label"
                                error={fieldErrors.label}
                            >
                                <Input
                                    id="structural-label"
                                    value={structural.label}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            label: e.target.value,
                                        })
                                    }
                                    required
                                />
                            </FormField>
                            <FormField
                                label="Feldtyp"
                                htmlFor="structural-field-type"
                                error={fieldErrors.field_type}
                            >
                                <select
                                    id="structural-field-type"
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    value={structural.field_type}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            field_type: e.target.value,
                                        })
                                    }
                                >
                                    <option value="short_text">
                                        short_text
                                    </option>
                                    <option value="long_text">long_text</option>
                                    <option value="select">select</option>
                                    <option value="multi_select">
                                        multi_select
                                    </option>
                                </select>
                            </FormField>
                            <FormField
                                label="Gilt für"
                                htmlFor="structural-applies-to"
                                error={fieldErrors.applies_to}
                            >
                                <select
                                    id="structural-applies-to"
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    value={structural.applies_to}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            applies_to: e.target.value,
                                        })
                                    }
                                >
                                    <option value="calculation">
                                        Kalkulation
                                    </option>
                                    <option value="dispo_order">
                                        Dispoauftrag
                                    </option>
                                    <option value="both">Beide</option>
                                </select>
                            </FormField>
                            {!isChoiceType(structural.field_type) ? (
                                <FormField
                                    label="Maximallänge"
                                    htmlFor="structural-max-length"
                                    error={fieldErrors.max_length}
                                >
                                    <Input
                                        id="structural-max-length"
                                        type="number"
                                        min={1}
                                        max={
                                            structural.field_type ===
                                            'short_text'
                                                ? 255
                                                : 20000
                                        }
                                        value={structural.max_length}
                                        onChange={(e) =>
                                            setStructural({
                                                ...structural,
                                                max_length: e.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            ) : null}
                            <FormField
                                label="Hilfetext"
                                htmlFor="structural-help"
                                error={fieldErrors.help_text}
                            >
                                <textarea
                                    id="structural-help"
                                    rows={3}
                                    value={structural.help_text}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            help_text: e.target.value,
                                        })
                                    }
                                    className="border-input bg-background flex w-full rounded-md border px-3 py-2 text-sm"
                                />
                            </FormField>
                            <FormField
                                label="Gruppe"
                                htmlFor="structural-group"
                                error={fieldErrors.group_key}
                            >
                                <Input
                                    id="structural-group"
                                    value={structural.group_key}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            group_key: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <FormField
                                label="Standardsortierung"
                                htmlFor="structural-sort"
                                error={fieldErrors.sort_default}
                            >
                                <Input
                                    id="structural-sort"
                                    type="number"
                                    min={0}
                                    value={structural.sort_default}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            sort_default: Number(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                    required
                                />
                            </FormField>
                            <div className="flex items-center gap-2">
                                <input
                                    id="structural-reportable"
                                    type="checkbox"
                                    checked={structural.reportable}
                                    onChange={(e) =>
                                        setStructural({
                                            ...structural,
                                            reportable: e.target.checked,
                                        })
                                    }
                                    className="size-4 rounded border"
                                />
                                <Label htmlFor="structural-reportable">
                                    Reportfähig
                                </Label>
                            </div>
                            {fieldErrors.definition ? (
                                <p className="text-destructive text-sm">
                                    {fieldErrors.definition}
                                </p>
                            ) : null}
                            <Button type="submit" disabled={busy}>
                                Speichern
                            </Button>
                        </form>
                    </section>
                ) : null}

                {definition.can_manage_options && routes ? (
                    <FieldDefinitionOptionsEditor
                        lockVersion={definition.lock_version}
                        initialOptions={definition.options}
                        boundaryNote={optionsBoundaryNote}
                        routes={routes}
                        onApplied={(result) => {
                            setDefinition((prev) => ({
                                ...prev,
                                lock_version: result.lock_version,
                                options: result.options,
                                options_fingerprint: result.fingerprint,
                                current_revision: result.current_revision,
                                revisions: result.revisions,
                            }));
                        }}
                    />
                ) : null}

                <section className="max-w-xl space-y-4 rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Neue Revision</h2>
                    <p className="text-muted-foreground text-sm">
                        Schlüssel und Feldtyp bleiben unveränderlich. Neue
                        Revisionen gelten erst nach Pin in einem Entwurf und
                        dessen Aktivierung für neue Vorgänge.
                    </p>
                    <form className="space-y-4" onSubmit={saveRevision}>
                        <FormField
                            label="Anzeigename"
                            htmlFor="label"
                            error={fieldErrors.label}
                        >
                            <Input
                                id="label"
                                value={revision.label}
                                onChange={(e) =>
                                    setRevision({
                                        ...revision,
                                        label: e.target.value,
                                    })
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            label="Hilfetext"
                            htmlFor="help_text"
                            error={fieldErrors.help_text}
                        >
                            <textarea
                                id="help_text"
                                rows={3}
                                value={revision.help_text}
                                onChange={(e) =>
                                    setRevision({
                                        ...revision,
                                        help_text: e.target.value,
                                    })
                                }
                                className="border-input bg-background flex w-full rounded-md border px-3 py-2 text-sm"
                            />
                        </FormField>
                        <FormField
                            label="Gruppe"
                            htmlFor="group_key"
                            error={fieldErrors.group_key}
                        >
                            <Input
                                id="group_key"
                                value={revision.group_key}
                                onChange={(e) =>
                                    setRevision({
                                        ...revision,
                                        group_key: e.target.value,
                                    })
                                }
                            />
                        </FormField>
                        <FormField
                            label="Standardsortierung"
                            htmlFor="sort_default"
                            error={fieldErrors.sort_default}
                        >
                            <Input
                                id="sort_default"
                                type="number"
                                min={0}
                                value={revision.sort_default}
                                onChange={(e) =>
                                    setRevision({
                                        ...revision,
                                        sort_default: Number(e.target.value),
                                    })
                                }
                                required
                            />
                        </FormField>
                        {isCustom && !isChoiceType(definition.field_type) ? (
                            <FormField
                                label="Maximallänge"
                                htmlFor="rev-max-length"
                                error={fieldErrors.max_length}
                                hint="max. 255 (short_text) bzw. 20000 (long_text)"
                            >
                                <Input
                                    id="rev-max-length"
                                    type="number"
                                    min={1}
                                    max={
                                        definition.field_type === 'short_text'
                                            ? 255
                                            : 20000
                                    }
                                    value={revision.max_length}
                                    onChange={(e) =>
                                        setRevision({
                                            ...revision,
                                            max_length: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        ) : null}
                        <div className="flex items-center gap-2">
                            <input
                                id="reportable"
                                type="checkbox"
                                checked={revision.reportable}
                                onChange={(e) =>
                                    setRevision({
                                        ...revision,
                                        reportable: e.target.checked,
                                    })
                                }
                                className="size-4 rounded border"
                            />
                            <Label htmlFor="reportable">Reportfähig</Label>
                        </div>
                        <Button type="submit" disabled={busy}>
                            Revision anlegen
                        </Button>
                    </form>
                </section>

                {isCustom ? (
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="text-base font-semibold">Status</h2>
                        <div className="flex flex-wrap gap-2">
                            {definition.is_active ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void runLifecycle('deactivate')
                                    }
                                >
                                    Deaktivieren
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void runLifecycle('reactivate')
                                    }
                                >
                                    Reaktivieren
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={busy || definition.is_used}
                                onClick={() => void runLifecycle('delete')}
                            >
                                Löschen
                            </Button>
                        </div>
                    </section>
                ) : null}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Feldset-Mitgliedschaften
                    </h2>
                    {definition.memberships.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Keine Mitgliedschaften.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            Feldset
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Version
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Sort
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Gepinnte Rev-ID
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {definition.memberships.map(
                                        (membership) => (
                                            <tr
                                                key={membership.id}
                                                className="border-t"
                                                data-test={`field-definition-membership-${membership.id}`}
                                            >
                                                <td className="px-4 py-2">
                                                    {membership.field_set_id ? (
                                                        <Link
                                                            href={`/administration/dynamische-felder/feldsets/${membership.field_set_id}`}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {membership.field_set_name ??
                                                                membership.field_set_key}
                                                        </Link>
                                                    ) : (
                                                        (membership.field_set_key ??
                                                        '–')
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {membership.version ?? '–'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {membership.status ?? '–'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {membership.sort}
                                                </td>
                                                <td
                                                    className="px-4 py-2 font-mono"
                                                    data-test={`field-definition-membership-pin-${membership.id}`}
                                                >
                                                    {membership.field_definition_revision_id ??
                                                        '–'}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
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
                                        Max.
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Reportfähig
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {definition.revisions.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            {row.revision}
                                            {current?.id === row.id
                                                ? ' (aktuell)'
                                                : ''}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.label}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.help_text ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.validation_json?.max_length ??
                                                '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.reportable ? 'ja' : 'nein'}
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
