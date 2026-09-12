/**
 * DF-3-RULE-A: reine TypeScript-Parität zum PHP-FieldRuleContract / Evaluator.
 * Keine produktive UI-Verdrahtung – nur Auswertung/Parity.
 */

export type FieldScope = 'header' | 'position';

export type FieldType =
    | 'period'
    | 'boolean'
    | 'short_text'
    | 'long_text'
    | 'select'
    | 'multi_select';

export type RuleFieldDefinition = {
    key: string;
    field_type: FieldType;
    scope: FieldScope;
    options_json?: Array<{ key: string; is_active?: boolean }> | null;
    action_target_readonly?: boolean;
};

export type AtomicCondition =
    | { op: 'field_equals'; field_key: string; value: unknown }
    | { op: 'field_empty'; field_key: string }
    | { op: 'field_not_empty'; field_key: string }
    | { op: 'field_contains'; field_key: string; value: string };

export type RuleCondition =
    | AtomicCondition
    | { op: 'all'; conditions: AtomicCondition[] }
    | { op: 'any'; conditions: AtomicCondition[] };

export type RuleAction =
    | { op: 'require_field'; field_key: string }
    | { op: 'set_visible'; field_key: string; value: boolean };

export type SnapshotFieldRule = {
    condition: RuleCondition | Record<string, unknown>;
    action: RuleAction | Record<string, unknown>;
};

export type PositionDynamicValues = {
    period_open?: boolean;
    position_flight_period?: {
        start?: string | null;
        end?: string | null;
    } | null;
    [key: string]: unknown;
};

export const MIN_GROUP_CONDITIONS = 2;
export const MAX_GROUP_CONDITIONS = 8;
export const MAX_CONDITION_JSON_BYTES = 8192;
export const MAX_ACTION_JSON_BYTES = 2048;

const ATOMIC_OPS = new Set([
    'field_equals',
    'field_empty',
    'field_not_empty',
    'field_contains',
]);

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

function periodIsEmpty(period: Record<string, unknown>): boolean {
    const start = period.start ?? period.period_start ?? null;
    const end = period.end ?? period.period_end ?? null;

    return start === null || start === '' || end === null || end === '';
}

export function isEmpty(
    def: Pick<RuleFieldDefinition, 'field_type'>,
    value: unknown,
): boolean {
    if (value === null || value === undefined) {
        return true;
    }

    switch (def.field_type) {
        case 'boolean':
            return asBool(value) === null;
        case 'period':
            return periodIsEmpty(
                typeof value === 'object' && value !== null
                    ? (value as Record<string, unknown>)
                    : {},
            );
        case 'short_text':
        case 'long_text':
            return typeof value === 'string'
                ? value.trim() === ''
                : String(value as string | number | boolean).trim() === '';
        case 'select':
            return value === '';
        case 'multi_select':
            return value === '' || (Array.isArray(value) && value.length === 0);
        default:
            return false;
    }
}

function jsonByteLength(value: unknown): number {
    return new TextEncoder().encode(JSON.stringify(value)).length;
}

function assertNoUnknownKeys(
    node: Record<string, unknown>,
    allowed: string[],
    label: string,
): void {
    for (const key of Object.keys(node)) {
        if (!allowed.includes(key)) {
            throw new Error(`${label} enthält unerwartetes Attribut „${key}“.`);
        }
    }
}

function allowedConditionKeys(condition: Record<string, unknown>): string[] {
    const op = condition.op;
    if (op === 'all' || op === 'any') {
        return ['op', 'conditions'];
    }
    if (op === 'field_empty' || op === 'field_not_empty') {
        return ['op', 'field_key'];
    }

    return ['op', 'field_key', 'value'];
}

function allowedActionKeys(action: Record<string, unknown>): string[] {
    return action.op === 'set_visible'
        ? ['op', 'field_key', 'value']
        : ['op', 'field_key'];
}

function conditionFieldKeys(condition: Record<string, unknown>): string[] {
    const op = typeof condition.op === 'string' ? condition.op : '';
    if (op === 'all' || op === 'any') {
        const keys: string[] = [];
        const children = Array.isArray(condition.conditions)
            ? condition.conditions
            : [];
        for (const child of children) {
            if (child && typeof child === 'object') {
                keys.push(
                    ...conditionFieldKeys(child as Record<string, unknown>),
                );
            }
        }

        return [...new Set(keys)];
    }
    const key =
        typeof condition.field_key === 'string' ? condition.field_key : '';

    return key === '' ? [] : [key];
}

