/**
 * DF-3-REST-B: Draft-Hilfen für Auswahloptionen (Client).
 */

export type OptionRow = {
    key: string;
    label: string;
    sort: number;
    is_active: boolean;
};

export type DraftOptionRow = OptionRow & {
    /** Client-only identity for React keys / unsaved rows */
    clientId: string;
    persisted: boolean;
    keyTouched: boolean;
    sortInput: string;
    sortError: string | null;
};

export type OptionsChangeSummary = {
    added: string[];
    label_changed: string[];
    sort_changed: string[];
    deactivated: string[];
    reactivated: string[];
    unchanged: boolean;
};

export function slugFromLabel(label: string): string {
    return label
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 64);
}

export function ensureOptionKeyPattern(key: string): string {
    const normalized = key.trim().toLowerCase();
    if (/^[a-z][a-z0-9_]{0,63}$/.test(normalized)) {
        return normalized;
    }
    return slugFromLabel(normalized);
}

let draftCounter = 0;

export function nextClientId(): string {
    draftCounter += 1;
    return `draft-${draftCounter}`;
}

export function rowsFromServer(options: OptionRow[]): DraftOptionRow[] {
    return options.map((row) => ({
        ...row,
        clientId: `persisted-${row.key}`,
        persisted: true,
        keyTouched: true,
        sortInput: String(row.sort),
        sortError: null,
    }));
}

export function createEmptyDraft(sort: number): DraftOptionRow {
    return {
        clientId: nextClientId(),
        key: '',
        label: '',
        sort,
        is_active: true,
        persisted: false,
        keyTouched: false,
        sortInput: String(sort),
        sortError: null,
    };
}

/**
 * Integer-Aufbereitung ohne permissive Coercion (kein parseFloat, kein Boolean).
 */
export function parseStrictSortInput(raw: string): {
    value: number | null;
    error: string | null;
} {
    const trimmed = raw.trim();
    if (trimmed === '') {
        return {
            value: null,
            error: 'Die Optionssortierung muss ein ganzer Zahlenwert (Integer) sein.',
        };
    }
    if (!/^[0-9]+$/.test(trimmed)) {
        return {
            value: null,
            error: 'Die Optionssortierung muss ein ganzer Zahlenwert (Integer) sein.',
        };
    }
    // Keine führenden Pluszeichen, keine Floats, keine Exponentialschreibweise.
    if (trimmed.length > 1 && trimmed.startsWith('0')) {
        return {
            value: null,
            error: 'Die Optionssortierung muss ein ganzer Zahlenwert (Integer) sein.',
        };
    }
    const value = Number(trimmed);
    if (!Number.isSafeInteger(value) || value < 0 || value > 4_294_967_295) {
        return {
            value: null,
            error: 'Die Optionssortierung muss zwischen 0 und 4294967295 liegen.',
        };
    }
    return { value, error: null };
}

export function activeOptionCount(rows: Array<{ is_active: boolean }>): number {
    return rows.filter((row) => row.is_active).length;
}

export function hasZeroActiveWarning(
    rows: Array<{ is_active: boolean }>,
): boolean {
    return activeOptionCount(rows) === 0;
}

export function toPayloadOptions(rows: DraftOptionRow[]): {
    options: OptionRow[];
    errors: Record<string, string>;
} {
    const errors: Record<string, string> = {};
    const options: OptionRow[] = [];

    rows.forEach((row, index) => {
        const parsed = parseStrictSortInput(row.sortInput);
        if (parsed.error || parsed.value === null) {
            errors[`${row.clientId}.sort`] =
                parsed.error ??
                'Die Optionssortierung muss ein ganzer Zahlenwert (Integer) sein.';
            return;
        }
        if (row.label.trim() === '') {
            errors[`${row.clientId}.label`] =
                'Das Optionslabel darf nicht leer sein.';
        }
        if (row.label.trim().length > 255) {
            errors[`${row.clientId}.label`] =
                'Das Optionslabel darf höchstens 255 Zeichen lang sein.';
        }
        const key = row.key.trim();
        if (!/^[a-z][a-z0-9_]{0,63}$/.test(key)) {
            errors[`${row.clientId}.key`] =
                'Der Optionsschlüssel muss dem Muster [a-z][a-z0-9_]{0,63} entsprechen.';
        }
        options.push({
            key,
            label: row.label.trim(),
            sort: parsed.value,
            is_active: row.is_active,
        });
        // index unused except for stability
        void index;
    });

    if (rows.length > 100) {
        errors.options =
            'Es sind maximal 100 Optionen pro Felddefinition zulässig.';
    }

    return { options, errors };
}

export function buildLocalChangeSummary(
    previous: OptionRow[],
    next: OptionRow[],
): OptionsChangeSummary {
    const previousByKey = new Map(previous.map((row) => [row.key, row]));
    const nextByKey = new Map(next.map((row) => [row.key, row]));

    const added: string[] = [];
    const labelChanged: string[] = [];
    const sortChanged: string[] = [];
    const deactivated: string[] = [];
    const reactivated: string[] = [];

    for (const [key, row] of nextByKey) {
        const before = previousByKey.get(key);
        if (!before) {
            added.push(key);
            continue;
        }
        if (before.label !== row.label) {
            labelChanged.push(key);
        }
        if (before.sort !== row.sort) {
            sortChanged.push(key);
        }
        if (before.is_active && !row.is_active) {
            deactivated.push(key);
        }
        if (!before.is_active && row.is_active) {
            reactivated.push(key);
        }
    }

    for (const [key, row] of previousByKey) {
        if (!nextByKey.has(key) && row.is_active) {
            deactivated.push(key);
        }
    }

    added.sort();
    labelChanged.sort();
    sortChanged.sort();
    deactivated.sort();
    reactivated.sort();

    return {
        added,
        label_changed: labelChanged,
        sort_changed: sortChanged,
        deactivated,
        reactivated,
        unchanged:
            added.length === 0 &&
            labelChanged.length === 0 &&
            sortChanged.length === 0 &&
            deactivated.length === 0 &&
            reactivated.length === 0,
    };
}

export function applyLabelChange(
    row: DraftOptionRow,
    label: string,
): DraftOptionRow {
    const next: DraftOptionRow = { ...row, label };
    if (!row.persisted && !row.keyTouched) {
        next.key = slugFromLabel(label);
    }
    return next;
}

export function canRemoveDraftRow(row: DraftOptionRow): boolean {
    return !row.persisted;
}

export function canEditKey(row: DraftOptionRow): boolean {
    return !row.persisted;
}
