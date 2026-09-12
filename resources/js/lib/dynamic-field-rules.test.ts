import { describe, expect, it } from 'vitest';
import {
    assertRuleStructure,
    assertRuleset,
    conditionMatches,
    effectiveRequiredMap,
    effectiveVisibleMap,
    requiredPositionFieldKeysFromSnapshotRules,
    type RuleFieldDefinition,
    type SnapshotFieldRule,
} from './dynamic-field-rules';

const periodOpenRule: SnapshotFieldRule = {
    condition: {
        op: 'field_equals',
        field_key: 'period_open',
        value: false,
    },
    action: {
        op: 'require_field',
        field_key: 'position_flight_period',
    },
};

const positionDefs: Record<string, RuleFieldDefinition> = {
    period_open: {
        key: 'period_open',
        field_type: 'boolean',
        scope: 'position',
    },
    position_flight_period: {
        key: 'position_flight_period',
        field_type: 'period',
        scope: 'position',
    },
    tags: {
        key: 'tags',
        field_type: 'multi_select',
        scope: 'position',
        options_json: [
            { key: 'opt_a', is_active: true },
            { key: 'opt_b', is_active: true },
        ],
    },
    notes: {
        key: 'notes',
        field_type: 'short_text',
        scope: 'position',
    },
};

describe('dynamic-field-rules', () => {
    it('requires flight period when period_open is false via legacy seed adapter', () => {
        expect(
            requiredPositionFieldKeysFromSnapshotRules([periodOpenRule], {
                period_open: false,
            }),
        ).toEqual(['position_flight_period']);
    });

    it('does not require flight period when period_open is true', () => {
        expect(
            requiredPositionFieldKeysFromSnapshotRules([periodOpenRule], {
                period_open: true,
            }),
        ).toEqual([]);
    });

    it('rejects malformed legacy rules fail-closed', () => {
        expect(() =>
            requiredPositionFieldKeysFromSnapshotRules(
                [
                    {
                        condition: {
                            op: 'unknown_op',
                            field_key: 'period_open',
                            value: false,
                        },
                        action: {
                            op: 'require_field',
                            field_key: 'position_flight_period',
                        },
                    },
                ],
                { period_open: false },
            ),
        ).toThrow(/Legacy-Wizard|ungültige/);
    });

    it('matches with full defs parity including field_contains', () => {
        const rule: SnapshotFieldRule = {
            condition: {
                op: 'field_contains',
                field_key: 'tags',
                value: 'opt_a',
            },
            action: { op: 'require_field', field_key: 'notes' },
        };

        expect(
            requiredPositionFieldKeysFromSnapshotRules(
                [rule],
                { tags: ['opt_b', 'opt_a'] },
                positionDefs,
            ),
        ).toEqual(['notes']);
        expect(
            requiredPositionFieldKeysFromSnapshotRules(
                [rule],
                { tags: ['opt_b'] },
                positionDefs,
            ),
        ).toEqual([]);
    });

    it('evaluates flat all/any conditions after validation', () => {
        const allRule = {
            op: 'all' as const,
            conditions: [
                {
                    op: 'field_equals' as const,
                    field_key: 'period_open',
                    value: false,
                },
                {
                    op: 'field_empty' as const,
                    field_key: 'notes',
                },
            ],
        };

        assertRuleStructure(
            positionDefs,
            allRule,
            { op: 'require_field', field_key: 'notes' },
            false,
        );

        expect(
            conditionMatches(
                allRule,
                {},
                { period_open: false, notes: null },
                positionDefs,
            ),
        ).toBe(true);
    });

    it('rejects empty groups, nested groups, extras and multi-equals', () => {
        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'all', conditions: [] },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/mindestens/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                {
                    op: 'all',
                    conditions: [
                        { op: 'field_empty', field_key: 'notes' },
                    ],
                },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/mindestens/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                {
                    op: 'all',
                    conditions: Array.from({ length: 9 }, () => ({
                        op: 'field_empty' as const,
                        field_key: 'notes',
                    })),
                },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/höchstens/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                {
                    op: 'all',
                    conditions: [
                        { op: 'field_empty', field_key: 'notes' },
                        {
                            op: 'any',
                            conditions: [
                                { op: 'field_empty', field_key: 'notes' },
                                { op: 'field_not_empty', field_key: 'notes' },
                            ],
                        },
                    ],
                },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/Verschachtelte/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                    extra: true,
                },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/unerwartetes Attribut/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'field_equals', field_key: 'tags', value: 'opt_a' },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/field_contains/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                {
                    op: 'field_contains',
                    field_key: 'notes',
                    value: 'x',
                },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/Multi-Select/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'field_equals', field_key: 'period_open', value: 'nope' },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/Boolean/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'field_equals', field_key: 'missing', value: true },
                { op: 'require_field', field_key: 'notes' },
            ),
        ).toThrow(/unbekanntes Feld/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'field_equals', field_key: 'period_open', value: false },
                { op: 'require_field', field_key: 'ghost' },
            ),
        ).toThrow(/unbekanntes Feld/);

        expect(() =>
            assertRuleStructure(
                positionDefs,
                { op: 'field_equals', field_key: 'period_open', value: false },
                { op: 'unknown_action', field_key: 'notes' },
            ),
        ).toThrow(/Aktionsoperator/);
    });

    it('applies set_visible over basis and DYN-005 for required', () => {
        const rules: SnapshotFieldRule[] = [
            {
                condition: {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                action: {
                    op: 'set_visible',
                    field_key: 'notes',
                    value: false,
                },
            },
            {
                condition: {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                action: { op: 'require_field', field_key: 'notes' },
            },
        ];

        assertRuleset(positionDefs, rules, false);

        const visible = effectiveVisibleMap(
            rules,
            positionDefs,
            {},
            { period_open: false },
            'position',
            { notes: true, period_open: true },
        );
        expect(visible.notes).toBe(false);

        const required = effectiveRequiredMap(
            rules,
            positionDefs,
            {},
            { period_open: false },
            'position',
            { notes: false },
            visible,
        );
        expect(required.notes).toBe(false);
    });

    it('rejects calc-origin action targets except exact seed', () => {
        const readonlyDefs: Record<string, RuleFieldDefinition> = {
            ...positionDefs,
            period_open: {
                ...positionDefs.period_open,
                action_target_readonly: true,
            },
            position_flight_period: {
                ...positionDefs.position_flight_period,
                action_target_readonly: true,
            },
            custom_from_calc: {
                key: 'custom_from_calc',
                field_type: 'short_text',
                scope: 'position',
                action_target_readonly: true,
            },
        };

        expect(() =>
            assertRuleStructure(
                readonlyDefs,
                {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                {
                    op: 'set_visible',
                    field_key: 'position_flight_period',
                    value: false,
                },
            ),
        ).toThrow(/Calc-Origin/);

        expect(() =>
            assertRuleStructure(
                readonlyDefs,
                { op: 'field_empty', field_key: 'period_open' },
                {
                    op: 'require_field',
                    field_key: 'position_flight_period',
                },
            ),
        ).toThrow(/Calc-Origin/);

        expect(() =>
            assertRuleStructure(
                readonlyDefs,
                {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                { op: 'require_field', field_key: 'custom_from_calc' },
            ),
        ).toThrow(/Calc-Origin/);

        expect(() =>
            assertRuleStructure(
                readonlyDefs,
                {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                {
                    op: 'require_field',
                    field_key: 'position_flight_period',
                },
            ),
        ).not.toThrow();
    });
});