export function isExactDf1SeedRule(
    condition: Record<string, unknown>,
    action: Record<string, unknown>,
): boolean {
    if (action.op !== 'require_field') {
        return false;
    }
    if (action.field_key !== 'position_flight_period') {
        return false;
    }
    if (Object.keys(action).length !== 2) {
        return false;
    }
    if (condition.op !== 'field_equals') {
        return false;
    }
    if (condition.field_key !== 'period_open') {
        return false;
    }
    if (!('value' in condition) || condition.value !== false) {
        return false;
    }

    return Object.keys(condition).length === 3;
}

function assertOptionKey(
    def: RuleFieldDefinition,
    optionKey: string,
    requireActive: boolean,
): void {
    const options = def.options_json;
    if (!Array.isArray(options)) {
        throw new Error(
            `Auswahlfeld „${def.key}“ hat keinen Optionskatalog für die Regelprüfung.`,
        );
    }
    const found = options.find((row) => row.key === optionKey);
    if (!found) {
        throw new Error(
            `Options-Key „${optionKey}“ ist im Feldkatalog nicht vorhanden.`,
        );
    }
    if (requireActive && !found.is_active) {
        throw new Error(
            `Options-Key „${optionKey}“ ist inaktiv und darf in neuen Regeln nicht verwendet werden.`,
        );
    }
}

function assertConditionNode(
    defsByKey: Record<string, RuleFieldDefinition>,
    condition: Record<string, unknown>,
    requireActiveOptionKeys: boolean,
    allowGroup: boolean,
): void {
    const op = condition.op;
    if (typeof op !== 'string' || op === '') {
        throw new Error('Regelbedingung ohne Operator.');
    }

    if (op === 'all' || op === 'any') {
        if (!allowGroup) {
            throw new Error(
                'Verschachtelte UND-/ODER-Gruppen sind in V1 nicht erlaubt.',
            );
        }
        if (!Array.isArray(condition.conditions)) {
            throw new Error('UND-/ODER-Gruppe benötigt ein Conditions-Array.');
        }
        const count = condition.conditions.length;
        if (count < MIN_GROUP_CONDITIONS) {
            throw new Error(
                `UND-/ODER-Gruppe braucht mindestens ${MIN_GROUP_CONDITIONS} Bedingungen.`,
            );
        }
        if (count > MAX_GROUP_CONDITIONS) {
            throw new Error(
                `UND-/ODER-Gruppe darf höchstens ${MAX_GROUP_CONDITIONS} Bedingungen haben.`,
            );
        }
        for (const child of condition.conditions) {
            if (!child || typeof child !== 'object') {
                throw new Error(
                    'UND-/ODER-Gruppe enthält ungültige Kindbedingung.',
                );
            }
            const childRec = child as Record<string, unknown>;
            assertNoUnknownKeys(
                childRec,
                allowedConditionKeys(childRec),
                'Bedingung',
            );
            assertConditionNode(
                defsByKey,
                childRec,
                requireActiveOptionKeys,
                false,
            );
        }

        return;
    }

    if (!ATOMIC_OPS.has(op)) {
        throw new Error(`Unbekannter Regel-Bedingungsoperator: ${op}`);
    }

    const fieldKey =
        typeof condition.field_key === 'string' ? condition.field_key : '';
    const def = defsByKey[fieldKey];
    if (!def) {
        throw new Error(
            `Regelbedingung referenziert unbekanntes Feld „${fieldKey}“.`,
        );
    }

    if (op === 'field_equals') {
        if (!('value' in condition)) {
            throw new Error('field_equals benötigt einen Vergleichswert.');
        }
        if (def.field_type === 'boolean') {
            if (typeof condition.value !== 'boolean') {
                throw new Error(
                    'field_equals auf Boolean erwartet einen echten Boolean-Wert.',
                );
            }
        } else if (
            def.field_type === 'short_text' ||
            def.field_type === 'long_text'
        ) {
            if (typeof condition.value !== 'string') {
                throw new Error('field_equals auf Text erwartet einen String.');
            }
        } else if (def.field_type === 'select') {
            if (typeof condition.value !== 'string' || condition.value === '') {
                throw new Error(
                    'field_equals auf Select erwartet einen Options-Key.',
                );
            }
            assertOptionKey(def, condition.value, requireActiveOptionKeys);
        } else if (def.field_type === 'multi_select') {
            throw new Error(
                'field_equals ist für Multi-Select nicht erlaubt; field_contains verwenden.',
            );
        } else {
            throw new Error(
                'field_equals ist für Zeitraum-Felder nicht erlaubt; field_empty / field_not_empty verwenden.',
            );
        }
    } else if (op === 'field_empty' || op === 'field_not_empty') {
        if ('value' in condition) {
            throw new Error(
                'field_empty / field_not_empty dürfen keinen value tragen.',
            );
        }
    } else if (op === 'field_contains') {
        if (def.field_type !== 'multi_select') {
            throw new Error('field_contains ist nur für Multi-Select erlaubt.');
        }
        if (typeof condition.value !== 'string' || condition.value === '') {
            throw new Error(
                'field_contains erwartet genau einen Options-Key als String.',
            );
        }
        assertOptionKey(def, condition.value, requireActiveOptionKeys);
    }
}

