/**
 * DF-1: Snapshot-Regelauswertung für den Kalkulationswizard.
 * Unterstützt ausschließlich field_equals + require_field (wie der Server).
 */

export type SnapshotFieldRule = {
    condition: {
        op?: string;
        field_key?: string;
        value?: unknown;
    };
    action: {
        op?: string;
        field_key?: string;
    };
};

export type PositionDynamicValues = {
    period_open?: boolean;
    position_flight_period?: {
        start?: string | null;
        end?: string | null;
    } | null;
    [key: string]: unknown;
};

function asBool(value: unknown): boolean | null {
    if (typeof value === 'boolean') {
        return value;
    }
    if (value === 0 || value === '0') {
        return false;
    }
    if (value === 1 || value === '1') {
        return true;
    }

    return null;
}

function conditionMatches(
    condition: SnapshotFieldRule['condition'],
    values: PositionDynamicValues,
): boolean {
    if (condition.op !== 'field_equals') {
        return false;
    }

    const key = condition.field_key ?? '';
    if (key === '') {
        return false;
    }

    const actual = values[key];
    const expected = condition.value;

    if (typeof expected === 'boolean') {
        return asBool(actual) === expected;
    }

    // Multi-Select: leeres Array ist „leer“, gefülltes Array matcht nur
    // identische Listen (Reihenfolge egal) bzw. nie einen Skalar-Expected.
    if (Array.isArray(actual)) {
        if (Array.isArray(expected)) {
            if (actual.length !== expected.length) {
                return false;
            }
            const left = [...actual].map(String).sort();
            const right = [...expected].map(String).sort();

            return left.every((item, index) => item === right[index]);
        }

        return false;
    }

    return actual === expected;
}

/**
 * Liefert die laut Snapshot-Regeln für die aktuelle Position erforderlichen Feldschlüssel.
 */
export function requiredPositionFieldKeysFromSnapshotRules(
    rules: SnapshotFieldRule[],
    positionValues: PositionDynamicValues,
): string[] {
    const required: string[] = [];

    for (const rule of rules) {
        if (!conditionMatches(rule.condition, positionValues)) {
            continue;
        }
        if (rule.action?.op !== 'require_field') {
            continue;
        }
        const key = rule.action.field_key ?? '';
        if (key !== '') {
            required.push(key);
        }
    }

    return [...new Set(required)];
}
