/**
 * DF-3-REST-C2: Client-seitige Choice-Werthelfer (Select / Multi-Select).
 * Speichersemantik bleibt C1-serverseitig; hier nur UI-Init, Vergleich und Payload.
 */

export type ChoiceOption = {
    key: string;
    label: string;
    sort: number;
    is_active: boolean;
};

export type ChoiceFieldType = 'select' | 'multi_select';

export type SchemaChoiceField = {
    key: string;
    label: string;
    help_text?: string | null;
    field_type: ChoiceFieldType;
    required?: boolean;
    sort?: number;
    visible?: boolean;
    options_json: ChoiceOption[] | null;
};

export type ChoiceValue = string | null | string[];

export type ChoiceSchemaIssue =
    | 'missing_options'
    | 'invalid_options'
    | 'duplicate_option_keys'
    | 'invalid_value_type'
    | 'unknown_stored_key'
    | 'multi_over_limit';

export const MULTI_SELECT_MAX = 50;
export const CHOICE_SEARCH_MIN_OPTIONS = 10;
/** Radix-Select: explizites Leeren (Payload → null). */
export const SELECT_CLEAR_VALUE = '__dispo_choice_none__';

export function isChoiceFieldType(value: string): value is ChoiceFieldType {
    return value === 'select' || value === 'multi_select';
}

export function sortChoiceOptions(options: ChoiceOption[]): ChoiceOption[] {
    return [...options].sort(
        (a, b) => a.sort - b.sort || a.key.localeCompare(b.key),
    );
}

/**
 * Kanonische Multi-Liste: unique Keys, lexikographisch sortiert (wie C1).
 */
export function canonicalizeMultiKeys(keys: readonly string[]): string[] {
    return [...new Set(keys.filter((key) => key !== ''))].sort((a, b) =>
        a.localeCompare(b),
    );
}

export function multiKeysEqual(
    left: readonly string[],
    right: readonly string[],
): boolean {
    const a = canonicalizeMultiKeys(left);
    const b = canonicalizeMultiKeys(right);
    if (a.length !== b.length) {
        return false;
    }

    return a.every((key, index) => key === b[index]);
}

export function selectValuesEqual(
    left: string | null,
    right: string | null,
): boolean {
    return (left ?? null) === (right ?? null);
}

type SchemaSourceField = {
    key: string;
    label: string;
    help_text?: string | null;
    field_type: string;
    scope?: string;
    sort?: number;
    is_system?: boolean;
    required?: boolean;
    visible?: boolean;
    options_json?: unknown;
};

function parseOptionsJson(raw: unknown): {
    options: ChoiceOption[] | null;
    issue: ChoiceSchemaIssue | null;
} {
    if (raw === null || raw === undefined) {
        return { options: null, issue: 'missing_options' };
    }

    if (!Array.isArray(raw)) {
        return { options: null, issue: 'invalid_options' };
    }

    const options: ChoiceOption[] = [];
    const seen = new Set<string>();

    for (const row of raw) {
        if (row === null || typeof row !== 'object' || Array.isArray(row)) {
            return { options: null, issue: 'invalid_options' };
        }

        const record = row as Record<string, unknown>;
        const key = record.key;
        const label = record.label;
        const sort = record.sort;
        const isActive = record.is_active;

        if (typeof key !== 'string' || key === '') {
            return { options: null, issue: 'invalid_options' };
        }
        if (typeof label !== 'string') {
            return { options: null, issue: 'invalid_options' };
        }
        if (typeof sort !== 'number' || !Number.isFinite(sort)) {
            return { options: null, issue: 'invalid_options' };
        }
        if (typeof isActive !== 'boolean') {
            return { options: null, issue: 'invalid_options' };
        }
        if (seen.has(key)) {
            return { options: null, issue: 'duplicate_option_keys' };
        }

        seen.add(key);
        options.push({
            key,
            label,
            sort,
            is_active: isActive,
        });
    }

    return { options, issue: null };
}

/**
 * Alle Choice-Felder eines Scopes inkl. unsichtbarer (State behalten).
 */
export function choiceFieldsFromSchema(
    fields: SchemaSourceField[],
    scope: 'header' | 'position',
): SchemaChoiceField[] {
    return fields
        .filter(
            (field) =>
                field.is_system !== true &&
                (field.scope === undefined || field.scope === scope) &&
                isChoiceFieldType(field.field_type),
        )
        .map((field) => {
            const parsed = parseOptionsJson(field.options_json);
            const fieldType = field.field_type as ChoiceFieldType;

            return {
                key: field.key,
                label: field.label,
                help_text: field.help_text,
                field_type: fieldType,
                sort: field.sort,
                required: field.required === true,
                visible: field.visible !== false,
                options_json: parsed.options,
            } satisfies SchemaChoiceField;
        });
}

export function customHeaderChoiceFieldsFromSchema(
    fields: SchemaSourceField[],
): SchemaChoiceField[] {
    return choiceFieldsFromSchema(fields, 'header');
}

export function customPositionChoiceFieldsFromSchema(
    fields: SchemaSourceField[],
): SchemaChoiceField[] {
    return choiceFieldsFromSchema(fields, 'position');
}

export function visibleChoiceFields(
    fields: SchemaChoiceField[],
): SchemaChoiceField[] {
    return fields.filter((field) => field.visible !== false);
}

export function emptyChoiceValue(fieldType: ChoiceFieldType): ChoiceValue {
    return fieldType === 'multi_select' ? [] : null;
}

