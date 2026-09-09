import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonPost, jsonPut } from '@/lib/json-post';

type Option = { value: string; label: string };

type FieldSetOption = {
    id: number;
    key: string;
    name: string;
    applies_to: string;
    applies_to_label: string;
};

type CategoryOption = {
    id: number;
    key: string;
    name: string;
    is_active: boolean;
    label: string;
};

type MediumOption = {
    id: number;
    code: string;
    name: string;
    category_id: number;
    category_name: string | null;
    is_active: boolean;
    label: string;
};

type FormOptions = {
    fieldSets: FieldSetOption[];
    categories: CategoryOption[];
    media: MediumOption[];
    targetLayerOptions: Option[];
    processOptions: Option[];
};

type AssignmentDetail = {
    id: number;
    field_set_id: number;
    field_set_key: string | null;
    field_set_name: string | null;
    target_layer: string;
    target_layer_label: string;
    advertising_category_id: number | null;
    advertising_medium_id: number | null;
    target_label: string;
    applies_to_process: string;
    applies_to_process_label: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    lock_version: number;
    target_is_selectable: boolean;
    updated_at: string | null;
};

type PreviewField = {
    field_key?: string;
    label?: string;
    winning_layer?: string;
    winning_field_set_key?: string | null;
    winning_assignment_id?: number | null;
    required_override?: boolean | null;
    visible_override?: boolean | null;
    override_chain?: Array<{
        layer?: string;
        field_set_key?: string | null;
        assignment_id?: number | null;
        required_override?: boolean | null;
        visible_override?: boolean | null;
    }>;
};

type PreviewConflict = {
    code?: string;
    message?: string;
};

type ContextPreview = {
    process: string;
    scope: string;
    advertising_category_id?: number | null;
    advertising_medium_id?: number | null;
    fingerprint: string;
    has_blocking_conflicts: boolean;
    fields: PreviewField[];
    skipped_sources?: Array<{
        assignment_id?: number;
        reason_code?: string;
        message?: string;
    }>;
    conflicts: PreviewConflict[];
    included_assignments?: Array<{
        id: number;
        field_set_key?: string | null;
        target_layer?: string;
        is_candidate?: boolean;
    }>;
};

type Routes = {
    update: string;
    contextPreview: string;
    activationPreview: string;
    activate: string;
    deactivate: string;
    index: string;
};

function firstFieldError(
    fieldErrors: Record<string, string[]>,
    key: string,
): string | undefined {
    return fieldErrors[key]?.[0];
}

function layerLabel(layer: string | undefined): string {
    switch (layer) {
        case 'primary_core':
        case 'core':
            return 'Kern-Feldset';
        case 'global':
            return 'Global';
        case 'advertising_category':
            return 'Oberkategorie';
        case 'advertising_medium':
            return 'Werbemittel';
        default:
            return layer ?? 'unbekannt';
    }
}

function processLabel(value: string): string {
    switch (value) {
        case 'calculation':
            return 'Kalkulation';
        case 'dispo_order':
            return 'Dispoauftrag';
        case 'both':
            return 'Beide';
        default:
            return value;
    }
}

function scopeLabel(value: string): string {
    switch (value) {
        case 'header':
            return 'Kopf';
        case 'position':
            return 'Position';
        default:
            return value;
    }
}

