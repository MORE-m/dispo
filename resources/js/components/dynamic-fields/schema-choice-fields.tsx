import { FormField } from '@/components/form-field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CHOICE_SEARCH_MIN_OPTIONS,
    MULTI_SELECT_MAX,
    SELECT_CLEAR_VALUE,
    type ChoiceOption,
    type ChoiceSchemaIssue,
    type ChoiceValue,
    type SchemaChoiceField,
    choiceSchemaIssueMessage,
    diagnoseChoiceField,
    filterOptionsBySearch,
    optionByKey,
    selectableSelectOptions,
    sortChoiceOptions,
} from '@/lib/choice-field-values';
import { cn } from '@/lib/utils';
import { useMemo, useState } from 'react';

export type {
    ChoiceOption,
    ChoiceValue,
    SchemaChoiceField,
} from '@/lib/choice-field-values';

export {
    customHeaderChoiceFieldsFromSchema,
    customPositionChoiceFieldsFromSchema,
    visibleChoiceFields,
} from '@/lib/choice-field-values';

type Props = {
    fields: SchemaChoiceField[];
    values: Record<string, ChoiceValue>;
    onChange: (key: string, value: ChoiceValue) => void;
    errors?: Record<string, string | string[]>;
    errorKeyPrefixes?: string[];
    disabled?: boolean;
    idPrefix?: string;
};

function firstError(
    errors: Record<string, string | string[]>,
    ...keys: string[]
): string | undefined {
    for (const key of keys) {
        const value = errors[key];
        if (typeof value === 'string' && value !== '') {
            return value;
        }
        if (Array.isArray(value) && value[0]) {
            return value[0];
        }
    }

    return undefined;
}

function SchemaIntegrityNotice({
    issue,
    testId,
}: {
    issue: ChoiceSchemaIssue;
    testId: string;
}) {
    return (
        <div
            className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            role="alert"
            data-test={testId}
        >
            {choiceSchemaIssueMessage(issue)}
        </div>
    );
}

function SchemaSelectField({
    field,
    value,
    onChange,
    error,
    disabled,
    id,
    idPrefix,
}: {
    field: SchemaChoiceField;
    value: string | null;
    onChange: (next: string | null) => void;
    error?: string;
    disabled: boolean;
    id: string;
    idPrefix: string;
}) {
    const options = field.options_json ?? [];
    const selectable = selectableSelectOptions(options, value);
    const current = optionByKey(options, value);
    const inactiveSelected = current !== undefined && !current.is_active;
    const label = field.required ? `${field.label} *` : field.label;
    const selectValue = value ?? SELECT_CLEAR_VALUE;

    return (
        <FormField
            label={label}
            htmlFor={id}
            error={error}
            hint={field.help_text ?? undefined}
        >
            <Select
                value={selectValue}
                disabled={disabled}
                onValueChange={(next) => {
                    if (next === SELECT_CLEAR_VALUE) {
                        onChange(null);

                        return;
                    }
                    onChange(next);
                }}
            >
                <SelectTrigger
                    id={id}
                    className="h-9 w-full max-w-full"
                    data-test={`${idPrefix}-${field.key}`}
                    aria-invalid={error ? true : undefined}
                >
                    <SelectValue placeholder="Keine Auswahl" />
                </SelectTrigger>
                <SelectContent
                    className="max-h-72 w-[var(--radix-select-trigger-width)]"
                    data-test={`${idPrefix}-${field.key}-content`}
                >
                    <SelectItem
                        value={SELECT_CLEAR_VALUE}
                        data-test={`${idPrefix}-${field.key}-clear`}
                    >
                        Keine Auswahl
                    </SelectItem>
                    {selectable.map((option) => {
                        const inactive =
                            !option.is_active && option.key === value;

                        return (
                            <SelectItem
                                key={option.key}
                                value={option.key}
                                data-test={`${idPrefix}-${field.key}-option-${option.key}`}
                            >
                                <span className="flex items-center gap-2">
                                    <span>{option.label}</span>
                                    {inactive ? (
                                        <span
                                            className="text-muted-foreground text-xs"
                                            data-test={`${idPrefix}-${field.key}-inactive-badge`}
                                        >
                                            Nicht mehr auswählbar
                                        </span>
                                    ) : null}
                                </span>
                            </SelectItem>
                        );
                    })}
                </SelectContent>
            </Select>
            {inactiveSelected ? (
                <p
                    className="text-muted-foreground text-xs"
                    data-test={`${idPrefix}-${field.key}-inactive-hint`}
                >
                    Aktueller Wert ist inaktiv und nicht erneut auswählbar,
                    sobald eine aktive Option gewählt wird.
                </p>
            ) : null}
        </FormField>
    );
}

