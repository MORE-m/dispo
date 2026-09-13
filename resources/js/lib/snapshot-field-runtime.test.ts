import { describe, expect, it } from 'vitest';
import {
    applyEffectiveRequired,
    evaluateSnapshotFieldRuntime,
    filterByEffectiveVisible,
} from './snapshot-field-runtime';
import type { SnapshotFieldRule } from './dynamic-field-rules';

describe('snapshot-field-runtime', () => {
    const fields = [
        {
            key: 'flag',
            field_type: 'boolean',
            label: 'Flag',
            scope: 'header',
            visible: true,
            required: false,
            action_target_readonly: false,
        },
        {
            key: 'title',
            field_type: 'short_text',
            label: 'Titel',
            scope: 'header',
            visible: false,
            required: true,
            action_target_readonly: false,
        },
    ];

    const showTitleWhenFlag: SnapshotFieldRule[] = [
        {
            condition: { op: 'field_equals', field_key: 'flag', value: true },
            action: { op: 'set_visible', field_key: 'title', value: true },
        },
    ];

    it('shows basis-hidden field when set_visible matches', () => {
        const runtime = evaluateSnapshotFieldRuntime({
            fields,
            rules: showTitleWhenFlag,
            scope: 'header',
            headerValues: { flag: true, title: 'x' },
        });

        expect(runtime.integrityError).toBeNull();
        expect(runtime.isFieldVisible('title')).toBe(true);
        expect(runtime.isFieldRequired('title')).toBe(true);

        const visible = applyEffectiveRequired(
            filterByEffectiveVisible(
                fields.filter((field) => field.key === 'title'),
                runtime,
            ),
            runtime,
        );
        expect(visible).toHaveLength(1);
        expect(visible[0]?.required).toBe(true);
    });

    it('hides field and clears effective required when set_visible false', () => {
        const rules: SnapshotFieldRule[] = [
            {
                condition: { op: 'field_equals', field_key: 'flag', value: true },
                action: { op: 'set_visible', field_key: 'title', value: false },
            },
        ];
        const runtime = evaluateSnapshotFieldRuntime({
            fields: fields.map((field) =>
                field.key === 'title'
                    ? { ...field, visible: true, required: true }
                    : field,
            ),
            rules,
            scope: 'header',
            headerValues: { flag: true, title: null },
        });

        expect(runtime.isFieldVisible('title')).toBe(false);
        expect(runtime.isFieldRequired('title')).toBe(false);
    });

    it('fail-closed on unknown operator', () => {
        const runtime = evaluateSnapshotFieldRuntime({
            fields,
            rules: [
                {
                    condition: { op: 'nope', field_key: 'flag' },
                    action: { op: 'require_field', field_key: 'title' },
                },
            ] as SnapshotFieldRule[],
            scope: 'header',
            headerValues: { flag: true },
        });

        expect(runtime.integrityError).not.toBeNull();
        expect(runtime.isFieldVisible('title')).toBe(false);
    });

    it('header pass stays intact when definitionFields cover DF-1 position seed', () => {
        const df1: SnapshotFieldRule = {
            condition: {
                op: 'field_equals',
                field_key: 'period_open',
                value: false,
            },
            action: { op: 'require_field', field_key: 'position_flight_period' },
        };
        const withoutCatalog = evaluateSnapshotFieldRuntime({
            fields,
            rules: [df1, ...showTitleWhenFlag],
            scope: 'header',
            headerValues: { flag: true, title: 'x' },
        });
        expect(withoutCatalog.integrityError).not.toBeNull();

        const withCatalog = evaluateSnapshotFieldRuntime({
            fields,
            definitionFields: [
                ...fields,
                {
                    key: 'period_open',
                    field_type: 'boolean',
                    label: 'Zeitraum offen',
                    scope: 'position',
                    visible: true,
                    action_target_readonly: false,
                },
                {
                    key: 'position_flight_period',
                    field_type: 'period',
                    label: 'Flugzeitraum',
                    scope: 'position',
                    visible: true,
                    action_target_readonly: false,
                },
            ],
            rules: [df1, ...showTitleWhenFlag],
            scope: 'header',
            headerValues: { flag: true, title: 'x' },
        });
        expect(withCatalog.integrityError).toBeNull();
        expect(withCatalog.isFieldVisible('title')).toBe(true);
    });
});