function OverrideChain({
    chain,
}: {
    chain: NonNullable<PreviewField['override_chain']>;
}) {
    const [open, setOpen] = useState(false);
    if (chain.length === 0) {
        return null;
    }

    return (
        <div className="mt-1">
            <button
                type="button"
                className="text-muted-foreground text-xs underline-offset-2 hover:underline"
                onClick={() => setOpen((value) => !value)}
            >
                {open
                    ? 'Override-Kette ausblenden'
                    : `Override-Kette anzeigen (${chain.length})`}
            </button>
            {open ? (
                <ul className="text-muted-foreground mt-1 list-disc space-y-1 pl-4 text-xs">
                    {chain.map((entry, index) => (
                        <li
                            key={`${entry.layer}-${entry.assignment_id}-${index}`}
                        >
                            {layerLabel(entry.layer)}
                            {entry.field_set_key
                                ? ` · ${entry.field_set_key}`
                                : ''}
                            {entry.assignment_id
                                ? ` · Assignment #${entry.assignment_id}`
                                : ''}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

function PreviewPanel({
    title,
    preview,
    dataTest,
}: {
    title: string;
    preview: ContextPreview;
    dataTest: string;
}) {
    return (
        <section
            className="space-y-3 rounded-xl border p-4"
            data-test={dataTest}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="font-medium">{title}</h3>
                    <p className="text-muted-foreground text-sm">
                        {processLabel(preview.process)} ·{' '}
                        {scopeLabel(preview.scope)}
                        {preview.advertising_category_id
                            ? ` · Kategorie #${preview.advertising_category_id}`
                            : ''}
                        {preview.advertising_medium_id
                            ? ` · Medium #${preview.advertising_medium_id}`
                            : ''}
                    </p>
                </div>
                <span
                    className={
                        preview.has_blocking_conflicts
                            ? 'rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-900 dark:bg-red-950 dark:text-red-100'
                            : 'rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                    }
                    data-test={`${dataTest}-conflict-badge`}
                >
                    {preview.has_blocking_conflicts
                        ? 'Blockierende Konflikte'
                        : 'Konfliktfrei'}
                </span>
            </div>

            <p
                className="font-mono text-xs break-all"
                data-test={`${dataTest}-fingerprint`}
            >
                Fingerprint: {preview.fingerprint}
            </p>

            {preview.conflicts.length > 0 ? (
                <div
                    className="space-y-1 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
                    data-test={`${dataTest}-conflicts`}
                >
                    <p className="font-medium">Konflikte</p>
                    <ul className="list-disc space-y-1 pl-4">
                        {preview.conflicts.map((conflict, index) => (
                            <li key={`${conflict.code}-${index}`}>
                                {conflict.message ??
                                    conflict.code ??
                                    'Konflikt'}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {(preview.skipped_sources?.length ?? 0) > 0 ? (
                <div className="text-muted-foreground text-sm">
                    <p className="text-foreground font-medium">
                        Übersprungene Quellen
                    </p>
                    <ul className="mt-1 list-disc space-y-1 pl-4">
                        {preview.skipped_sources!.map((skipped, index) => (
                            <li key={`${skipped.assignment_id}-${index}`}>
                                {skipped.message ??
                                    skipped.reason_code ??
                                    `Assignment #${skipped.assignment_id}`}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[560px] text-left text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="px-3 py-2 font-medium">Feld</th>
                            <th className="px-3 py-2 font-medium">Herkunft</th>
                            <th className="px-3 py-2 font-medium">Feldset</th>
                            <th className="px-3 py-2 font-medium">Overrides</th>
                        </tr>
                    </thead>
                    <tbody>
                        {preview.fields.length === 0 ? (
                            <tr className="border-t">
                                <td
                                    className="text-muted-foreground px-3 py-3"
                                    colSpan={4}
                                >
                                    Keine Felder in diesem Kontext.
                                </td>
                            </tr>
                        ) : (
                            preview.fields.map((field) => (
                                <tr
                                    key={field.field_key}
                                    className="border-t align-top"
                                    data-test={`${dataTest}-field-${field.field_key}`}
                                >
                                    <td className="px-3 py-2">
                                        <div className="font-medium">
                                            {field.label ?? field.field_key}
                                        </div>
                                        <div className="text-muted-foreground font-mono text-xs">
                                            {field.field_key}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        {layerLabel(field.winning_layer)}
                                        {field.winning_assignment_id ? (
                                            <div className="text-muted-foreground text-xs">
                                                Assignment #
                                                {field.winning_assignment_id}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {field.winning_field_set_key ?? '–'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        Pflicht:{' '}
                                        {field.required_override === null ||
                                        field.required_override === undefined
                                            ? 'geerbt'
                                            : field.required_override
                                              ? 'ja'
                                              : 'nein'}
                                        <br />
                                        Sichtbar:{' '}
                                        {field.visible_override === null ||
                                        field.visible_override === undefined
                                            ? 'geerbt'
                                            : field.visible_override
                                              ? 'ja'
                                              : 'nein'}
                                        {field.override_chain ? (
                                            <OverrideChain
                                                chain={field.override_chain}
                                            />
                                        ) : null}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function AssignmentShow({
    assignment,
    formOptions,
    catalogNote,
    routes,
}: {
    assignment: AssignmentDetail;
    formOptions: FormOptions;
    catalogNote: string;
    routes: Routes;
}) {
    const [lockVersion, setLockVersion] = useState(assignment.lock_version);
    const [isActive, setIsActive] = useState(assignment.is_active);
    const [fieldSetId, setFieldSetId] = useState(
        assignment.field_set_id.toString(),
    );
    const [targetLayer, setTargetLayer] = useState(assignment.target_layer);
    const [categoryId, setCategoryId] = useState(
        assignment.advertising_category_id?.toString() ?? '',
    );
    const [mediumId, setMediumId] = useState(
        assignment.advertising_medium_id?.toString() ?? '',
    );
    const [process, setProcess] = useState(assignment.applies_to_process);
    const [sort, setSort] = useState(assignment.sort.toString());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [contextPreview, setContextPreview] = useState<ContextPreview | null>(
        null,
    );
    const [activationFingerprint, setActivationFingerprint] = useState<
        string | null
    >(null);
    const [activationPreviews, setActivationPreviews] = useState<
        ContextPreview[]
    >([]);
    const [hasBlockingConflicts, setHasBlockingConflicts] = useState(false);
    const [contextProcess, setContextProcess] = useState(
        assignment.applies_to_process === 'both'
            ? 'calculation'
            : assignment.applies_to_process,
    );
    const [contextScope, setContextScope] = useState(
        assignment.target_layer === 'global' ? 'header' : 'position',
    );

    const selectedFieldSet = useMemo(
        () =>
            formOptions.fieldSets.find(
                (set) => set.id.toString() === fieldSetId,
            ),
        [fieldSetId, formOptions.fieldSets],
    );

    const processChoices = useMemo(() => {
        if (!selectedFieldSet) {
            return formOptions.processOptions;
        }
        if (selectedFieldSet.applies_to === 'both') {
            return formOptions.processOptions;
        }

        return formOptions.processOptions.filter(
            (option) => option.value === selectedFieldSet.applies_to,
        );
    }, [formOptions.processOptions, selectedFieldSet]);

    function resetMessages() {
        setError(null);
        setSuccess(null);
        setFieldErrors({});
    }

    function applyAssignmentState(next: {
        lock_version: number;
        is_active: boolean;
        field_set_id?: number;
        target_layer?: string;
        advertising_category_id?: number | null;
        advertising_medium_id?: number | null;
        applies_to_process?: string;
        sort?: number;
    }) {
        setLockVersion(next.lock_version);
        setIsActive(next.is_active);
        if (next.field_set_id !== undefined) {
            setFieldSetId(next.field_set_id.toString());
        }
        if (next.target_layer !== undefined) {
            setTargetLayer(next.target_layer);
        }
        if (next.advertising_category_id !== undefined) {
            setCategoryId(next.advertising_category_id?.toString() ?? '');
        }
        if (next.advertising_medium_id !== undefined) {
            setMediumId(next.advertising_medium_id?.toString() ?? '');
        }
        if (next.applies_to_process !== undefined) {
            setProcess(next.applies_to_process);
        }
        if (next.sort !== undefined) {
            setSort(next.sort.toString());
        }
    }

    async function saveStructural(event: React.FormEvent) {
        event.preventDefault();
        if (busy || isActive) {
            return;
        }

        setBusy(true);
        resetMessages();

        const payload: Record<string, unknown> = {
            lock_version: lockVersion,
            field_set_id: Number(fieldSetId),
            target_layer: targetLayer,
            applies_to_process: process,
            sort: Number(sort || 0),
            advertising_category_id: null,
            advertising_medium_id: null,
        };

        if (targetLayer === 'advertising_category') {
            payload.advertising_category_id = Number(categoryId);
        }
        if (targetLayer === 'advertising_medium') {
            payload.advertising_medium_id = Number(mediumId);
        }

        try {
            const result = await jsonPut<{
                assignment: AssignmentDetail & { lock_version: number };
            }>(routes.update, payload);
            applyAssignmentState(result.assignment);
            setSuccess('Assignment wurde gespeichert.');
            setActivationFingerprint(null);
            setActivationPreviews([]);
            router.reload({ only: ['assignment', 'formOptions'] });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setFieldErrors(caught.fieldErrors);
                setError(
                    caught.isConflict
                        ? `${caught.message} Bitte Seite neu laden und erneut speichern.`
                        : caught.message ||
                              'Das Assignment konnte nicht gespeichert werden.',
                );
            } else {
                setError('Das Assignment konnte nicht gespeichert werden.');
            }
        } finally {
            setBusy(false);
        }
    }

    async function loadContextPreview() {
        if (busy) {
            return;
        }

        setBusy(true);
        resetMessages();

        const payload: Record<string, unknown> = {
            process: contextProcess,
            scope: contextScope,
            candidate_assignment_id: assignment.id,
            strict: true,
        };

        if (contextScope === 'position') {
            if (assignment.target_layer === 'advertising_category') {
                payload.advertising_category_id =
                    assignment.advertising_category_id;
            }
            if (assignment.target_layer === 'advertising_medium') {
                payload.advertising_medium_id =
                    assignment.advertising_medium_id;
            }
        }

        try {
            const result = await jsonPost<{ preview: ContextPreview }>(
                routes.contextPreview,
                payload,
            );
            setContextPreview(result.preview);
            setSuccess('Kontextvorschau aktualisiert.');
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.message ||
                        'Die Kontextvorschau konnte nicht geladen werden.',
                );
            } else {
                setError('Die Kontextvorschau konnte nicht geladen werden.');
            }
        } finally {
            setBusy(false);
        }
    }

    async function loadActivationPreview() {
        if (busy || isActive) {
            return;
        }

        setBusy(true);
        resetMessages();

        try {
            const result = await jsonPost<{
                fingerprint: string;
                previews: ContextPreview[];
                has_blocking_conflicts: boolean;
            }>(routes.activationPreview, {});
            setActivationFingerprint(result.fingerprint);
            setActivationPreviews(result.previews);
            setHasBlockingConflicts(result.has_blocking_conflicts);
            setSuccess(
                result.has_blocking_conflicts
                    ? 'Aktivierungsvorschau geladen – Konflikte blockieren die Aktivierung.'
                    : 'Aktivierungsvorschau geladen – konfliktfrei.',
            );
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.message ||
                        'Die Aktivierungsvorschau konnte nicht geladen werden.',
                );
            } else {
                setError(
                    'Die Aktivierungsvorschau konnte nicht geladen werden.',
                );
            }
        } finally {
            setBusy(false);
        }
    }

    async function activateAssignment() {
        if (
            busy ||
            isActive ||
            !activationFingerprint ||
            hasBlockingConflicts
        ) {
            return;
        }

        setBusy(true);
        resetMessages();

        try {
            const result = await jsonPost<{
                assignment: AssignmentDetail & { lock_version: number };
            }>(routes.activate, {
                lock_version: lockVersion,
                fingerprint: activationFingerprint,
            });
            applyAssignmentState(result.assignment);
            setSuccess('Assignment wurde aktiviert.');
            setActivationFingerprint(null);
            setActivationPreviews([]);
            router.reload({ only: ['assignment', 'formOptions'] });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                const conflictMessages = caught.fieldErrors.conflicts ?? [];
                setFieldErrors(caught.fieldErrors);
                setError(
                    caught.isConflict
                        ? `${caught.message} Bitte Aktivierungsvorschau erneut laden.`
                        : conflictMessages[0] ||
                              caught.message ||
                              'Aktivierung fehlgeschlagen.',
                );
                if (caught.isConflict) {
                    setActivationFingerprint(null);
                }
            } else {
                setError('Aktivierung fehlgeschlagen.');
            }
        } finally {
            setBusy(false);
        }
    }

    async function deactivateAssignment() {
        if (busy || !isActive) {
            return;
        }

        if (
            !window.confirm(
                'Assignment wirklich deaktivieren? Neue Vorgänge nutzen es danach nicht mehr; historische Snapshots bleiben unverändert.',
            )
        ) {
            return;
        }

        setBusy(true);
        resetMessages();

        try {
            const result = await jsonPost<{
                assignment: AssignmentDetail & { lock_version: number };
            }>(routes.deactivate, {
                lock_version: lockVersion,
            });
            applyAssignmentState(result.assignment);
            setSuccess('Assignment wurde deaktiviert.');
            router.reload({ only: ['assignment', 'formOptions'] });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? `${caught.message} Bitte Seite neu laden.`
                        : caught.message || 'Deaktivierung fehlgeschlagen.',
                );
            } else {
                setError('Deaktivierung fehlgeschlagen.');
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Assignment #${assignment.id}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={
                        assignment.field_set_name ??
                        assignment.field_set_key ??
                        `Assignment #${assignment.id}`
                    }
                    description={`${assignment.target_layer_label} · ${assignment.target_label} · ${assignment.applies_to_process_label} · Sperrversion ${lockVersion}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={routes.index}
                                    data-test="assignment-back-link"
                                >
                                    Zur Liste
                                </Link>
                            </Button>
                            {isActive ? (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={deactivateAssignment}
                                    data-test="assignment-deactivate-button"
                                >
                                    Deaktivieren
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <span
                        className={
                            isActive
                                ? 'rounded-md bg-emerald-50 px-2 py-1 text-sm font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                                : 'bg-muted text-muted-foreground rounded-md px-2 py-1 text-sm font-medium'
                        }
                        data-test="assignment-status-badge"
                    >
                        {isActive ? 'Aktiv' : 'Inaktiv'}
                    </span>
                    {!assignment.target_is_selectable ? (
                        <span className="text-muted-foreground text-sm">
                            Ziel ist historisch/deaktiviert und bleibt lesbar.
                        </span>
                    ) : null}
                </div>

                <p className="text-muted-foreground max-w-3xl text-sm">
                    {catalogNote}
                </p>

                {error ? (
                    <ErrorState
                        message={error}
                        data-test="assignment-show-error"
                    />
                ) : null}
                {success ? <SuccessState message={success} /> : null}

                {isActive ? (
                    <div
                        className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
                        data-test="assignment-active-lock-note"
                    >
                        Aktive Assignments sind strukturell gesperrt. Zum
                        Bearbeiten zuerst deaktivieren, anschließend erneut
                        aktivieren.
                    </div>
                ) : (
                    <form
                        className="grid max-w-2xl gap-4 rounded-xl border p-4"
                        onSubmit={saveStructural}
                        data-test="assignment-edit-form"
                    >
                        <h2 className="text-base font-semibold">
                            Inaktives Assignment bearbeiten
                        </h2>

                        <FormField
                            label="Freies Feldset"
                            htmlFor="field_set_id"
                            error={firstFieldError(fieldErrors, 'field_set_id')}
                        >
                            <select
                                id="field_set_id"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={fieldSetId}
                                onChange={(event) =>
                                    setFieldSetId(event.target.value)
                                }
                                data-test="assignment-edit-fieldset-select"
                            >
                                {formOptions.fieldSets.map((set) => (
                                    <option key={set.id} value={set.id}>
                                        {set.name} ({set.key})
                                    </option>
                                ))}
                            </select>
                        </FormField>

                        <FormField
                            label="Zielebene"
                            htmlFor="target_layer"
                            error={firstFieldError(fieldErrors, 'target_layer')}
                        >
                            <select
                                id="target_layer"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={targetLayer}
                                onChange={(event) =>
                                    setTargetLayer(event.target.value)
                                }
                                data-test="assignment-edit-target-layer-select"
                            >
                                {formOptions.targetLayerOptions.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </FormField>

                        {targetLayer === 'advertising_category' ? (
                            <FormField
                                label="Oberkategorie"
                                htmlFor="advertising_category_id"
                                error={firstFieldError(
                                    fieldErrors,
                                    'advertising_category_id',
                                )}
                            >
                                <select
                                    id="advertising_category_id"
                                    className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                    value={categoryId}
                                    onChange={(event) =>
                                        setCategoryId(event.target.value)
                                    }
                                    data-test="assignment-edit-category-select"
                                >
                                    {formOptions.categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                        ) : null}

                        {targetLayer === 'advertising_medium' ? (
                            <FormField
                                label="Werbemittel"
                                htmlFor="advertising_medium_id"
                                error={firstFieldError(
                                    fieldErrors,
                                    'advertising_medium_id',
                                )}
                            >
                                <select
                                    id="advertising_medium_id"
                                    className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                    value={mediumId}
                                    onChange={(event) =>
                                        setMediumId(event.target.value)
                                    }
                                    data-test="assignment-edit-medium-select"
                                >
                                    {formOptions.media.map((medium) => (
                                        <option
                                            key={medium.id}
                                            value={medium.id}
                                        >
                                            {medium.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                        ) : null}

                        <FormField
                            label="Prozessbezug"
                            htmlFor="applies_to_process"
                            error={firstFieldError(
                                fieldErrors,
                                'applies_to_process',
                            )}
                        >
                            <select
                                id="applies_to_process"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={process}
                                onChange={(event) =>
                                    setProcess(event.target.value)
                                }
                                data-test="assignment-edit-process-select"
                            >
                                {processChoices.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </FormField>

                        <FormField
                            label="Sortierung"
                            htmlFor="sort"
                            error={firstFieldError(fieldErrors, 'sort')}
                        >
                            <Input
                                id="sort"
                                type="number"
                                min={0}
                                value={sort}
                                onChange={(event) =>
                                    setSort(event.target.value)
                                }
                                data-test="assignment-edit-sort-input"
                            />
                        </FormField>

                        <Button
                            type="submit"
                            disabled={busy}
                            data-test="assignment-edit-submit"
                        >
                            {busy ? 'Speichern…' : 'Änderungen speichern'}
                        </Button>
                    </form>
                )}

                <section className="space-y-4 rounded-xl border p-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold">
                                Kontextvorschau
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Zeigt Herkunft und Konflikte für einen gewählten
                                Prozess/Scope anhand der Server-Preview.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={loadContextPreview}
                            data-test="assignment-context-preview-button"
                        >
                            Kontextvorschau laden
                        </Button>
                    </div>

                    <div className="grid gap-3 md:grid-cols-2">
                        <FormField label="Prozess" htmlFor="context_process">
                            <select
                                id="context_process"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={contextProcess}
                                onChange={(event) =>
                                    setContextProcess(event.target.value)
                                }
                                data-test="assignment-context-process-select"
                            >
                                <option value="calculation">Kalkulation</option>
                                <option value="dispo_order">
                                    Dispoauftrag
                                </option>
                            </select>
                        </FormField>
                        <FormField label="Scope" htmlFor="context_scope">
                            <select
                                id="context_scope"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={contextScope}
                                onChange={(event) =>
                                    setContextScope(event.target.value)
                                }
                                data-test="assignment-context-scope-select"
                            >
                                <option value="header">Kopf</option>
                                <option value="position">Position</option>
                            </select>
                        </FormField>
                    </div>

                    {contextPreview ? (
                        <PreviewPanel
                            title="Kontextvorschau"
                            preview={contextPreview}
                            dataTest="assignment-context-preview"
                        />
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Noch keine Kontextvorschau geladen.
                        </p>
                    )}
                </section>

                <section className="space-y-4 rounded-xl border p-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold">
                                Aktivierung
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Zuerst Vorschau laden, Konflikte prüfen, dann
                                mit aktuellem Fingerprint und Sperrversion
                                aktivieren.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || isActive}
                                onClick={loadActivationPreview}
                                data-test="assignment-activation-preview-button"
                            >
                                Aktivierungsvorschau
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    busy ||
                                    isActive ||
                                    !activationFingerprint ||
                                    hasBlockingConflicts
                                }
                                onClick={activateAssignment}
                                data-test="assignment-activate-button"
                            >
                                Aktivieren
                            </Button>
                        </div>
                    </div>

                    {activationFingerprint ? (
                        <p
                            className="font-mono text-xs break-all"
                            data-test="assignment-activation-fingerprint"
                        >
                            Aktivierungs-Fingerprint: {activationFingerprint}
                        </p>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Noch keine Aktivierungsvorschau geladen.
                        </p>
                    )}

                    {hasBlockingConflicts ? (
                        <ErrorState
                            message="Blockierende Konflikte verhindern die Aktivierung. Bitte Overrides oder Zuordnungen bereinigen."
                            data-test="assignment-activation-blocked"
                        />
                    ) : null}

                    <div className="space-y-4">
                        {activationPreviews.map((preview, index) => (
                            <PreviewPanel
                                key={`${preview.process}-${preview.scope}-${index}`}
                                title={`Betroffener Kontext ${index + 1}`}
                                preview={preview}
                                dataTest={`assignment-activation-preview-${index}`}
                            />
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
