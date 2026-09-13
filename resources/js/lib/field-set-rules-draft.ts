/**
 * DF-3-RULE-C: lokaler Draft-Zustand für Feldset-Regeln (Desired State).
 * Serverseitige Wahrheit bleibt FieldRuleContract / RulesWriter.
 */

export type FieldCatalogEntry = {
    key: string;
    label: string;
    field_type: string;
    scope: string;
    is_system: boolean;
    options: Array<{
        key: string;
        label: string;
        sort: number;
        is_active: boolean;
    }>;
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

export type DraftRule = {
    localId: string;
    condition: RuleCondition;
    action: RuleAction;
    is_system_seed: boolean;
};

export type ServerRule = {
    id?: number;
    sort?: number;
    condition: Record<string, unknown>;
    action: Record<string, unknown>;
    dedupe_key?: string;
    is_system_seed?: boolean;
    summary?: string;
};

let localIdCounter = 0;

export function nextLocalId(): string {
    localIdCounter += 1;

    return `local-${localIdCounter}`;
}

export function rulesFromServer(rules: ServerRule[]): DraftRule[] {
    return rules.map((rule) => ({
        localId: nextLocalId(),
        condition: rule.condition as RuleCondition,
        action: rule.action as RuleAction,
        is_system_seed: rule.is_system_seed === true,
    }));
}

export function toPayloadRules(
    rules: DraftRule[],
): Array<{ condition: RuleCondition; action: RuleAction }> {
    return rules.map((rule) => ({
        condition: rule.condition,
        action: rule.action,
    }));
}

export function emptyAtomicCondition(fieldKey = ''): AtomicCondition {
    return { op: 'field_equals', field_key: fieldKey, value: true };
}

export function emptyDraftRule(fieldKey = ''): DraftRule {
    return {
        localId: nextLocalId(),
        condition: emptyAtomicCondition(fieldKey),
        action: { op: 'require_field', field_key: fieldKey },
        is_system_seed: false,
    };
}

export function duplicateDraftRule(rule: DraftRule): DraftRule {
    return {
        localId: nextLocalId(),
        condition: structuredClone(rule.condition),
        action: structuredClone(rule.action),
        is_system_seed: false,
    };
}

export function moveDraftRule(
    rules: DraftRule[],
    index: number,
    direction: -1 | 1,
): DraftRule[] {
    const target = index + direction;
    if (index < 0 || target < 0 || index >= rules.length || target >= rules.length) {
        return rules;
    }
    if (rules[index]?.is_system_seed || rules[target]?.is_system_seed) {
        return rules;
    }
    const next = [...rules];
    const [row] = next.splice(index, 1);
    next.splice(target, 0, row);

    return next;
}

export function allowedConditionOps(fieldType: string): string[] {
    if (fieldType === 'multi_select') {
        return ['field_contains', 'field_empty', 'field_not_empty'];
    }
    if (fieldType === 'period') {
        return ['field_empty', 'field_not_empty'];
    }
    if (fieldType === 'boolean' || fieldType === 'select') {
        return ['field_equals', 'field_empty', 'field_not_empty'];
    }
    if (fieldType === 'short_text' || fieldType === 'long_text') {
        return ['field_equals', 'field_empty', 'field_not_empty'];
    }

    return ['field_empty', 'field_not_empty'];
}

export function fieldLabel(
    catalog: FieldCatalogEntry[],
    key: string,
): string {
    const entry = catalog.find((row) => row.key === key);

    return entry ? `${entry.label} (${entry.key})` : key;
}

export function summarizeRule(
    rule: DraftRule,
    catalog: FieldCatalogEntry[],
): string {
    const actionLabel =
        rule.action.op === 'set_visible'
            ? `${fieldLabel(catalog, rule.action.field_key)} ${rule.action.value ? 'sichtbar' : 'unsichtbar'}`
            : `${fieldLabel(catalog, rule.action.field_key)} Pflicht`;

    return `Wenn ${summarizeCondition(rule.condition, catalog)}, dann ${actionLabel}.`;
}

function summarizeCondition(
    condition: RuleCondition,
    catalog: FieldCatalogEntry[],
): string {
    if (condition.op === 'all' || condition.op === 'any') {
        const joiner = condition.op === 'all' ? ' und ' : ' oder ';
        return condition.conditions
            .map((child) => summarizeCondition(child, catalog))
            .join(joiner);
    }
    const label = fieldLabel(catalog, condition.field_key);
    if (condition.op === 'field_empty') {
        return `${label} leer`;
    }
    if (condition.op === 'field_not_empty') {
        return `${label} nicht leer`;
    }
    if (condition.op === 'field_contains') {
        return `${label} enthält ${condition.value}`;
    }
    const value =
        typeof condition.value === 'boolean'
            ? condition.value
                ? 'ja'
                : 'nein'
            : String(condition.value);

    return `${label} = ${value}`;
}

export function hasRequireAndHiddenWarning(rules: DraftRule[]): string[] {
    const required = new Set<string>();
    const hidden = new Set<string>();
    for (const rule of rules) {
        if (rule.action.op === 'require_field') {
            required.add(rule.action.field_key);
        }
        if (rule.action.op === 'set_visible' && rule.action.value === false) {
            hidden.add(rule.action.field_key);
        }
    }

    return [...required].filter((key) => hidden.has(key));
}