function assertActionNode(
    defsByKey: Record<string, RuleFieldDefinition>,
    action: Record<string, unknown>,
): void {
    const op = action.op;
    if (op !== 'require_field' && op !== 'set_visible') {
        throw new Error(`Unbekannter Regel-Aktionsoperator: ${String(op)}`);
    }
    const fieldKey =
        typeof action.field_key === 'string' ? action.field_key : '';
    if (!defsByKey[fieldKey]) {
        throw new Error(
            `Regelaktion referenziert unbekanntes Feld „${fieldKey}“.`,
        );
    }
    if (op === 'set_visible') {
        if (!('value' in action) || typeof action.value !== 'boolean') {
            throw new Error('set_visible benötigt value als Boolean.');
        }
    }
}

function assertActionTargetWritable(
    defsByKey: Record<string, RuleFieldDefinition>,
    condition: Record<string, unknown>,
    action: Record<string, unknown>,
): void {
    const fieldKey =
        typeof action.field_key === 'string' ? action.field_key : '';
    const def = defsByKey[fieldKey];
    if (!def?.action_target_readonly) {
        return;
    }
    if (action.op === 'set_visible') {
        throw new Error(
            `Calc-Origin-Feld „${fieldKey}“ darf nicht Ziel einer Sichtbarkeitsregel sein.`,
        );
    }
    if (isExactDf1SeedRule(condition, action)) {
        return;
    }
    throw new Error(
        `Calc-Origin-Feld „${fieldKey}“ darf nicht Ziel einer Pflichtregel sein.`,
    );
}

function assertScopeCompatibility(
    defsByKey: Record<string, RuleFieldDefinition>,
    condition: Record<string, unknown>,
    action: Record<string, unknown>,
): void {
    const actionKey =
        typeof action.field_key === 'string' ? action.field_key : '';
    const actionDef = defsByKey[actionKey];
    if (!actionDef || actionDef.scope !== 'header') {
        return;
    }
    for (const key of conditionFieldKeys(condition)) {
        const def = defsByKey[key];
        if (!def || def.scope !== 'header') {
            throw new Error(
                'Position→Header-Regeln sind in V1 nicht erlaubt; Header-Aktionen brauchen ausschließlich Header-Bedingungen.',
            );
        }
    }
}

export function assertRuleStructure(
    defsByKey: Record<string, RuleFieldDefinition>,
    condition: Record<string, unknown>,
    action: Record<string, unknown>,
    requireActiveOptionKeys = true,
): void {
    if (jsonByteLength(condition) > MAX_CONDITION_JSON_BYTES) {
        throw new Error('Bedingung überschreitet die maximale JSON-Größe.');
    }
    if (jsonByteLength(action) > MAX_ACTION_JSON_BYTES) {
        throw new Error('Aktion überschreitet die maximale JSON-Größe.');
    }
    assertNoUnknownKeys(
        condition,
        allowedConditionKeys(condition),
        'Bedingung',
    );
    assertNoUnknownKeys(action, allowedActionKeys(action), 'Aktion');
    assertConditionNode(defsByKey, condition, requireActiveOptionKeys, true);
    assertActionNode(defsByKey, action);
    assertActionTargetWritable(defsByKey, condition, action);
    assertScopeCompatibility(defsByKey, condition, action);
    if (
        action.op === 'set_visible' &&
        typeof action.field_key === 'string' &&
        conditionFieldKeys(condition).includes(action.field_key)
    ) {
        throw new Error(
            `set_visible darf das Zielfeld „${action.field_key}“ nicht in der eigenen Bedingung referenzieren.`,
        );
    }
}

