import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import FieldSetRulesEditor from '@/components/administration/field-set-rules-editor';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonDelete, jsonPost, jsonPut } from '@/lib/json-post';
import type { FieldCatalogEntry } from '@/lib/field-set-rules-draft';

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
    is_system: boolean;
    scope?: string;
    applies_to?: string;
    sort: number;
    required_override: boolean | null;
    visible_override: boolean | null;
    available_revisions: AvailableRevision[];
};

type AvailableCustomDefinition = {
    id: number;
    key: string;
    label: string | null;
    scope: string;
    applies_to: string;
    field_type: string;
    current_revision_id: number | null;
    available_revisions: AvailableRevision[];
};

type RuleRow = {
    id: number;
    sort: number;
    condition: Record<string, unknown>;
    action: Record<string, unknown>;
    dedupe_key?: string;
    is_system_seed?: boolean;
};

type Props = {
    fieldSet: {
        id: number;
        key: string;
        name: string;
        is_system?: boolean;
        applies_to?: string;
        lock_version: number;
    };
    version: {
        id: number;
        version: number;
        status: string;
        editable: boolean;
        fields: Membership[];
        rules: RuleRow[];
        field_catalog: FieldCatalogEntry[];
    };
    availableCustomDefinitions?: AvailableCustomDefinition[];
    rulesRoutes: {
        preview: string;
        replace: string;
    };
};

