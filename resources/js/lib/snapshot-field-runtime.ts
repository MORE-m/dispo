/**
 * DF-3-RULE-B: gemeinsame Client-Auswertung Effective Visible/Required.
 * Baut auf dynamic-field-rules.ts (Parität zu PHP); Controls bleiben regel-frei.
 */

import {
    type FieldScope,
    type FieldType,
    type RuleFieldDefinition,
    type SnapshotFieldRule,
    assertRuleset,
    effectiveRequiredMap,
    effectiveVisibleMap,
} from '@/lib/dynamic-field-rules';

export const RULES_INTEGRITY_MESSAGE =
    'Die Feldregeln dieses Vorgangs sind ungültig. Speichern ist nicht möglich.';

export type RuntimeSchemaField = {
    key: string;
    field_type: string;
    label: string;
    help_text?: string | null;
    scope?: string;
    sort?: number;
    is_system?: boolean;
    required?: boolean;
    visible?: boolean;
    editable?: boolean;
    calc_origin?: boolean;
    action_target_readonly?: boolean;
    max_length?: number | null;
    validation_json?: { max_length?: number } | null;
    options_json?: unknown;
    group_key?: string | null;
    applies_to?: string;
};

export type SnapshotFieldRuntimeResult = {
    defsByKey: Record<string, RuleFieldDefinition>;
    effectiveVisible: Record<string, boolean>;
    effectiveRequired: Record<string, boolean>;
    integrityError: string | null;
    isFieldVisible: (key: string) => boolean;
    isFieldRequired: (key: string) => boolean;
};

function asFieldType(value: string): FieldType | null {
    switch (value) {
        case 'period':
        case 'boolean':
        case 'short_text':
        case 'long_text':
        case 'select':
        case 'multi_select':
            return value;
        default:
            return null;
    }
}

function asScope(value: string | undefined, fallback: FieldScope): FieldScope {
    if (value === 'header' || value === 'position') {
        return value;
    }

    return fallback;
}

export function toRuleFieldDefinition(
    field: RuntimeSchemaField,
    fallbackScope: FieldScope,
): RuleFieldDefinition | null {
    const fieldType = asFieldType(field.field_type);
    if (fieldType === null || field.key === '') {
        return null;
    }

    const readonly =
        field.action_target_readonly === true || field.calc_origin === true;

    return {
        key: field.key,
        field_type: fieldType,
        scope: asScope(field.scope, fallbackScope),
        options_json: Array.isArray(field.options_json)
            ? (field.options_json as Array<{
                  key: string;
                  is_active?: boolean;
              }>)
            : null,
        action_target_readonly: readonly,
    };
}

export function buildDefsByKey(
    fields: RuntimeSchemaField[],
    fallbackScope: FieldScope,
): Record<string, RuleFieldDefinition> {
    const defs: Record<string, RuleFieldDefinition> = {};
    for (const field of fields) {
        const def = toRuleFieldDefinition(field, fallbackScope);
        if (def === null) {
            continue;
        }
        defs[def.key] = def;
    }

    return defs;
}

function basisMap(
    fields: RuntimeSchemaField[],
    scope: FieldScope,
    flag: 'visible' | 'required',
): Record<string, boolean> {
    const map: Record<string, boolean> = {};
    for (const field of fields) {
        if (asScope(field.scope, scope) !== scope) {
            continue;
        }
        map[field.key] =
            flag === 'visible'
                ? field.visible !== false
                : field.required === true;
    }

    return map;
}

function dedupeRules(rules: SnapshotFieldRule[]): SnapshotFieldRule[] {
    const seen = new Set<string>();
    const out: SnapshotFieldRule[] = [];
    for (const rule of rules) {
        const key = JSON.stringify({
            condition: rule.condition,
            action: rule.action,
        });
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);
        out.push(rule);
    }

    return out;
}

/**
 * Wertet Effective Visible/Required für genau einen Scope-Kontext aus.
 * Für Positionen: conditionFields/additionalRules = Header-Basis (Header→Position).
 *
 * `definitionFields` (optional) liefert den vollständigen Def-Katalog für
 * Ruleset-Integrity (z. B. Header-Pass braucht Positionsfelder der DF-1-Seed).
 * Basis Visible/Required stammen weiterhin nur aus `fields` im Ziel-Scope.
 */
export function evaluateSnapshotFieldRuntime(args: {
    fields: RuntimeSchemaField[];
    rules: SnapshotFieldRule[];
    scope: FieldScope;
    headerValues: Record<string, unknown>;
    positionValues?: Record<string, unknown>;
    conditionFields?: RuntimeSchemaField[];
    additionalRules?: SnapshotFieldRule[];
    definitionFields?: RuntimeSchemaField[];
    /** Serverseitig erkanntes Ruleset-/Snapshot-Integrity-Problem. */
    serverIntegrityError?: string | null;
}): SnapshotFieldRuntimeResult {
    const positionValues = args.positionValues ?? {};
    const catalogFields = args.definitionFields ?? [
        ...(args.conditionFields ?? []),
        ...args.fields,
    ];
    const defsByKey = buildDefsByKey(catalogFields, args.scope);
    const rules = dedupeRules([...(args.additionalRules ?? []), ...args.rules]);

    const empty: SnapshotFieldRuntimeResult = {
        defsByKey,
        effectiveVisible: basisMap(args.fields, args.scope, 'visible'),
        effectiveRequired: {},
        integrityError: null,
        isFieldVisible: (key) =>
            basisMap(args.fields, args.scope, 'visible')[key] ?? false,
        isFieldRequired: () => false,
    };

    if (args.serverIntegrityError) {
        return {
            ...empty,
            integrityError: args.serverIntegrityError,
            isFieldVisible: () => false,
            isFieldRequired: () => false,
        };
    }

    if (Object.keys(defsByKey).length === 0) {
        return empty;
    }

    try {
        assertRuleset(defsByKey, rules, false);
        const basisVisible = basisMap(args.fields, args.scope, 'visible');
        const basisRequired = basisMap(args.fields, args.scope, 'required');
        const effectiveVisible = effectiveVisibleMap(
            rules,
            defsByKey,
            args.headerValues,
            positionValues,
            args.scope,
            basisVisible,
        );
        const effectiveRequired = effectiveRequiredMap(
            rules,
            defsByKey,
            args.headerValues,
            positionValues,
            args.scope,
            basisRequired,
            effectiveVisible,
        );

        return {
            defsByKey,
            effectiveVisible,
            effectiveRequired,
            integrityError: null,
            isFieldVisible: (key) => effectiveVisible[key] ?? false,
            isFieldRequired: (key) => effectiveRequired[key] ?? false,
        };
    } catch {
        return {
            ...empty,
            integrityError: RULES_INTEGRITY_MESSAGE,
            isFieldVisible: () => false,
            isFieldRequired: () => false,
        };
    }
}

export function filterByEffectiveVisible<T extends { key: string }>(
    fields: T[],
    runtime: Pick<
        SnapshotFieldRuntimeResult,
        'isFieldVisible' | 'integrityError'
    >,
): T[] {
    if (runtime.integrityError) {
        return [];
    }

    return fields.filter((field) => runtime.isFieldVisible(field.key));
}

export function applyEffectiveRequired<
    T extends { key: string; required?: boolean },
>(
    fields: T[],
    runtime: Pick<SnapshotFieldRuntimeResult, 'isFieldRequired'>,
): Array<T & { required: boolean }> {
    return fields.map((field) => ({
        ...field,
        required: runtime.isFieldRequired(field.key),
    }));
}