export function assertRuleset(
    defsByKey: Record<string, RuleFieldDefinition>,
    rules: SnapshotFieldRule[],
    requireActiveOptionKeys = true,
): void {
    const setVisibleTargets = new Set<string>();
    for (const rule of rules) {
        const condition =
            rule.condition && typeof rule.condition === 'object'
                ? (rule.condition as Record<string, unknown>)
                : null;
        const action =
            rule.action && typeof rule.action === 'object'
                ? (rule.action as Record<string, unknown>)
                : null;
        if (!condition || !action) {
            throw new Error('Feldregel hat ungültige JSON-Struktur.');
        }
        assertRuleStructure(
            defsByKey,
            condition,
            action,
            requireActiveOptionKeys,
        );
        if (action.op === 'set_visible') {
            const target =
                typeof action.field_key === 'string' ? action.field_key : '';
            if (setVisibleTargets.has(target)) {
                throw new Error(
                    `Mehrere Sichtbarkeitsregeln auf Feld „${target}“ sind in V1 nicht erlaubt.`,
                );
            }
            setVisibleTargets.add(target);
        }
    }
}

function readActual(
    def: RuleFieldDefinition,
    headerValues: Record<string, unknown>,
    positionValues: Record<string, unknown>,
): unknown {
    return def.scope === 'header'
        ? (headerValues[def.key] ?? null)
        : (positionValues[def.key] ?? null);
}

function atomicMatches(
    condition: AtomicCondition,
    headerValues: Record<string, unknown>,
    positionValues: Record<string, unknown>,
    defsByKey: Record<string, RuleFieldDefinition>,
): boolean {
    const def = defsByKey[condition.field_key];
    if (!def) {
        throw new Error(
            `Regelbedingung referenziert unbekanntes Feld „${condition.field_key}“.`,
        );
    }
    const actual = readActual(def, headerValues, positionValues);

    switch (condition.op) {
        case 'field_equals': {
            if (def.field_type === 'boolean') {
                return (
                    typeof condition.value === 'boolean' &&
                    asBool(actual) === condition.value
                );
            }
            return actual === condition.value;
        }
        case 'field_empty':
            return isEmpty(def, actual);
        case 'field_not_empty':
            return !isEmpty(def, actual);
        case 'field_contains': {
            if (!Array.isArray(actual) || typeof condition.value !== 'string') {
                return false;
            }
            return actual.map(String).includes(condition.value);
        }
        default:
            throw new Error('Unbekannter Regel-Bedingungsoperator.');
    }
}

export function conditionMatches(
    condition: RuleCondition | Record<string, unknown>,
    headerValues: Record<string, unknown>,
    positionValues: Record<string, unknown>,
    defsByKey: Record<string, RuleFieldDefinition>,
): boolean {
    const rawOp = condition.op;
    const op = typeof rawOp === 'string' ? rawOp : '';

    if (op === 'all' || op === 'any') {
        const children =
            'conditions' in condition && Array.isArray(condition.conditions)
                ? condition.conditions
                : [];
        if (op === 'all') {
            for (const child of children) {
                if (
                    !conditionMatches(
                        child as RuleCondition,
                        headerValues,
                        positionValues,
                        defsByKey,
                    )
                ) {
                    return false;
                }
            }
            return true;
        }
        for (const child of children) {
            if (
                conditionMatches(
                    child as RuleCondition,
                    headerValues,
                    positionValues,
                    defsByKey,
                )
            ) {
                return true;
            }
        }
        return false;
    }

    if (!ATOMIC_OPS.has(op)) {
        throw new Error(`Unbekannter Regel-Bedingungsoperator: ${op}`);
    }

    return atomicMatches(
        condition as AtomicCondition,
        headerValues,
        positionValues,
        defsByKey,
    );
}