function SchemaMultiSelectField({
    field,
    value,
    onChange,
    error,
    disabled,
    id,
    idPrefix,
}: {
    field: SchemaChoiceField;
    value: string[];
    onChange: (next: string[]) => void;
    error?: string;
    disabled: boolean;
    id: string;
    idPrefix: string;
}) {
    const [query, setQuery] = useState('');
    const options = useMemo(
        () => sortChoiceOptions(field.options_json ?? []),
        [field.options_json],
    );
    const selected = useMemo(() => new Set(value), [value]);
    const showSearch = options.length >= CHOICE_SEARCH_MIN_OPTIONS;
    const filtered = useMemo(
        () => (showSearch ? filterOptionsBySearch(options, query) : options),
        [options, query, showSearch],
    );
    const label = field.required ? `${field.label} *` : field.label;
    const atLimit = value.length >= MULTI_SELECT_MAX;
    const searchId = `${id}-search`;

    const toggle = (option: ChoiceOption, checked: boolean) => {
        if (disabled) {
            return;
        }

        if (!checked) {
            onChange(value.filter((key) => key !== option.key));

            return;
        }

        if (!option.is_active) {
            return;
        }
        if (selected.has(option.key)) {
            return;
        }
        if (value.length >= MULTI_SELECT_MAX) {
            return;
        }

        onChange([...value, option.key]);
    };

    return (
        <FormField
            label={label}
            htmlFor={showSearch ? searchId : id}
            error={error}
            hint={field.help_text ?? undefined}
        >
            <div
                className="border-input bg-background space-y-3 rounded-md border p-3 shadow-xs"
                data-test={`${idPrefix}-${field.key}`}
            >
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span
                        className="text-muted-foreground"
                        data-test={`${idPrefix}-${field.key}-count`}
                    >
                        {value.length} ausgewählt
                    </span>
                    <span
                        className={cn(
                            'text-xs',
                            atLimit
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        )}
                        data-test={`${idPrefix}-${field.key}-limit`}
                    >
                        Maximal {MULTI_SELECT_MAX} Werte
                    </span>
                </div>
                {showSearch ? (
                    <div className="space-y-1.5">
                        <label
                            htmlFor={searchId}
                            className="text-muted-foreground text-xs font-medium"
                        >
                            Optionen durchsuchen
                        </label>
                        <Input
                            id={searchId}
                            value={query}
                            disabled={disabled}
                            placeholder="Label oder Schlüssel"
                            data-test={`${idPrefix}-${field.key}-search`}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </div>
                ) : null}
                <div
                    className="max-h-56 space-y-1 overflow-y-auto pr-1"
                    data-test={`${idPrefix}-${field.key}-list`}
                    id={id}
                >
                    {filtered.length === 0 ? (
                        <p
                            className="text-muted-foreground py-4 text-center text-sm"
                            data-test={`${idPrefix}-${field.key}-empty`}
                        >
                            Keine Treffer
                        </p>
                    ) : (
                        filtered.map((option) => {
                            const isChecked = selected.has(option.key);
                            const inactive = !option.is_active;
                            // Inactive + nicht ausgewählt → disabled.
                            // Inactive + ausgewählt → Entfernung muss möglich bleiben.
                            const optionDisabled =
                                disabled || (inactive && !isChecked);
                            const addBlocked =
                                !isChecked && option.is_active && atLimit;

                            return (
                                <label
                                    key={option.key}
                                    className={cn(
                                        'hover:bg-muted/50 flex cursor-pointer items-start gap-3 rounded-md px-2 py-2 text-sm',
                                        optionDisabled &&
                                            'cursor-not-allowed opacity-60',
                                        inactive && 'text-muted-foreground',
                                    )}
                                    data-test={`${idPrefix}-${field.key}-option-${option.key}`}
                                >
                                    <Checkbox
                                        checked={isChecked}
                                        disabled={optionDisabled || addBlocked}
                                        className="mt-0.5"
                                        data-test={`${idPrefix}-${field.key}-check-${option.key}`}
                                        onCheckedChange={(state) =>
                                            toggle(option, state === true)
                                        }
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium">
                                            {option.label}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">
                                            {option.key}
                                            {inactive ? (
                                                <>
                                                    {' · '}
                                                    <span
                                                        data-test={`${idPrefix}-${field.key}-inactive-badge-${option.key}`}
                                                    >
                                                        Nicht mehr auswählbar
                                                    </span>
                                                </>
                                            ) : null}
                                            {addBlocked ? (
                                                <>
                                                    {' · '}
                                                    Limit erreicht
                                                </>
                                            ) : null}
                                        </span>
                                    </span>
                                </label>
                            );
                        })
                    )}
                </div>
            </div>
        </FormField>
    );
}

