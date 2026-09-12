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
    if (value.length !== new Set(value).size) {
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

/**
 * Gespeicherten Serverwert typstreng initialisieren (keine Coercion aus falschen Typen).
 * Bei Typfehlern: leerer Anzeigewert + initPayloadSafe=false (niemals als Clear senden).
 */
export function initChoiceValueFromStored(
    fieldType: ChoiceFieldType,
    raw: unknown,
): {
    value: ChoiceValue;
    issue: ChoiceSchemaIssue | null;
    initPayloadSafe: boolean;
} {
    if (fieldType === 'select') {
        if (raw === null || raw === undefined || raw === '') {
            return { value: null, issue: null, initPayloadSafe: true };
        }
        if (typeof raw !== 'string') {
            return {
                value: null,
                issue: 'invalid_value_type',
                initPayloadSafe: false,
            };
        }

        return { value: raw, issue: null, initPayloadSafe: true };
    }

    if (raw === null || raw === undefined) {
        return { value: [], issue: null, initPayloadSafe: true };
    }
    if (!Array.isArray(raw)) {
        return {
            value: [],
            issue: 'invalid_value_type',
            initPayloadSafe: false,
        };
    }
    if (!raw.every((item) => typeof item === 'string')) {
        return {
            value: [],
            issue: 'invalid_value_type',
            initPayloadSafe: false,
        };
    }

    const asStrings = raw as string[];
    if (asStrings.length !== new Set(asStrings).size) {
        // Beschädigte Serverdaten: keine stille Deduplizierung.
        return {
            value: [...asStrings],
            issue: 'invalid_value_type',
            initPayloadSafe: false,
        };
    }

    const canonical = canonicalizeMultiKeys(asStrings);
    if (canonical.length > MULTI_SELECT_MAX) {
        return {
            value: canonical,
            issue: 'multi_over_limit',
            initPayloadSafe: false,
        };
    }

    return { value: canonical, issue: null, initPayloadSafe: true };
}

export type ChoiceFieldEntry = {
    value: ChoiceValue;
    issue: ChoiceSchemaIssue | null;
    /** false: Key darf unberührt nicht im Payload erscheinen (Datenverlust-Schutz). */
    initPayloadSafe: boolean;
};

/**
 * Init-Map für alle Choice-Felder eines Schemas (sichtbar und unsichtbar).
 */
export function initChoiceEntriesMap(
    fields: SchemaChoiceField[],
    stored: Record<string, unknown> | null | undefined,
): Record<string, ChoiceFieldEntry> {
    const initial: Record<string, ChoiceFieldEntry> = {};

    for (const field of fields) {
        const raw = stored?.[field.key];
        const initialized = initChoiceValueFromStored(field.field_type, raw);
        const schemaIssue =
            initialized.issue ?? diagnoseChoiceField(field, initialized.value);
        const unsafeInit =
            initialized.initPayloadSafe === false ||
            schemaIssue === 'missing_options' ||
            schemaIssue === 'invalid_options' ||
            schemaIssue === 'duplicate_option_keys' ||
            schemaIssue === 'invalid_value_type' ||
            schemaIssue === 'unknown_stored_key' ||
            schemaIssue === 'multi_over_limit';

        initial[field.key] = {
            value: initialized.value,
            issue: schemaIssue,
            initPayloadSafe: !unsafeInit,
        };
    }

    return initial;
}

/**
 * Abwärtskompatibel: nur Werte (ohne Meta).
 */
export function initChoiceValuesMap(
    fields: SchemaChoiceField[],
    stored: Record<string, unknown> | null | undefined,
): Record<string, ChoiceValue> {
    const entries = initChoiceEntriesMap(fields, stored);
    const initial: Record<string, ChoiceValue> = {};
    for (const [key, entry] of Object.entries(entries)) {
        initial[key] = entry.value;
    }

    return initial;
}

/**
 * C1-Partial-Save: unberührte Keys weglassen (Keep).
 * Nur touched Keys senden; Integrity ohne gültige Nutzerkorrektur blockiert Save.
 */
export function choiceValuesForPayload(
    fields: SchemaChoiceField[],
    values: Record<string, ChoiceValue>,
    touchedKeys: ReadonlySet<string>,
    initMeta: Record<
        string,
        Pick<ChoiceFieldEntry, 'initPayloadSafe' | 'issue'>
    > = {},
): {
    payload: Record<string, string | null | string[]>;
    blockReason: string | null;
} {
    const payload: Record<string, string | null | string[]> = {};
    let blockReason: string | null = null;

    for (const field of fields) {
        if (!touchedKeys.has(field.key)) {
            continue;
        }

        const current = values[field.key];
        const meta = initMeta[field.key];
        const issue = diagnoseChoiceField(
            field,
            current ?? emptyChoiceValue(field.field_type),
        );

        if (
            issue === 'missing_options' ||
            issue === 'invalid_options' ||
            issue === 'duplicate_option_keys' ||
            issue === 'invalid_value_type' ||
            issue === 'unknown_stored_key' ||
            issue === 'multi_over_limit'
        ) {
            blockReason =
                blockReason ??
                `${field.label}: ${choiceSchemaIssueMessage(issue)}`;
            continue;
        }

        if (meta && meta.initPayloadSafe === false && issue !== null) {
            blockReason =
                blockReason ??
                `${field.label}: ${choiceSchemaIssueMessage(meta.issue ?? issue)}`;
            continue;
        }

        if (field.field_type === 'select') {
            payload[field.key] =
                typeof current === 'string' && current !== '' ? current : null;
        } else {
            payload[field.key] = Array.isArray(current)
                ? canonicalizeMultiKeys(current)
                : [];
        }
    }

    return { payload, blockReason };
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

export function isTextFieldType(
    value: string,
): value is 'short_text' | 'long_text' {
    return value === 'short_text' || value === 'long_text';
}

/**
 * Text-Customs aus einem bereits gefilterten Custom-Bucket (Header oder Position).
 */
export function textFieldsFromCustomBucket(fields: SchemaSourceField[]): Array<{
    key: string;
    label: string;
    help_text?: string | null;
    field_type: string;
    sort?: number;
    max_length?: number | null;
    required?: boolean;
}> {
    return fields
        .filter((field) => isTextFieldType(field.field_type))
        .map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            max_length:
                typeof (field as { max_length?: unknown }).max_length ===
                'number'
                    ? ((field as { max_length?: number }).max_length ?? null)
                    : null,
            required: field.required === true,
        }));
}