/**
 * Wizard-Legacy bis RULE-B: nur exakte DF-1-Seed-Regel
 * field_equals(period_open, false) → require_field(position_flight_period).
 * Alles andere fail-closed.
 */
export function requiredPositionFieldKeysFromSnapshotRules(
    rules: SnapshotFieldRule[],
    positionValues: PositionDynamicValues,
    defsByKey: Record<string, RuleFieldDefinition> = {},
    headerValues: Record<string, unknown> = {},
): string[] {
    const hasDefs = Object.keys(defsByKey).length > 0;
    if (hasDefs) {
        assertRuleset(defsByKey, rules, false);
        const required: string[] = [];
        for (const rule of rules) {
            if (
                !conditionMatches(
                    rule.condition,
                    headerValues,
                    positionValues,
                    defsByKey,
                )
            ) {
                continue;
            }
            if (rule.action?.op !== 'require_field') {
                continue;
            }
            const key =
                typeof rule.action.field_key === 'string'
                    ? rule.action.field_key
                    : '';
            if (key !== '') {
                required.push(key);
            }
        }

        return [...new Set(required)];
    }

    const required: string[] = [];
    for (const rule of rules) {
        const condition =
            rule.condition && typeof rule.condition === 'object'
                ? (rule.condition as Record<string, unknown>)
                : null;
        const action =
            rule.action && typeof rule.action === 'object'
                ? (rule.action as Record<string, unknown>)
                : null;
        if (!condition || !action) {
            throw new Error('Feldregel hat ungültige JSON-Struktur.');
        }
        if (!isExactDf1SeedRule(condition, action)) {
            throw new Error(
                'Legacy-Wizard akzeptiert nur die DF-1-Seed-Regel ohne Feldkatalog.',
            );
        }
        if (asBool(positionValues.period_open) === false) {
            required.push('position_flight_period');
        }
    }

    return [...new Set(required)];
}

export function effectiveVisibleMap(
    rules: SnapshotFieldRule[],
    defsByKey: Record<string, RuleFieldDefinition>,
    headerValues: Record<string, unknown>,
    positionValues: Record<string, unknown>,
    scope: FieldScope,
    basisVisible: Record<string, boolean>,
): Record<string, boolean> {
    assertRuleset(defsByKey, rules, false);
    const effective = { ...basisVisible };

    for (const rule of rules) {
        if (rule.action?.op !== 'set_visible') {
            continue;
        }
        const targetKey =
            typeof rule.action.field_key === 'string'
                ? rule.action.field_key
                : '';
        const def = defsByKey[targetKey];
        if (!def) {
            throw new Error(
                `Regelaktion referenziert unbekanntes Feld „${targetKey}“.`,
            );
        }
        if (def.scope !== scope) {
            continue;
        }
        if (
            !conditionMatches(
                rule.condition,
                headerValues,
                positionValues,
                defsByKey,
            )
        ) {
            continue;
        }
        effective[targetKey] = Boolean(rule.action.value);
    }

    return effective;
}

export function effectiveRequiredMap(
    rules: SnapshotFieldRule[],
    defsByKey: Record<string, RuleFieldDefinition>,
    headerValues: Record<string, unknown>,
    positionValues: Record<string, unknown>,
    scope: FieldScope,
    basisRequired: Record<string, boolean>,
    effectiveVisible: Record<string, boolean>,
): Record<string, boolean> {
    assertRuleset(defsByKey, rules, false);
    const effective = { ...basisRequired };

    for (const rule of rules) {
        if (rule.action?.op !== 'require_field') {
            continue;
        }
        const targetKey =
            typeof rule.action.field_key === 'string'
                ? rule.action.field_key
                : '';
        const def = defsByKey[targetKey];
        if (!def) {
            throw new Error(
                `Regelaktion referenziert unbekanntes Feld „${targetKey}“.`,
            );
        }
        if (def.scope !== scope) {
            continue;
        }
        if (
            !conditionMatches(
                rule.condition,
                headerValues,
                positionValues,
                defsByKey,
            )
        ) {
            continue;
        }
        effective[targetKey] = true;
    }

    for (const key of Object.keys(effective)) {
        if (effective[key] && !effectiveVisible[key]) {
            effective[key] = false;
        }
    }

    return effective;
}
