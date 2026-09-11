import { useEffect, useMemo, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonPost, jsonPut } from '@/lib/json-post';
import {
    applyLabelChange,
    buildLocalChangeSummary,
    canEditKey,
    canRemoveDraftRow,
    createEmptyDraft,
    hasZeroActiveWarning,
    parseStrictSortInput,
    rowsFromServer,
    toPayloadOptions,
    type DraftOptionRow,
    type OptionRow,
    type OptionsChangeSummary,
} from '@/lib/field-definition-options-draft';

type PreviewResponse = {
    lock_version: number;
    fingerprint: string;
    has_changes: boolean;
    options: OptionRow[];
    previous_options: OptionRow[];
    summary: OptionsChangeSummary;
};

type ReplaceResponse = {
    message: string;
    has_changes: boolean;
    lock_version: number;
    fingerprint: string;
    options: OptionRow[];
    current_revision: {
        id: number;
        revision: number;
        label: string;
        help_text: string | null;
        group_key: string | null;
        sort_default: number;
        reportable: boolean;
        validation_json?: { max_length?: number } | null;
    } | null;
    revisions: Array<{
        id: number;
        revision: number;
        label: string;
        help_text: string | null;
        group_key: string | null;
        sort_default: number;
        reportable: boolean;
        validation_json?: { max_length?: number } | null;
        created_at?: string | null;
    }>;
};

type Props = {
    lockVersion: number;
    initialOptions: OptionRow[];
    boundaryNote: string;
    routes: {
        optionsPreview: string;
        optionsReplace: string;
    };
    onApplied: (result: ReplaceResponse) => void;
};

function summaryLines(summary: OptionsChangeSummary): string[] {
    if (summary.unchanged) {
        return ['Keine Änderung'];
    }
    const lines: string[] = [];
    if (summary.added.length > 0) {
        lines.push(`Neu: ${summary.added.join(', ')}`);
    }
    if (summary.label_changed.length > 0) {
        lines.push(`Label geändert: ${summary.label_changed.join(', ')}`);
    }
    if (summary.sort_changed.length > 0) {
        lines.push(`Sortierung geändert: ${summary.sort_changed.join(', ')}`);
    }
    if (summary.deactivated.length > 0) {
        lines.push(`Deaktiviert: ${summary.deactivated.join(', ')}`);
    }
    if (summary.reactivated.length > 0) {
        lines.push(`Reaktiviert: ${summary.reactivated.join(', ')}`);
    }
    return lines;
}