/**
 * Choice-Customs aus einem bereits gefilterten Custom-Bucket.
 */
export function choiceFieldsFromCustomBucket(
    fields: SchemaSourceField[],
): SchemaChoiceField[] {
    return fields
        .filter((field) => isChoiceFieldType(field.field_type))
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

/**
 * Multi-Anzeige: Reihenfolge nach Freeze-options_json.sort, Tie-Breaker Key.
 * Speicherung bleibt C1-kanonisch (lexikographisch nach Key).
 */
export function sortMultiKeysForDisplay(
    keys: readonly string[],
    options: ChoiceOption[] | null,
): string[] {
    const unique = [...new Set(keys.filter((key) => key !== ''))];
    if (!options || options.length === 0) {
        return [...unique].sort((a, b) => a.localeCompare(b));
    }

    const byKey = new Map(options.map((option) => [option.key, option]));

    return unique.sort((a, b) => {
        const left = byKey.get(a);
        const right = byKey.get(b);
        const leftSort = left?.sort ?? Number.MAX_SAFE_INTEGER;
        const rightSort = right?.sort ?? Number.MAX_SAFE_INTEGER;
        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        return a.localeCompare(b);
    });
}

export type ChoiceReadOnlyDisplay =
    | { kind: 'uncaptured'; text: 'Nicht erfasst' }
    | { kind: 'empty'; text: '–' }
    | {
          kind: 'select';
          label: string;
          inactive: boolean;
      }
    | {
          kind: 'multi';
          items: Array<{ key: string; label: string; inactive: boolean }>;
      }
    | { kind: 'integrity'; message: string };

/**
 * Calc-Origin-/Read-only-Darstellung für Choice (keine Controls).
 */
export function choiceReadOnlyDisplay(
    field: SchemaChoiceField,
    raw: unknown,
    captured: boolean,
): ChoiceReadOnlyDisplay {
    if (!captured) {
        return { kind: 'uncaptured', text: 'Nicht erfasst' };
    }

    const optionsIssue = parseOptionsJson(field.options_json).issue;
    if (optionsIssue !== null) {
        return {
            kind: 'integrity',
            message: choiceSchemaIssueMessage(optionsIssue),
        };
    }
    if (field.options_json === null) {
        return {
            kind: 'integrity',
            message: choiceSchemaIssueMessage('missing_options'),
        };
    }

    if (field.field_type === 'select') {
        if (raw === null || raw === undefined || raw === '') {
            return { kind: 'empty', text: '–' };
        }
        if (typeof raw !== 'string') {
            return {
                kind: 'integrity',
                message: choiceSchemaIssueMessage('invalid_value_type'),
            };
        }
        const option = optionByKey(field.options_json, raw);
        if (!option) {
            return {
                kind: 'integrity',
                message: choiceSchemaIssueMessage('unknown_stored_key'),
            };
        }

        return {
            kind: 'select',
            label: option.label,
            inactive: !option.is_active,
        };
    }

    if (raw === null || raw === undefined) {
        return { kind: 'empty', text: '–' };
    }
    if (!Array.isArray(raw) || !raw.every((item) => typeof item === 'string')) {
        return {
            kind: 'integrity',
            message: choiceSchemaIssueMessage('invalid_value_type'),
        };
    }
    if (raw.length === 0) {
        return { kind: 'empty', text: '–' };
    }
    if (raw.length !== new Set(raw).size) {
        return {
            kind: 'integrity',
            message: choiceSchemaIssueMessage('invalid_value_type'),
        };
    }
    if (raw.length > MULTI_SELECT_MAX) {
        return {
            kind: 'integrity',
            message: choiceSchemaIssueMessage('multi_over_limit'),
        };
    }

    const ordered = sortMultiKeysForDisplay(raw, field.options_json);
    const items: Array<{ key: string; label: string; inactive: boolean }> = [];

    for (const key of ordered) {
        const option = optionByKey(field.options_json, key);
        if (!option) {
            return {
                kind: 'integrity',
                message: choiceSchemaIssueMessage('unknown_stored_key'),
            };
        }
        items.push({
            key,
            label: option.label,
            inactive: !option.is_active,
        });
    }

    return { kind: 'multi', items };
}

/**
 * Text-Calc-Origin: captured=false → „Nicht erfasst“, sonst Leerwert „–“.
 */
export function textReadOnlyCapturedDisplay(
    raw: unknown,
    captured: boolean,
): string {
    if (!captured) {
        return 'Nicht erfasst';
    }

    if (raw === null || raw === undefined) {
        return '–';
    }
    if (typeof raw === 'string' || typeof raw === 'number') {
        const text = String(raw).trim();

        return text === '' ? '–' : String(raw);
    }

    return '–';
}