/**
 * Gespeicherten Serverwert typstreng initialisieren (keine Coercion aus falschen Typen).
 */
export function initChoiceValueFromStored(
    fieldType: ChoiceFieldType,
    raw: unknown,
): { value: ChoiceValue; issue: ChoiceSchemaIssue | null } {
    if (fieldType === 'select') {
        if (raw === null || raw === undefined || raw === '') {
            return { value: null, issue: null };
        }
        if (typeof raw !== 'string') {
            return { value: null, issue: 'invalid_value_type' };
        }

        return { value: raw, issue: null };
    }

    if (raw === null || raw === undefined) {
        return { value: [], issue: null };
    }
    if (!Array.isArray(raw)) {
        return { value: [], issue: 'invalid_value_type' };
    }
    if (!raw.every((item) => typeof item === 'string')) {
        return { value: [], issue: 'invalid_value_type' };
    }

    const canonical = canonicalizeMultiKeys(raw);
    if (canonical.length > MULTI_SELECT_MAX) {
        return { value: canonical, issue: 'multi_over_limit' };
    }

    return { value: canonical, issue: null };
}

export function diagnoseChoiceField(
    field: SchemaChoiceField,
    value: ChoiceValue,
): ChoiceSchemaIssue | null {
    const parsed = parseOptionsJson(field.options_json);
    if (parsed.issue) {
        return parsed.issue;
    }
    if (!parsed.options) {
        return 'missing_options';
    }

    const optionKeys = new Set(parsed.options.map((option) => option.key));

    if (field.field_type === 'select') {
        if (value !== null && typeof value !== 'string') {
            return 'invalid_value_type';
        }
        if (
            typeof value === 'string' &&
            value !== '' &&
            !optionKeys.has(value)
        ) {
            return 'unknown_stored_key';
        }

        return null;
    }

    if (!Array.isArray(value)) {
        return 'invalid_value_type';
    }
    if (!value.every((item) => typeof item === 'string')) {
        return 'invalid_value_type';
    }
    if (value.length > MULTI_SELECT_MAX) {
        return 'multi_over_limit';
    }
    if (value.some((key) => !optionKeys.has(key))) {
        return 'unknown_stored_key';
    }

    return null;
}

export function choiceSchemaIssueMessage(issue: ChoiceSchemaIssue): string {
    switch (issue) {
        case 'missing_options':
            return 'Für dieses Auswahlfeld fehlen eingefrorene Optionen. Speichern ist für dieses Feld nicht möglich, bis der Snapshot korrigiert ist.';
        case 'invalid_options':
            return 'Die eingefrorenen Optionen dieses Feldes sind ungültig. Der gespeicherte Wert wurde nicht verändert.';
        case 'duplicate_option_keys':
            return 'Die eingefrorenen Optionen enthalten doppelte Schlüssel. Der gespeicherte Wert wurde nicht verändert.';
        case 'invalid_value_type':
            return 'Der gespeicherte Wert hat einen ungültigen Typ für dieses Auswahlfeld und wird nicht überschrieben.';
        case 'unknown_stored_key':
            return 'Der gespeicherte Wert enthält einen unbekannten Optionsschlüssel. Bitte einen gültigen Wert wählen oder den Support kontaktieren.';
        case 'multi_over_limit':
            return `Es sind mehr als ${MULTI_SELECT_MAX} Werte gespeichert. Bitte die Auswahl reduzieren.`;
        default:
            return 'Dieses Auswahlfeld kann nicht dargestellt werden.';
    }
}

export function filterOptionsBySearch(
    options: ChoiceOption[],
    query: string,
): ChoiceOption[] {
    const needle = query.trim().toLocaleLowerCase('de');
    if (needle === '') {
        return options;
    }

    return options.filter(
        (option) =>
            option.label.toLocaleLowerCase('de').includes(needle) ||
            option.key.toLocaleLowerCase('de').includes(needle),
    );
}

export function selectableSelectOptions(
    options: ChoiceOption[],
    currentValue: string | null,
): ChoiceOption[] {
    const sorted = sortChoiceOptions(options);

    return sorted.filter(
        (option) =>
            option.is_active ||
            (currentValue !== null && option.key === currentValue),
    );
}

export function optionByKey(
    options: ChoiceOption[],
    key: string | null,
): ChoiceOption | undefined {
    if (key === null || key === '') {
        return undefined;
    }

    return options.find((option) => option.key === key);
}

/**
 * Init-Map für alle Choice-Felder eines Schemas (sichtbar und unsichtbar).
 */
export function initChoiceValuesMap(
    fields: SchemaChoiceField[],
    stored: Record<string, unknown> | null | undefined,
): Record<string, ChoiceValue> {
    const initial: Record<string, ChoiceValue> = {};

    for (const field of fields) {
        const raw = stored?.[field.key];
        initial[field.key] = initChoiceValueFromStored(
            field.field_type,
            raw,
        ).value;
    }

    return initial;
}

/**
 * Payload-Einträge nur für Choice-Keys (explizit null / []).
 */
export function choiceValuesForPayload(
    fields: SchemaChoiceField[],
    values: Record<string, ChoiceValue>,
): Record<string, string | null | string[]> {
    const payload: Record<string, string | null | string[]> = {};

    for (const field of fields) {
        const current = values[field.key];
        if (field.field_type === 'select') {
            payload[field.key] =
                typeof current === 'string' && current !== '' ? current : null;
        } else {
            payload[field.key] = Array.isArray(current)
                ? canonicalizeMultiKeys(current)
                : [];
        }
    }

    return payload;
}
