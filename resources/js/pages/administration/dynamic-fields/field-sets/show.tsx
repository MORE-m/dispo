import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

type VersionRow = {
    id: number;
    version: number;
    status: string;
    created_at: string | null;
};

type ActiveAssignmentRow = {
    id: number;
    applies_to_process: string;
    applies_to_process_label: string;
    target_layer: string;
    target_layer_label: string;
    target_label: string;
    href: string;
};

type FieldSetDetail = {
    id: number;
    key: string;
    name: string;
    is_system: boolean;
    applies_to: string;
    is_assignable: boolean;
    lock_version: number;
    active_version_id: number | null;
    has_ever_been_activated: boolean;
    can_edit_applies_to: boolean;
    can_deactivate: boolean;
    can_reactivate: boolean;
    active_assignment_count: number;
    active_assignments: ActiveAssignmentRow[];
    has_inconsistent_active_assignments: boolean;
    versions: VersionRow[];
};

function usabilityLabel(fieldSet: FieldSetDetail): string {
    if (fieldSet.is_system) {
        return 'Kern-Feldset';
    }
    if (fieldSet.is_assignable) {
        return 'assignierbar (vorbereitet)';
    }
    if (fieldSet.active_version_id !== null) {
        return 'deaktiviert';
    }

    return 'Entwurf / noch nicht nutzbar';
}