export default function FieldDefinitionOptionsEditor({
    lockVersion,
    initialOptions,
    boundaryNote,
    routes,
    onApplied,
}: Props) {
    const [drafts, setDrafts] = useState<DraftOptionRow[]>(() =>
        rowsFromServer(initialOptions),
    );
    const [baseline, setBaseline] = useState<OptionRow[]>(initialOptions);
    const [currentLock, setCurrentLock] = useState(lockVersion);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [previewFocusEl, setPreviewFocusEl] = useState<HTMLDivElement | null>(
        null,
    );

    useEffect(() => {
        if (preview !== null && previewFocusEl !== null) {
            previewFocusEl.focus();
        }
    }, [preview, previewFocusEl]);

    const sortedDrafts = useMemo(() => {
        return [...drafts].sort((a, b) => {
            const sortA = parseStrictSortInput(a.sortInput).value ?? a.sort;
            const sortB = parseStrictSortInput(b.sortInput).value ?? b.sort;
            if (sortA !== sortB) {
                return sortA - sortB;
            }
            return a.key.localeCompare(b.key);
        });
    }, [drafts]);

    const zeroActive = hasZeroActiveWarning(drafts);

    function patchDraft(
        clientId: string,
        updater: (row: DraftOptionRow) => DraftOptionRow,
    ) {
        setPreview(null);
        setSuccess(null);
        setDrafts((prev) =>
            prev.map((row) => (row.clientId === clientId ? updater(row) : row)),
        );
    }

    function addOption() {
        const maxSort = drafts.reduce((max, row) => {
            const parsed = parseStrictSortInput(row.sortInput).value;
            return Math.max(max, parsed ?? row.sort);
        }, -1);
        setPreview(null);
        setSuccess(null);
        setDrafts((prev) => [...prev, createEmptyDraft(maxSort + 10)]);
    }

    function removeUnsaved(clientId: string) {
        setPreview(null);
        setSuccess(null);
        setDrafts((prev) => prev.filter((row) => row.clientId !== clientId));
    }

    async function runPreview() {
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setFieldErrors({});
        setSuccess(null);

        const { options, errors } = toPayloadOptions(drafts);
        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            setError('Bitte Eingabefehler in den Optionszeilen korrigieren.');
            setBusy(false);
            return;
        }

        try {
            const response = await jsonPost<PreviewResponse>(
                routes.optionsPreview,
                {
                    lock_version: currentLock,
                    options,
                },
            );
            setPreview(response);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                if (caught.isConflict) {
                    setError(
                        caught.message ||
                            'Die Daten wurden zwischenzeitlich geändert. Bitte die Seite neu laden.',
                    );
                } else {
                    setError(caught.message || 'Vorschau fehlgeschlagen.');
                    const mapped: Record<string, string> = {};
                    for (const [key, messages] of Object.entries(
                        caught.fieldErrors,
                    )) {
                        if (messages[0]) {
                            mapped[key] = messages[0];
                        }
                    }
                    setFieldErrors(mapped);
                }
            } else {
                setError('Vorschau fehlgeschlagen.');
            }
        } finally {
            setBusy(false);
        }
    }

    async function runApply() {
        if (busy || preview === null) {
            return;
        }
        setBusy(true);
        setError(null);
        setFieldErrors({});

        const { options, errors } = toPayloadOptions(drafts);
        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            setError('Bitte Eingabefehler in den Optionszeilen korrigieren.');
            setBusy(false);
            return;
        }

        try {
            const response = await jsonPut<ReplaceResponse>(
                routes.optionsReplace,
                {
                    lock_version: currentLock,
                    fingerprint: preview.fingerprint,
                    options,
                },
            );
            setCurrentLock(response.lock_version);
            setBaseline(response.options);
            setDrafts(rowsFromServer(response.options));
            setPreview(null);
            setSuccess(
                response.has_changes
                    ? response.message
                    : 'Keine Änderungen',
            );
            onApplied(response);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                if (caught.isConflict) {
                    setError(
                        caught.message ||
                            'Die Daten wurden zwischenzeitlich geändert. Bitte die Seite neu laden.',
                    );
                    setPreview(null);
                } else {
                    setError(caught.message || 'Übernehmen fehlgeschlagen.');
                    const mapped: Record<string, string> = {};
                    for (const [key, messages] of Object.entries(
                        caught.fieldErrors,
                    )) {
                        if (messages[0]) {
                            mapped[key] = messages[0];
                        }
                    }
                    setFieldErrors(mapped);
                }
            } else {
                setError('Übernehmen fehlgeschlagen.');
            }
        } finally {
            setBusy(false);
        }
    }

    const localSummary = useMemo(() => {
        const { options, errors } = toPayloadOptions(drafts);
        if (Object.keys(errors).length > 0) {
            return null;
        }
        return buildLocalChangeSummary(baseline, options);
    }, [drafts, baseline]);

    void localSummary;

    return (
        <section
            className="space-y-4 rounded-xl border p-4"
            data-test="field-definition-options-section"
            aria-labelledby="field-definition-options-heading"
        >
            <div>
                <h2
                    id="field-definition-options-heading"
                    className="text-base font-semibold"
                >
                    Auswahloptionen
                </h2>
                <p
                    className="text-muted-foreground mt-1 text-sm"
                    data-test="field-definition-options-boundary-note"
                >
                    {boundaryNote}
                </p>
                <p className="text-muted-foreground mt-1 text-sm">
                    Der Aktivitätsstatus einer Option ist unabhängig vom
                    Aktivitätsstatus der Felddefinition.
                </p>
            </div>

            {success ? <SuccessState message={success} /> : null}
            {error ? <ErrorState message={error} /> : null}

            {zeroActive ? (
                <div
                    className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
                    role="alert"
                    data-test="field-definition-options-zero-active-warning"
                >
                    Ohne aktive Option kann dieses Feld nicht über ein Feldset
                    aktiviert bzw. in einen neuen Snapshot eingefroren werden.
                </div>
            ) : null}

            {fieldErrors.options ? (
                <p className="text-destructive text-sm">{fieldErrors.options}</p>
            ) : null}
            {fieldErrors.definition ? (
                <p className="text-destructive text-sm">
                    {fieldErrors.definition}
                </p>
            ) : null}

            <ul className="space-y-3" data-test="field-definition-options-list">
                {sortedDrafts.map((row) => {
                    const keyError =
                        fieldErrors[`${row.clientId}.key`] ??
                        fieldErrors[`options.${sortedDrafts.indexOf(row)}.key`];
                    const labelError =
                        fieldErrors[`${row.clientId}.label`] ??
                        fieldErrors[
                            `options.${sortedDrafts.indexOf(row)}.label`
                        ];
                    const sortError =
                        row.sortError ??
                        fieldErrors[`${row.clientId}.sort`] ??
                        fieldErrors[
                            `options.${sortedDrafts.indexOf(row)}.sort`
                        ];

                    return (
                        <li
                            key={row.clientId}
                            className={
                                row.is_active
                                    ? 'space-y-3 rounded-lg border p-3'
                                    : 'bg-muted/30 space-y-3 rounded-lg border border-dashed p-3 opacity-80'
                            }
                            data-test={`field-definition-option-row-${row.key || row.clientId}`}
                            data-option-active={row.is_active ? '1' : '0'}
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={
                                        row.is_active ? 'secondary' : 'outline'
                                    }
                                    data-test={`field-definition-option-status-${row.key || row.clientId}`}
                                >
                                    {row.is_active
                                        ? 'Option aktiv'
                                        : 'Option inaktiv'}
                                </Badge>
                                {row.persisted ? (
                                    <span className="text-muted-foreground text-xs">
                                        gespeichert
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground text-xs">
                                        neu (ungespeichert)
                                    </span>
                                )}
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <FormField
                                    label="Label"
                                    htmlFor={`option-label-${row.clientId}`}
                                    error={labelError}
                                >
                                    <Input
                                        id={`option-label-${row.clientId}`}
                                        value={row.label}
                                        onChange={(e) =>
                                            patchDraft(row.clientId, (current) =>
                                                applyLabelChange(
                                                    current,
                                                    e.target.value,
                                                ),
                                            )
                                        }
                                        data-test={`field-definition-option-label-${row.key || row.clientId}`}
                                    />
                                </FormField>

                                <FormField
                                    label="Schlüssel"
                                    htmlFor={`option-key-${row.clientId}`}
                                    error={keyError}
                                    hint={
                                        canEditKey(row)
                                            ? 'Vorschlag aus Label; bis zum Speichern editierbar.'
                                            : 'Schlüssel unveränderlich'
                                    }
                                >
                                    <Input
                                        id={`option-key-${row.clientId}`}
                                        value={row.key}
                                        readOnly={!canEditKey(row)}
                                        className={
                                            canEditKey(row)
                                                ? undefined
                                                : 'font-mono'
                                        }
                                        onChange={(e) =>
                                            patchDraft(row.clientId, (current) => ({
                                                ...current,
                                                key: e.target.value,
                                                keyTouched: true,
                                            }))
                                        }
                                        data-test={`field-definition-option-key-${row.key || row.clientId}`}
                                    />
                                </FormField>

                                <FormField
                                    label="Sortierung"
                                    htmlFor={`option-sort-${row.clientId}`}
                                    error={sortError}
                                >
                                    <Input
                                        id={`option-sort-${row.clientId}`}
                                        inputMode="numeric"
                                        value={row.sortInput}
                                        onChange={(e) => {
                                            const raw = e.target.value;
                                            const parsed =
                                                parseStrictSortInput(raw);
                                            patchDraft(row.clientId, (current) => ({
                                                ...current,
                                                sortInput: raw,
                                                sort:
                                                    parsed.value ?? current.sort,
                                                sortError: parsed.error,
                                            }));
                                        }}
                                        data-test={`field-definition-option-sort-${row.key || row.clientId}`}
                                    />
                                </FormField>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                {row.is_active ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            patchDraft(row.clientId, (current) => ({
                                                ...current,
                                                is_active: false,
                                            }))
                                        }
                                        data-test={`field-definition-option-deactivate-${row.key || row.clientId}`}
                                    >
                                        Deaktivieren
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            patchDraft(row.clientId, (current) => ({
                                                ...current,
                                                is_active: true,
                                            }))
                                        }
                                        data-test={`field-definition-option-reactivate-${row.key || row.clientId}`}
                                    >
                                        Reaktivieren
                                    </Button>
                                )}
                                {canRemoveDraftRow(row) ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            removeUnsaved(row.clientId)
                                        }
                                        data-test={`field-definition-option-remove-${row.clientId}`}
                                    >
                                        Zeile entfernen
                                    </Button>
                                ) : null}
                            </div>
                        </li>
                    );
                })}
            </ul>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={busy || drafts.length >= 100}
                    onClick={addOption}
                    data-test="field-definition-options-add"
                >
                    Option hinzufügen
                </Button>
                <Button
                    type="button"
                    disabled={busy}
                    onClick={runPreview}
                    data-test="field-definition-options-preview-button"
                >
                    Änderungen prüfen
                </Button>
            </div>

            {preview ? (
                <div
                    className="space-y-3 rounded-lg border p-3"
                    data-test="field-definition-options-preview"
                    tabIndex={-1}
                    ref={setPreviewFocusEl}
                >
                    <h3 className="text-sm font-semibold">Vorschau</h3>
                    <ul
                        className="text-muted-foreground list-inside list-disc text-sm"
                        data-test="field-definition-options-preview-changes"
                    >
                        {summaryLines(preview.summary).map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-2">
                        {preview.has_changes ? (
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={runApply}
                                data-test="field-definition-options-preview-confirm"
                            >
                                Übernehmen
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={runApply}
                                data-test="field-definition-options-preview-noop"
                            >
                                Keine Änderungen
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setPreview(null)}
                            data-test="field-definition-options-preview-cancel"
                        >
                            Abbrechen
                        </Button>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