export function SchemaChoiceFields({
    fields,
    values,
    onChange,
    errors = {},
    errorKeyPrefixes = ['dynamic_field_values'],
    disabled = false,
    idPrefix = 'schema-choice',
}: Props) {
    // RULE-B: Caller liefert bereits effektiv sichtbare Felder (keine Basis-visible-Filterung hier).
    const sorted = [...fields].sort(
        (a, b) => (a.sort ?? 0) - (b.sort ?? 0) || a.key.localeCompare(b.key),
    );

    if (sorted.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4" data-test={`${idPrefix}-root`}>
            {sorted.map((field) => {
                const id = `${idPrefix}-${field.key}`;
                const error = firstError(
                    errors,
                    ...errorKeyPrefixes.map(
                        (prefix) => `${prefix}.${field.key}`,
                    ),
                    field.key,
                );
                const rawValue = values[field.key];
                const issue = diagnoseChoiceField(
                    field,
                    rawValue ??
                        (field.field_type === 'multi_select' ? [] : null),
                );

                if (
                    issue &&
                    issue !== 'unknown_stored_key' &&
                    issue !== 'multi_over_limit'
                ) {
                    return (
                        <div key={field.key} className="space-y-2">
                            <p className="text-sm font-medium">
                                {field.required
                                    ? `${field.label} *`
                                    : field.label}
                            </p>
                            <SchemaIntegrityNotice
                                issue={issue}
                                testId={`${idPrefix}-${field.key}-integrity`}
                            />
                        </div>
                    );
                }

                if (field.field_type === 'select') {
                    const selectValue =
                        typeof rawValue === 'string' ? rawValue : null;

                    return (
                        <div key={field.key} className="space-y-2">
                            {issue ? (
                                <SchemaIntegrityNotice
                                    issue={issue}
                                    testId={`${idPrefix}-${field.key}-integrity`}
                                />
                            ) : null}
                            <SchemaSelectField
                                field={field}
                                value={selectValue}
                                error={error}
                                disabled={disabled}
                                id={id}
                                idPrefix={idPrefix}
                                onChange={(next) => onChange(field.key, next)}
                            />
                        </div>
                    );
                }

                const multiValue = Array.isArray(rawValue) ? rawValue : [];

                return (
                    <div key={field.key} className="space-y-2">
                        {issue ? (
                            <SchemaIntegrityNotice
                                issue={issue}
                                testId={`${idPrefix}-${field.key}-integrity`}
                            />
                        ) : null}
                        <SchemaMultiSelectField
                            field={field}
                            value={multiValue}
                            error={error}
                            disabled={disabled}
                            id={id}
                            idPrefix={idPrefix}
                            onChange={(next) => onChange(field.key, next)}
                        />
                    </div>
                );
            })}
        </div>
    );
}