export default function FieldSetShow({
    fieldSet,
    appliesToOptions,
    runtimeNote,
}: {
    fieldSet: FieldSetDetail;
    appliesToOptions: Array<{ value: string; label: string }>;
    runtimeNote: string | null;
}) {
    const flash = usePage().props.flash;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const draft = fieldSet.versions.find((v) => v.status === 'draft');
    const sourceId =
        draft === undefined
            ? (fieldSet.active_version_id ??
              fieldSet.versions.find((v) => v.status === 'active')?.id ??
              fieldSet.versions[0]?.id)
            : null;
    const hasActiveAssignments = fieldSet.active_assignment_count > 0;
    const deactivateBlockedByAssignments =
        fieldSet.is_assignable &&
        hasActiveAssignments &&
        !fieldSet.can_deactivate;

    const metaForm = useForm({
        lock_version: fieldSet.lock_version,
        name: fieldSet.name,
        applies_to: fieldSet.applies_to,
    });

    async function createDraftFrom(versionId: number) {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/entwuerfe`,
                {
                    lock_version: fieldSet.lock_version,
                    source_version_id: versionId,
                },
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : (firstValidationMessage(caught.fieldErrors) ??
                              (caught.message ||
                                  'Der Entwurf konnte nicht angelegt werden.')),
                );
            } else {
                setError('Der Entwurf konnte nicht angelegt werden.');
            }
            setBusy(false);
        }
    }

    async function deactivate() {
        if (busy || !fieldSet.can_deactivate) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/deaktivieren`,
                { lock_version: fieldSet.lock_version },
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    firstValidationMessage(caught.fieldErrors) ??
                        caught.message,
                );
            } else {
                setError('Deaktivieren fehlgeschlagen.');
            }
            setBusy(false);
        }
    }

    async function reactivate() {
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/reaktivieren`,
                { lock_version: fieldSet.lock_version },
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    firstValidationMessage(caught.fieldErrors) ??
                        caught.message,
                );
            } else {
                setError('Reaktivieren fehlgeschlagen.');
            }
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Feldset ${fieldSet.key}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={fieldSet.name}
                    description={`${fieldSet.key} · ${usabilityLabel(fieldSet)} · Sperrversion ${fieldSet.lock_version}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {draft ? (
                                <Button asChild>
                                    <Link
                                        href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${draft.id}`}
                                        data-test="fieldset-open-draft"
                                    >
                                        Entwurf öffnen
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    data-test="fieldset-create-draft"
                                    onClick={() => {
                                        if (
                                            sourceId !== null &&
                                            sourceId !== undefined
                                        ) {
                                            void createDraftFrom(sourceId);
                                        }
                                    }}
                                    disabled={sourceId === null || busy}
                                >
                                    Entwurf aus aktiver Version
                                </Button>
                            )}
                            {fieldSet.can_deactivate ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    data-test="fieldset-deactivate"
                                    disabled={busy}
                                    onClick={() => void deactivate()}
                                >
                                    Deaktivieren
                                </Button>
                            ) : null}
                            {deactivateBlockedByAssignments ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    data-test="fieldset-deactivate-blocked"
                                    disabled
                                    title="Zuerst aktive Assignments deaktivieren"
                                >
                                    Deaktivieren (blockiert)
                                </Button>
                            ) : null}
                            {fieldSet.can_reactivate ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    data-test="fieldset-reactivate"
                                    disabled={busy}
                                    onClick={() => void reactivate()}
                                >
                                    Reaktivieren
                                </Button>
                            ) : null}
                            <Button variant="outline" asChild>
                                <Link href="/administration/dynamische-felder/feldsets">
                                    Zurück
                                </Link>
                            </Button>
                        </div>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {error ? (
                    <ErrorState
                        message={error}
                        data-test="fieldset-lifecycle-error"
                    />
                ) : null}
                {runtimeNote ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="fieldset-runtime-note"
                    >
                        {runtimeNote}
                    </p>
                ) : null}

                {fieldSet.has_inconsistent_active_assignments ? (
                    <div
                        className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                        data-test="fieldset-inconsistent-active-assignments"
                    >
                        <p className="font-medium">
                            Inkonsistenter Zustand: Feldset ist nicht
                            assignierbar, verweist aber noch auf aktive
                            Assignments.
                        </p>
                        <p className="mt-1">
                            Keine automatische Bereinigung. Sicherer
                            Reparaturweg: Feldset reaktivieren, danach
                            Assignments bewusst deaktivieren, anschließend
                            Feldset erneut deaktivieren.
                        </p>
                    </div>
                ) : null}

                {!fieldSet.is_system ? (
                    <section
                        className="max-w-3xl space-y-3 rounded-xl border p-4"
                        data-test="fieldset-active-assignments"
                    >
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="font-semibold">
                                Aktive Assignments
                            </h2>
                            <p
                                className="text-muted-foreground text-sm"
                                data-test="fieldset-active-assignment-count"
                            >
                                {fieldSet.active_assignment_count} aktiv
                            </p>
                        </div>
                        {deactivateBlockedByAssignments ? (
                            <p
                                className="text-sm"
                                data-test="fieldset-deactivate-blocker"
                            >
                                Das Feldset kann nicht deaktiviert werden,
                                solange aktive Assignments darauf verweisen.
                                Deaktiviere zuerst die aufgeführten Assignments.
                            </p>
                        ) : null}
                        {hasActiveAssignments ? (
                            <ul className="space-y-2 text-sm">
                                {fieldSet.active_assignments.map(
                                    (assignment) => (
                                        <li
                                            key={assignment.id}
                                            className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-t pt-2 first:border-t-0 first:pt-0"
                                            data-test="fieldset-active-assignment-row"
                                            data-assignment-id={assignment.id}
                                        >
                                            <Link
                                                href={assignment.href}
                                                className="font-medium underline-offset-2 hover:underline"
                                                data-test="fieldset-active-assignment-link"
                                            >
                                                Assignment #{assignment.id}
                                            </Link>
                                            <span>
                                                {
                                                    assignment.applies_to_process_label
                                                }
                                            </span>
                                            <span>
                                                {assignment.target_layer_label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {assignment.target_label}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Keine aktiven Assignments. Deaktivierung ist
                                freigegeben, sofern das Feldset assignierbar
                                ist.
                            </p>
                        )}
                    </section>
                ) : null}

                <section
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="fieldset-metadata"
                >
                    <h2 className="font-semibold">Container-Metadaten</h2>
                    <dl className="grid gap-2 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                Technischer Schlüssel
                            </dt>
                            <dd className="font-mono">{fieldSet.key}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Typ</dt>
                            <dd>
                                {fieldSet.is_system
                                    ? 'Kern-Feldset'
                                    : 'Freies Feldset'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd data-test="fieldset-usability-label">
                                {usabilityLabel(fieldSet)}
                            </dd>
                        </div>
                    </dl>

                    {!fieldSet.is_system ? (
                        <form
                            className="space-y-3"
                            data-test="fieldset-metadata-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                metaForm.put(
                                    `/administration/dynamische-felder/feldsets/${fieldSet.id}`,
                                    {
                                        preserveScroll: true,
                                        onError: () => {
                                            const first = Object.values(
                                                metaForm.errors,
                                            )[0];
                                            setError(
                                                typeof first === 'string'
                                                    ? first
                                                    : 'Speichern fehlgeschlagen.',
                                            );
                                        },
                                    },
                                );
                            }}
                        >
                            <FormField
                                label="Name"
                                error={metaForm.errors.name}
                                htmlFor="fieldset-meta-name"
                            >
                                <Input
                                    id="fieldset-meta-name"
                                    data-test="fieldset-meta-name"
                                    value={metaForm.data.name}
                                    onChange={(event) =>
                                        metaForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <div className="space-y-1">
                                <label
                                    className="text-sm font-medium"
                                    htmlFor="fieldset-meta-applies-to"
                                >
                                    Gültigkeit
                                </label>
                                <select
                                    id="fieldset-meta-applies-to"
                                    data-test="fieldset-meta-applies-to"
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                    value={metaForm.data.applies_to}
                                    disabled={!fieldSet.can_edit_applies_to}
                                    onChange={(event) =>
                                        metaForm.setData(
                                            'applies_to',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {appliesToOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                {!fieldSet.can_edit_applies_to ? (
                                    <p className="text-muted-foreground text-xs">
                                        Nach der ersten Aktivierung
                                        unveränderlich.
                                    </p>
                                ) : null}
                                {metaForm.errors.applies_to ? (
                                    <p className="text-destructive text-sm">
                                        {metaForm.errors.applies_to}
                                    </p>
                                ) : null}
                            </div>
                            <Button
                                type="submit"
                                disabled={metaForm.processing}
                                data-test="fieldset-metadata-save"
                            >
                                Metadaten speichern
                            </Button>
                        </form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Kern-Feldsets: Schlüssel, Systemstatus und
                            Gültigkeit sind geschützt und nicht assignierbar.
                        </p>
                    )}
                </section>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-4 py-2 font-medium">
                                    Version
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Aktionen
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {fieldSet.versions.map((version) => (
                                <tr key={version.id} className="border-t">
                                    <td className="px-4 py-2">
                                        v{version.version}
                                    </td>
                                    <td className="px-4 py-2">
                                        {version.status}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}`}
                                                >
                                                    Ansehen
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/vorschau`}
                                                >
                                                    Vorschau
                                                </Link>
                                            </Button>
                                            {(version.status === 'archived' ||
                                                version.status === 'active') &&
                                            draft === undefined ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void createDraftFrom(
                                                            version.id,
                                                        )
                                                    }
                                                >
                                                    Als Vorlage kopieren
                                                </Button>
                                            ) : null}
                                        </div>
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
