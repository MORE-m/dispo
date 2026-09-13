import { useMemo, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { JsonPostError, jsonPost, jsonPut } from '@/lib/json-post';
import {
    allowedConditionOps,
    duplicateDraftRule,
    emptyAtomicCondition,
    emptyDraftRule,
    fieldLabel,
    hasRequireAndHiddenWarning,
    moveDraftRule,
    rulesFromServer,
    summarizeRule,
    toPayloadRules,
    type AtomicCondition,
    type DraftRule,
    type FieldCatalogEntry,
    type RuleAction,
    type RuleCondition,
    type ServerRule,
} from '@/lib/field-set-rules-draft';

type PreviewResponse = {
    lock_version: number;
    fingerprint: string;
    has_changes: boolean;
    rules: ServerRule[];
    matched: boolean[];
    warnings: Array<{ code: string; message: string; field_key?: string }>;
    effective_visible: {
        header: Record<string, boolean>;
        position: Record<string, boolean>;
    };
    effective_required: {
        header: Record<string, boolean>;
        position: Record<string, boolean>;
    };
    example_values: {
        header: Record<string, unknown>;
        position: Record<string, unknown>;
    };
};

type ReplaceResponse = {
    message: string;
    has_changes: boolean;
    lock_version: number;
    fingerprint: string;
    rules: ServerRule[];
};

type Props = {
    editable: boolean;
    lockVersion: number;
    fieldCatalog: FieldCatalogEntry[];
    initialRules: ServerRule[];
    routes: {
        preview: string;
        replace: string;
    };
    onLockVersionChange: (lockVersion: number) => void;
};

function conditionOpsForField(
    catalog: FieldCatalogEntry[],
    fieldKey: string,
): string[] {
    const entry = catalog.find((row) => row.key === fieldKey);
    return allowedConditionOps(entry?.field_type ?? '');
}

function updateAtomic(
    condition: AtomicCondition,
    patch: Partial<AtomicCondition> & { op?: string; field_key?: string },
    catalog: FieldCatalogEntry[],
): AtomicCondition {
    const fieldKey = patch.field_key ?? condition.field_key;
    const ops = conditionOpsForField(catalog, fieldKey);
    const op = (patch.op ?? condition.op) as AtomicCondition['op'];
    const nextOp = ops.includes(op) ? op : (ops[0] as AtomicCondition['op']);
    const entry = catalog.find((row) => row.key === fieldKey);

    if (nextOp === 'field_empty' || nextOp === 'field_not_empty') {
        return { op: nextOp, field_key: fieldKey };
    }
    if (nextOp === 'field_contains') {
        const firstActive =
            entry?.options.find((option) => option.is_active)?.key ?? '';
        return {
            op: 'field_contains',
            field_key: fieldKey,
            value:
                'value' in patch && typeof patch.value === 'string'
                    ? patch.value
                    : condition.op === 'field_contains'
                      ? condition.value
                      : firstActive,
        };
    }

    let value: unknown = true;
    if (entry?.field_type === 'boolean') {
        value =
            'value' in patch && typeof patch.value === 'boolean'
                ? patch.value
                : condition.op === 'field_equals' &&
                    typeof condition.value === 'boolean'
                  ? condition.value
                  : true;
    } else if (entry?.field_type === 'select') {
        const firstActive =
            entry.options.find((option) => option.is_active)?.key ?? '';
        value =
            'value' in patch && typeof patch.value === 'string'
                ? patch.value
                : condition.op === 'field_equals' &&
                    typeof condition.value === 'string'
                  ? condition.value
                  : firstActive;
    } else {
        value =
            'value' in patch && typeof patch.value === 'string'
                ? patch.value
                : condition.op === 'field_equals' &&
                    typeof condition.value === 'string'
                  ? condition.value
                  : '';
    }

    return { op: 'field_equals', field_key: fieldKey, value };
}

export default function FieldSetRulesEditor({
    editable,
    lockVersion,
    fieldCatalog,
    initialRules,
    routes,
    onLockVersionChange,
}: Props) {
    const [rules, setRules] = useState<DraftRule[]>(() =>
        rulesFromServer(initialRules),
    );
    const [editingIndex, setEditingIndex] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [success, setSuccess] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [fingerprint, setFingerprint] = useState<string | null>(null);

    const structuralWarnings = useMemo(
        () => hasRequireAndHiddenWarning(rules),
        [rules],
    );

    const defaultFieldKey = fieldCatalog[0]?.key ?? '';

    async function runPreview(): Promise<PreviewResponse | null> {
        setBusy(true);
        setError(null);
        setFieldErrors({});
        setSuccess(null);
        try {
            const result = await jsonPost<PreviewResponse>(routes.preview, {
                lock_version: lockVersion,
                rules: toPayloadRules(rules),
            });
            setPreview(result);
            setFingerprint(result.fingerprint);
            return result;
        } catch (err) {
            if (err instanceof JsonPostError) {
                setError(err.message);
                setFieldErrors(err.fieldErrors);
                if (err.isConflict) {
                    setFingerprint(null);
                }
            } else {
                setError('Die Regelvorschau ist fehlgeschlagen.');
            }
            return null;
        } finally {
            setBusy(false);
        }
    }

    async function applyRules(): Promise<void> {
        setBusy(true);
        setError(null);
        setFieldErrors({});
        setSuccess(null);
        try {
            let nextFingerprint = fingerprint;
            if (!nextFingerprint) {
                const previewResult = await jsonPost<PreviewResponse>(
                    routes.preview,
                    {
                        lock_version: lockVersion,
                        rules: toPayloadRules(rules),
                    },
                );
                setPreview(previewResult);
                nextFingerprint = previewResult.fingerprint;
                setFingerprint(nextFingerprint);
                if (!previewResult.has_changes) {
                    setSuccess('Keine Änderungen');
                    return;
                }
            }

            const result = await jsonPut<ReplaceResponse>(routes.replace, {
                lock_version: lockVersion,
                fingerprint: nextFingerprint,
                rules: toPayloadRules(rules),
            });
            onLockVersionChange(result.lock_version);
            setRules(rulesFromServer(result.rules));
            setFingerprint(result.fingerprint);
            setEditingIndex(null);
            setSuccess(result.message);
        } catch (err) {
            if (err instanceof JsonPostError) {
                setError(err.message);
                setFieldErrors(err.fieldErrors);
                if (err.isConflict) {
                    setFingerprint(null);
                }
            } else {
                setError('Das Speichern der Regeln ist fehlgeschlagen.');
            }
        } finally {
            setBusy(false);
        }
    }

    function updateRule(index: number, next: DraftRule): void {
        setRules((current) =>
            current.map((rule, ruleIndex) =>
                ruleIndex === index ? next : rule,
            ),
        );
        setFingerprint(null);
    }

    function renderConditionEditor(
        condition: RuleCondition,
        onChange: (next: RuleCondition) => void,
        disabled: boolean,
    ) {
        if (condition.op === 'all' || condition.op === 'any') {
            return (
                <div className="space-y-3 rounded-lg border p-3">
                    <label className="block space-y-1 text-sm">
                        <span className="font-medium">Verknüpfung</span>
                        <select
                            className="border-input bg-background w-full rounded-md border px-3 py-2"
                            disabled={disabled}
                            value={condition.op}
                            onChange={(event) =>
                                onChange({
                                    ...condition,
                                    op: event.target.value as 'all' | 'any',
                                })
                            }
                        >
                            <option value="all">Wenn alle Bedingungen</option>
                            <option value="any">
                                Wenn mindestens eine Bedingung
                            </option>
                        </select>
                    </label>
                    {condition.conditions.map((child, childIndex) => (
                        <div key={childIndex} className="space-y-2 border-t pt-3">
                            {renderAtomicEditor(
                                child,
                                (nextChild) => {
                                    const conditions = [
                                        ...condition.conditions,
                                    ];
                                    conditions[childIndex] = nextChild;
                                    onChange({ ...condition, conditions });
                                },
                                disabled,
                            )}
                            {!disabled && condition.conditions.length > 2 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const conditions =
                                            condition.conditions.filter(
                                                (_, index) =>
                                                    index !== childIndex,
                                            );
                                        onChange({ ...condition, conditions });
                                    }}
                                >
                                    Bedingung entfernen
                                </Button>
                            ) : null}
                        </div>
                    ))}
                    {!disabled && condition.conditions.length < 8 ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                onChange({
                                    ...condition,
                                    conditions: [
                                        ...condition.conditions,
                                        emptyAtomicCondition(defaultFieldKey),
                                    ],
                                })
                            }
                        >
                            Bedingung hinzufügen
                        </Button>
                    ) : null}
                </div>
            );
        }

        return (
            <div className="space-y-3">
                <label className="block space-y-1 text-sm">
                    <span className="font-medium">Bedingungsart</span>
                    <select
                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                        disabled={disabled}
                        value="atomic"
                        onChange={(event) => {
                            if (event.target.value === 'all') {
                                onChange({
                                    op: 'all',
                                    conditions: [
                                        emptyAtomicCondition(defaultFieldKey),
                                        emptyAtomicCondition(defaultFieldKey),
                                    ],
                                });
                            }
                            if (event.target.value === 'any') {
                                onChange({
                                    op: 'any',
                                    conditions: [
                                        emptyAtomicCondition(defaultFieldKey),
                                        emptyAtomicCondition(defaultFieldKey),
                                    ],
                                });
                            }
                        }}
                    >
                        <option value="atomic">Einzelne Bedingung</option>
                        <option value="all">Wenn alle Bedingungen</option>
                        <option value="any">
                            Wenn mindestens eine Bedingung
                        </option>
                    </select>
                </label>
                {renderAtomicEditor(condition, onChange, disabled)}
            </div>
        );
    }

    function renderAtomicEditor(
        condition: AtomicCondition,
        onChange: (next: AtomicCondition) => void,
        disabled: boolean,
    ) {
        const entry = fieldCatalog.find(
            (row) => row.key === condition.field_key,
        );
        const ops = conditionOpsForField(fieldCatalog, condition.field_key);

        return (
            <div className="grid gap-3 md:grid-cols-3">
                <label className="block space-y-1 text-sm">
                    <span className="font-medium">Feld</span>
                    <select
                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                        disabled={disabled}
                        value={condition.field_key}
                        onChange={(event) =>
                            onChange(
                                updateAtomic(
                                    condition,
                                    { field_key: event.target.value },
                                    fieldCatalog,
                                ),
                            )
                        }
                    >
                        {fieldCatalog.map((field) => (
                            <option key={field.key} value={field.key}>
                                {field.label} ({field.key}) · {field.scope}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block space-y-1 text-sm">
                    <span className="font-medium">Operator</span>
                    <select
                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                        disabled={disabled}
                        value={condition.op}
                        onChange={(event) =>
                            onChange(
                                updateAtomic(
                                    condition,
                                    { op: event.target.value },
                                    fieldCatalog,
                                ),
                            )
                        }
                    >
                        {ops.map((op) => (
                            <option key={op} value={op}>
                                {op === 'field_equals'
                                    ? 'entspricht'
                                    : op === 'field_empty'
                                      ? 'ist leer'
                                      : op === 'field_not_empty'
                                        ? 'ist nicht leer'
                                        : 'enthält Option'}
                            </option>
                        ))}
                    </select>
                </label>
                {condition.op === 'field_equals' ||
                condition.op === 'field_contains' ? (
                    <label className="block space-y-1 text-sm">
                        <span className="font-medium">Wert</span>
                        {entry?.field_type === 'boolean' ? (
                            <select
                                className="border-input bg-background w-full rounded-md border px-3 py-2"
                                disabled={disabled}
                                value={String(condition.value)}
                                onChange={(event) =>
                                    onChange(
                                        updateAtomic(
                                            condition,
                                            {
                                                value:
                                                    event.target.value ===
                                                    'true',
                                            },
                                            fieldCatalog,
                                        ),
                                    )
                                }
                            >
                                <option value="true">Ja</option>
                                <option value="false">Nein</option>
                            </select>
                        ) : entry?.field_type === 'select' ||
                          entry?.field_type === 'multi_select' ? (
                            <select
                                className="border-input bg-background w-full rounded-md border px-3 py-2"
                                disabled={disabled}
                                value={String(condition.value ?? '')}
                                onChange={(event) =>
                                    onChange(
                                        updateAtomic(
                                            condition,
                                            { value: event.target.value },
                                            fieldCatalog,
                                        ),
                                    )
                                }
                            >
                                {entry.options
                                    .filter((option) => option.is_active)
                                    .map((option) => (
                                        <option
                                            key={option.key}
                                            value={option.key}
                                        >
                                            {option.label} ({option.key})
                                        </option>
                                    ))}
                            </select>
                        ) : (
                            <Input
                                disabled={disabled}
                                value={String(condition.value ?? '')}
                                onChange={(event) =>
                                    onChange(
                                        updateAtomic(
                                            condition,
                                            { value: event.target.value },
                                            fieldCatalog,
                                        ),
                                    )
                                }
                            />
                        )}
                    </label>
                ) : (
                    <div />
                )}
            </div>
        );
    }

    function renderActionEditor(
        action: RuleAction,
        onChange: (next: RuleAction) => void,
        disabled: boolean,
    ) {
        return (
            <div className="grid gap-3 md:grid-cols-3">
                <label className="block space-y-1 text-sm">
                    <span className="font-medium">Aktion</span>
                    <select
                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                        disabled={disabled}
                        value={action.op}
                        onChange={(event) => {
                            const op = event.target.value;
                            if (op === 'set_visible') {
                                onChange({
                                    op: 'set_visible',
                                    field_key: action.field_key,
                                    value: true,
                                });
                            } else {
                                onChange({
                                    op: 'require_field',
                                    field_key: action.field_key,
                                });
                            }
                        }}
                    >
                        <option value="require_field">Als Pflicht setzen</option>
                        <option value="set_visible">
                            Sichtbarkeit setzen
                        </option>
                    </select>
                </label>
                <label className="block space-y-1 text-sm">
                    <span className="font-medium">Zielfeld</span>
                    <select
                        className="border-input bg-background w-full rounded-md border px-3 py-2"
                        disabled={disabled}
                        value={action.field_key}
                        onChange={(event) =>
                            onChange(
                                action.op === 'set_visible'
                                    ? {
                                          ...action,
                                          field_key: event.target.value,
                                      }
                                    : {
                                          op: 'require_field',
                                          field_key: event.target.value,
                                      },
                            )
                        }
                    >
                        {fieldCatalog.map((field) => (
                            <option key={field.key} value={field.key}>
                                {field.label} ({field.key}) · {field.scope}
                            </option>
                        ))}
                    </select>
                </label>
                {action.op === 'set_visible' ? (
                    <label className="block space-y-1 text-sm">
                        <span className="font-medium">Sichtbarkeit</span>
                        <select
                            className="border-input bg-background w-full rounded-md border px-3 py-2"
                            disabled={disabled}
                            value={String(action.value)}
                            onChange={(event) =>
                                onChange({
                                    ...action,
                                    value: event.target.value === 'true',
                                })
                            }
                        >
                            <option value="true">Sichtbar</option>
                            <option value="false">Unsichtbar</option>
                        </select>
                    </label>
                ) : (
                    <div />
                )}
            </div>
        );
    }

    return (
        <section className="space-y-4" data-test="fieldset-rules-editor">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold">Regeln</h2>
                    <p className="text-muted-foreground text-sm">
                        {editable
                            ? 'Desired-State für diesen Entwurf. Sortierung über Nach oben / Nach unten.'
                            : 'Nur Lesen. Zum Ändern bitte einen Entwurf anlegen.'}
                    </p>
                </div>
                {editable ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy || fieldCatalog.length === 0}
                            data-test="fieldset-rules-add"
                            onClick={() => {
                                setRules((current) => [
                                    ...current,
                                    emptyDraftRule(defaultFieldKey),
                                ]);
                                setEditingIndex(rules.length);
                                setFingerprint(null);
                            }}
                        >
                            Regel hinzufügen
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            data-test="fieldset-rules-preview"
                            onClick={() => void runPreview()}
                        >
                            Vorschau
                        </Button>
                        <Button
                            type="button"
                            disabled={busy}
                            data-test="fieldset-rules-apply"
                            onClick={() => void applyRules()}
                        >
                            Regeln speichern
                        </Button>
                    </div>
                ) : null}
            </div>

            {error ? <ErrorState message={error} /> : null}
            {success ? <SuccessState message={success} /> : null}
            {Object.keys(fieldErrors).length > 0 ? (
                <ul className="text-destructive space-y-1 text-sm">
                    {Object.entries(fieldErrors).map(([key, messages]) => (
                        <li key={key}>
                            {key}: {messages.join(' ')}
                        </li>
                    ))}
                </ul>
            ) : null}
            {structuralWarnings.length > 0 ? (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                    Pflicht und Unsichtbarkeit betreffen dieselben Felder:{' '}
                    {structuralWarnings
                        .map((key) => fieldLabel(fieldCatalog, key))
                        .join(', ')}
                    . Die Pflicht greift nur bei sichtbaren Feldern.
                </div>
            ) : null}

            {rules.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Keine Regeln in dieser Version.
                </p>
            ) : (
                <ul className="space-y-3">
                    {rules.map((rule, index) => {
                        const isEditing = editable && editingIndex === index;
                        return (
                            <li
                                key={rule.localId}
                                className="space-y-3 rounded-xl border p-4"
                                data-test={`fieldset-rule-${index}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-medium">
                                                Regel {index + 1}
                                            </span>
                                            {rule.is_system_seed ? (
                                                <Badge variant="secondary">
                                                    Systemregel
                                                </Badge>
                                            ) : null}
                                            {preview?.matched?.[index] ===
                                            true ? (
                                                <Badge>Beispiel trifft zu</Badge>
                                            ) : null}
                                            {preview?.matched?.[index] ===
                                            false ? (
                                                <Badge variant="outline">
                                                    Beispiel trifft nicht zu
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <p className="text-sm">
                                            {summarizeRule(rule, fieldCatalog)}
                                        </p>
                                    </div>
                                    {editable ? (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    busy ||
                                                    rule.is_system_seed ||
                                                    index === 0 ||
                                                    rules[index - 1]
                                                        ?.is_system_seed
                                                }
                                                onClick={() => {
                                                    setRules((current) =>
                                                        moveDraftRule(
                                                            current,
                                                            index,
                                                            -1,
                                                        ),
                                                    );
                                                    setFingerprint(null);
                                                }}
                                            >
                                                Nach oben
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    busy ||
                                                    rule.is_system_seed ||
                                                    index >= rules.length - 1
                                                }
                                                onClick={() => {
                                                    setRules((current) =>
                                                        moveDraftRule(
                                                            current,
                                                            index,
                                                            1,
                                                        ),
                                                    );
                                                    setFingerprint(null);
                                                }}
                                            >
                                                Nach unten
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    busy || rule.is_system_seed
                                                }
                                                onClick={() =>
                                                    setEditingIndex(
                                                        isEditing
                                                            ? null
                                                            : index,
                                                    )
                                                }
                                            >
                                                {isEditing
                                                    ? 'Schließen'
                                                    : 'Bearbeiten'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    busy || rule.is_system_seed
                                                }
                                                onClick={() => {
                                                    setRules((current) => {
                                                        const next = [
                                                            ...current,
                                                        ];
                                                        next.splice(
                                                            index + 1,
                                                            0,
                                                            duplicateDraftRule(
                                                                rule,
                                                            ),
                                                        );
                                                        return next;
                                                    });
                                                    setFingerprint(null);
                                                    setEditingIndex(index + 1);
                                                }}
                                            >
                                                Duplizieren
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    busy || rule.is_system_seed
                                                }
                                                onClick={() => {
                                                    setRules((current) =>
                                                        current.filter(
                                                            (_, ruleIndex) =>
                                                                ruleIndex !==
                                                                index,
                                                        ),
                                                    );
                                                    setEditingIndex(null);
                                                    setFingerprint(null);
                                                }}
                                            >
                                                Entfernen
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>

                                {isEditing || rule.is_system_seed ? (
                                    <div className="space-y-4">
                                        {rule.is_system_seed ? (
                                            <p className="text-muted-foreground text-sm">
                                                Geschützte Systemregel. Löschen,
                                                Bearbeiten, Duplizieren und
                                                Umsortieren sind nicht erlaubt.
                                            </p>
                                        ) : null}
                                        {renderConditionEditor(
                                            rule.condition,
                                            (condition) =>
                                                updateRule(index, {
                                                    ...rule,
                                                    condition,
                                                }),
                                            !editable || rule.is_system_seed,
                                        )}
                                        {renderActionEditor(
                                            rule.action,
                                            (action) =>
                                                updateRule(index, {
                                                    ...rule,
                                                    action,
                                                }),
                                            !editable || rule.is_system_seed,
                                        )}
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}

            {preview ? (
                <div
                    className="space-y-3 rounded-xl border p-4"
                    data-test="fieldset-rules-preview-result"
                >
                    <h3 className="text-sm font-semibold">Regelvorschau</h3>
                    {preview.warnings.length > 0 ? (
                        <ul className="space-y-1 text-sm text-amber-900">
                            {preview.warnings.map((warning, index) => (
                                <li key={`${warning.code}-${index}`}>
                                    {warning.message}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <p className="mb-2 text-sm font-medium">
                                Effektive Sichtbarkeit
                            </p>
                            <ul className="text-muted-foreground space-y-1 text-xs">
                                {Object.entries({
                                    ...preview.effective_visible.header,
                                    ...preview.effective_visible.position,
                                }).map(([key, visible]) => (
                                    <li key={`visible-${key}`}>
                                        {fieldLabel(fieldCatalog, key)}:{' '}
                                        {visible ? 'sichtbar' : 'unsichtbar'}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div>
                            <p className="mb-2 text-sm font-medium">
                                Effektive Pflicht
                            </p>
                            <ul className="text-muted-foreground space-y-1 text-xs">
                                {Object.entries({
                                    ...preview.effective_required.header,
                                    ...preview.effective_required.position,
                                }).map(([key, required]) => (
                                    <li key={`required-${key}`}>
                                        {fieldLabel(fieldCatalog, key)}:{' '}
                                        {required ? 'pflicht' : 'optional'}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