export default function FieldSetVersionEdit({
    fieldSet,
    version,
    availableCustomDefinitions = [],
    rulesRoutes,
}: Props) {
    const flash = usePage().props.flash;
    const [fields, setFields] = useState(version.fields);
    const [lockVersion, setLockVersion] = useState(fieldSet.lock_version);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [selectedDefinitionId, setSelectedDefinitionId] = useState<
        number | ''
    >('');
    const [selectedRevisionId, setSelectedRevisionId] = useState<number | ''>(
        '',
    );
    const [addSort, setAddSort] = useState(100);

    const selectedDefinition = availableCustomDefinitions.find(
        (definition) => definition.id === selectedDefinitionId,
    );

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

    function handleCaught(caught: unknown, fallback: string) {
        if (caught instanceof JsonPostError) {
            setError(caught.message || fallback);
        } else {
            setError(fallback);
        }
        setBusy(false);
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
                    lock_version: lockVersion,
                    fields: membershipPayload(),
                },
            );
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            handleCaught(
                caught,
                'Der Entwurf konnte nicht gespeichert werden.',
            );
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
                { lock_version: lockVersion },
            );
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            handleCaught(
                caught,
                'Die Revisionen konnten nicht übernommen werden.',
            );
        }
    }

    async function addMembership() {
        if (busy || selectedDefinitionId === '' || selectedRevisionId === '') {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/felder`,
                {
                    lock_version: lockVersion,
                    field_definition_id: selectedDefinitionId,
                    field_definition_revision_id: selectedRevisionId,
                    sort: addSort,
                    required_override: null,
                    visible_override: null,
                },
            );
            setAddOpen(false);
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            handleCaught(caught, 'Das Feld konnte nicht hinzugefügt werden.');
        }
    }

    async function removeMembership(membership: Membership) {
        if (busy || membership.is_system) {
            return;
        }

        if (
            !window.confirm(
                `Feld „${membership.key}“ aus dem Entwurf entfernen?`,
            )
        ) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonDelete<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/felder/${membership.id}`,
                { lock_version: lockVersion },
            );
            router.visit(result.redirect, { preserveScroll: true });
        } catch (caught) {
            handleCaught(caught, 'Das Feld konnte nicht entfernt werden.');
        }
    }

    return (
        <>
            <Head title={`Feldset-Version ${version.version}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`${fieldSet.name} · Version ${version.version}`}
                    description={`Status: ${version.status}. Regeln können im Entwurf bearbeitet werden. Custom-Felder mit Scope header oder position können hinzugefügt werden.`}
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
                            data-test="fieldset-draft-save"
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setSelectedDefinitionId('');
                                setSelectedRevisionId('');
                                setAddSort(100);
                                setAddOpen(true);
                            }}
                            disabled={busy}
                            data-test="fieldset-add-custom-field"
                        >
                            Eigenes Feld hinzufügen
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
                                    {version.editable ? (
                                        <th className="px-4 py-2 font-medium">
                                            Aktion
                                        </th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((field, index) => (
                                    <tr key={field.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    {field.key}
                                                </span>
                                                {field.is_system ? (
                                                    <Badge variant="outline">
                                                        System
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        Eigen
                                                    </Badge>
                                                )}
                                                {field.scope ? (
                                                    <Badge variant="outline">
                                                        {field.scope}
                                                    </Badge>
                                                ) : null}
                                                {field.applies_to ? (
                                                    <Badge variant="outline">
                                                        {field.applies_to}
                                                    </Badge>
                                                ) : null}
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
                                                        (rev) => (
                                                            <option
                                                                key={rev.id}
                                                                value={rev.id}
                                                            >
                                                                r{rev.revision}:{' '}
                                                                {rev.label}
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
                                                    data-test={`membership-required-${field.key}`}
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
                                        {version.editable ? (
                                            <td className="px-4 py-2">
                                                {!field.is_system ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={busy}
                                                        data-test={`membership-remove-${field.key}`}
                                                        onClick={() =>
                                                            void removeMembership(
                                                                field,
                                                            )
                                                        }
                                                    >
                                                        Entfernen
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">
                                                        –
                                                    </span>
                                                )}
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <FieldSetRulesEditor
                    editable={version.editable}
                    lockVersion={lockVersion}
                    fieldCatalog={version.field_catalog ?? []}
                    initialRules={version.rules}
                    routes={rulesRoutes}
                    onLockVersionChange={setLockVersion}
                />
            </div>

            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Eigenes Feld hinzufügen</DialogTitle>
                        <DialogDescription>
                            Nur aktive Custom-Felder (header oder position) mit
                            passendem applies_to.
                        </DialogDescription>
                    </DialogHeader>
                    {availableCustomDefinitions.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Keine passenden eigenen Felder verfügbar.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            <label className="block space-y-1 text-sm">
                                <span className="font-medium">Feld</span>
                                <select
                                    className="border-input bg-background w-full rounded-md border px-3 py-2"
                                    data-test="fieldset-add-definition-select"
                                    value={selectedDefinitionId}
                                    onChange={(e) => {
                                        const id =
                                            e.target.value === ''
                                                ? ''
                                                : Number(e.target.value);
                                        setSelectedDefinitionId(id);
                                        const def =
                                            availableCustomDefinitions.find(
                                                (row) => row.id === id,
                                            );
                                        setSelectedRevisionId(
                                            def?.current_revision_id ?? '',
                                        );
                                    }}
                                >
                                    <option value="">Bitte wählen</option>
                                    {availableCustomDefinitions.map((def) => (
                                        <option key={def.id} value={def.id}>
                                            [{def.scope}] {def.key}
                                            {def.label ? ` – ${def.label}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {selectedDefinition ? (
                                <label className="block space-y-1 text-sm">
                                    <span className="font-medium">
                                        Revision
                                    </span>
                                    <select
                                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                                        value={selectedRevisionId}
                                        onChange={(e) =>
                                            setSelectedRevisionId(
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                            )
                                        }
                                    >
                                        {selectedDefinition.available_revisions.map(
                                            (rev) => (
                                                <option
                                                    key={rev.id}
                                                    value={rev.id}
                                                >
                                                    r{rev.revision}: {rev.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                            ) : null}
                            <label className="block space-y-1 text-sm">
                                <span className="font-medium">Sortierung</span>
                                <Input
                                    type="number"
                                    min={0}
                                    value={addSort}
                                    onChange={(e) =>
                                        setAddSort(Number(e.target.value))
                                    }
                                />
                            </label>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAddOpen(false)}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                busy ||
                                selectedDefinitionId === '' ||
                                selectedRevisionId === ''
                            }
                            data-test="fieldset-add-membership-submit"
                            onClick={() => void addMembership()}
                        >
                            Hinzufügen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
