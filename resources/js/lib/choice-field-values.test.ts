import { describe, expect, it } from 'vitest';
import {
    MULTI_SELECT_MAX,
    canonicalizeMultiKeys,
    choiceValuesForPayload,
    diagnoseChoiceField,
    filterOptionsBySearch,
    initChoiceValueFromStored,
    multiKeysEqual,
    selectableSelectOptions,
    selectValuesEqual,
    sortChoiceOptions,
    visibleChoiceFields,
    type SchemaChoiceField,
} from './choice-field-values';

const options = [
    { key: 'opt_b', label: 'Beta', sort: 2, is_active: true },
    { key: 'opt_a', label: 'Alpha', sort: 1, is_active: true },
    { key: 'opt_old', label: 'Alt', sort: 3, is_active: false },
];

const selectField: SchemaChoiceField = {
    key: 'hdr_select',
    label: 'Auswahl',
    field_type: 'select',
    required: true,
    options_json: options,
    visible: true,
};

const multiField: SchemaChoiceField = {
    key: 'hdr_multi',
    label: 'Mehrfach',
    field_type: 'multi_select',
    required: true,
    options_json: options,
    visible: true,
};

describe('choice-field-values select', () => {
    it('sorts active options by sort then key', () => {
        expect(sortChoiceOptions(options).map((row) => row.key)).toEqual([
            'opt_a',
            'opt_b',
            'opt_old',
        ]);
    });

    it('allows clearing and keeps historical inactive selectable while current', () => {
        const selectable = selectableSelectOptions(options, 'opt_old');
        expect(selectable.map((row) => row.key)).toEqual([
            'opt_a',
            'opt_b',
            'opt_old',
        ]);
        expect(
            selectableSelectOptions(options, 'opt_a').map((row) => row.key),
        ).toEqual(['opt_a', 'opt_b']);
    });

    it('initializes select string|null without coercing arrays', () => {
        expect(initChoiceValueFromStored('select', 'opt_a')).toEqual({
            value: 'opt_a',
            issue: null,
        });
        expect(initChoiceValueFromStored('select', null)).toEqual({
            value: null,
            issue: null,
        });
        expect(initChoiceValueFromStored('select', ['opt_a']).issue).toBe(
            'invalid_value_type',
        );
    });

    it('marks required without blocking empty draft payload', () => {
        expect(selectField.required).toBe(true);
        expect(
            choiceValuesForPayload([selectField], { hdr_select: null }),
        ).toEqual({ hdr_select: null });
    });
});

describe('choice-field-values multi', () => {
    it('canonicalizes and compares multi keys set-wise', () => {
        expect(canonicalizeMultiKeys(['opt_b', 'opt_a', 'opt_b'])).toEqual([
            'opt_a',
            'opt_b',
        ]);
        expect(multiKeysEqual(['opt_b', 'opt_a'], ['opt_a', 'opt_b'])).toBe(
            true,
        );
        expect(multiKeysEqual(['opt_a'], ['opt_a', 'opt_b'])).toBe(false);
    });

    it('blocks more than 50 keys via diagnose', () => {
        const keys = Array.from({ length: MULTI_SELECT_MAX + 1 }, (_, i) =>
            `k${String(i).padStart(2, '0')}`,
        );
        const field: SchemaChoiceField = {
            ...multiField,
            options_json: keys.map((key, index) => ({
                key,
                label: key,
                sort: index,
                is_active: true,
            })),
        };
        expect(diagnoseChoiceField(field, keys)).toBe('multi_over_limit');
        expect(initChoiceValueFromStored('multi_select', keys).issue).toBe(
            'multi_over_limit',
        );
    });

    it('sends explicit empty array and omits nothing when field listed', () => {
        expect(
            choiceValuesForPayload([multiField], { hdr_multi: [] }),
        ).toEqual({ hdr_multi: [] });
        expect(
            choiceValuesForPayload([multiField], {
                hdr_multi: ['opt_b', 'opt_a'],
            }),
        ).toEqual({ hdr_multi: ['opt_a', 'opt_b'] });
        expect(choiceValuesForPayload([], { hdr_multi: [] })).toEqual({});
    });

    it('rejects wrong stored types for multi', () => {
        expect(initChoiceValueFromStored('multi_select', 'opt_a').issue).toBe(
            'invalid_value_type',
        );
    });

    it('filters local search by label and key without mutating options', () => {
        const source = sortChoiceOptions(options);
        expect(filterOptionsBySearch(source, 'alpha').map((o) => o.key)).toEqual([
            'opt_a',
        ]);
        expect(filterOptionsBySearch(source, 'OPT_B').map((o) => o.key)).toEqual([
            'opt_b',
        ]);
        expect(filterOptionsBySearch(source, 'zzz')).toEqual([]);
        expect(source).toHaveLength(3);
    });
});

describe('choice-field-values visibility and equality', () => {
    it('hides invisible fields for render but keeps them in source list', () => {
        const hidden: SchemaChoiceField = {
            ...selectField,
            key: 'hidden',
            visible: false,
        };
        expect(visibleChoiceFields([selectField, hidden])).toEqual([
            selectField,
        ]);
    });

    it('compares select values strictly', () => {
        expect(selectValuesEqual(null, null)).toBe(true);
        expect(selectValuesEqual('a', 'a')).toBe(true);
        expect(selectValuesEqual(null, 'a')).toBe(false);
    });
});
