import { describe, expect, it } from 'vitest';
import {
    MULTI_SELECT_MAX,
    canonicalizeMultiKeys,
    choiceFieldsFromCustomBucket,
    choiceReadOnlyDisplay,
    choiceValuesForPayload,
    diagnoseChoiceField,
    filterOptionsBySearch,
    initChoiceValueFromStored,
    multiKeysEqual,
    selectableSelectOptions,
    selectValuesEqual,
    sortChoiceOptions,
    sortMultiKeysForDisplay,
    textFieldsFromCustomBucket,
    textReadOnlyCapturedDisplay,
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
            initPayloadSafe: true,
        });
        expect(initChoiceValueFromStored('select', null)).toEqual({
            value: null,
            issue: null,
            initPayloadSafe: true,
        });
        expect(initChoiceValueFromStored('select', ['opt_a']).issue).toBe(
            'invalid_value_type',
        );
        expect(
            initChoiceValueFromStored('select', ['opt_a']).initPayloadSafe,
        ).toBe(false);
    });

    it('marks required without blocking empty draft payload when touched', () => {
        expect(selectField.required).toBe(true);
        expect(
            choiceValuesForPayload(
                [selectField],
                { hdr_select: null },
                new Set(['hdr_select']),
            ),
        ).toEqual({
            payload: { hdr_select: null },
            blockReason: null,
        });
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
        const keys = Array.from(
            { length: MULTI_SELECT_MAX + 1 },
            (_, i) => `k${String(i).padStart(2, '0')}`,
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
        expect(
            initChoiceValueFromStored('multi_select', keys).initPayloadSafe,
        ).toBe(false);
    });

    it('sends explicit empty array only when touched; omits untouched keys', () => {
        expect(
            choiceValuesForPayload(
                [multiField],
                { hdr_multi: [] },
                new Set(['hdr_multi']),
            ),
        ).toEqual({
            payload: { hdr_multi: [] },
            blockReason: null,
        });
        expect(
            choiceValuesForPayload(
                [multiField],
                { hdr_multi: ['opt_b', 'opt_a'] },
                new Set(),
            ),
        ).toEqual({ payload: {}, blockReason: null });
        expect(
            choiceValuesForPayload(
                [multiField],
                {
                    hdr_multi: ['opt_b', 'opt_a'],
                },
                new Set(['hdr_multi']),
            ),
        ).toEqual({
            payload: { hdr_multi: ['opt_a', 'opt_b'] },
            blockReason: null,
        });
    });

    it('rejects wrong stored types for multi without treating as clear', () => {
        const init = initChoiceValueFromStored('multi_select', 'opt_a');
        expect(init.issue).toBe('invalid_value_type');
        expect(init.initPayloadSafe).toBe(false);
    });

    it('does not silently dedupe damaged multi server rows', () => {
        const init = initChoiceValueFromStored('multi_select', [
            'opt_a',
            'opt_a',
        ]);
        expect(init.issue).toBe('invalid_value_type');
        expect(init.initPayloadSafe).toBe(false);
        expect(init.value).toEqual(['opt_a', 'opt_a']);
    });

    it('blocks touched integrity values instead of sending null/[]', () => {
        const broken: SchemaChoiceField = {
            ...selectField,
            options_json: null,
        };
        const result = choiceValuesForPayload(
            [broken],
            { hdr_select: null },
            new Set(['hdr_select']),
        );
        expect(result.payload).toEqual({});
        expect(result.blockReason).toMatch(/fehlen eingefrorene Optionen/i);
    });

    it('filters local search by label and key without mutating options', () => {
        const source = sortChoiceOptions(options);
        expect(
            filterOptionsBySearch(source, 'alpha').map((o) => o.key),
        ).toEqual(['opt_a']);
        expect(
            filterOptionsBySearch(source, 'OPT_B').map((o) => o.key),
        ).toEqual(['opt_b']);
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

describe('choice-field-values custom bucket partition', () => {
    it('partitions text and choice fields from a custom bucket', () => {
        const bucket = [
            {
                key: 'txt',
                label: 'Text',
                field_type: 'short_text',
                sort: 1,
                max_length: 40,
            },
            {
                key: 'sel',
                label: 'Auswahl',
                field_type: 'select',
                sort: 2,
                options_json: options,
            },
            {
                key: 'sys',
                label: 'System',
                field_type: 'period',
                sort: 3,
            },
        ];

        expect(
            textFieldsFromCustomBucket(bucket).map((row) => row.key),
        ).toEqual(['txt']);
        expect(
            choiceFieldsFromCustomBucket(bucket).map((row) => row.key),
        ).toEqual(['sel']);
        expect(
            choiceFieldsFromCustomBucket(bucket)[0]?.options_json?.map(
                (option) => option.key,
            ),
        ).toEqual(['opt_b', 'opt_a', 'opt_old']);
    });
});

describe('choice-field-values display helpers', () => {
    it('sorts multi keys by options sort not key alpha', () => {
        expect(
            sortMultiKeysForDisplay(
                ['opt_a', 'opt_b'],
                [
                    { key: 'opt_a', label: 'A', sort: 20, is_active: true },
                    { key: 'opt_b', label: 'B', sort: 10, is_active: true },
                ],
            ),
        ).toEqual(['opt_b', 'opt_a']);
        expect(sortMultiKeysForDisplay(['z', 'a'], null)).toEqual(['a', 'z']);
    });

    it('renders choiceReadOnlyDisplay for uncaptured empty inactive multi and integrity', () => {
        expect(choiceReadOnlyDisplay(selectField, 'opt_a', false)).toEqual({
            kind: 'uncaptured',
            text: 'Nicht erfasst',
        });
        expect(choiceReadOnlyDisplay(selectField, null, true)).toEqual({
            kind: 'empty',
            text: '–',
        });
        expect(choiceReadOnlyDisplay(selectField, 'opt_old', true)).toEqual({
            kind: 'select',
            label: 'Alt',
            inactive: true,
        });
        expect(
            choiceReadOnlyDisplay(multiField, ['opt_b', 'opt_a'], true),
        ).toEqual({
            kind: 'multi',
            items: [
                { key: 'opt_a', label: 'Alpha', inactive: false },
                { key: 'opt_b', label: 'Beta', inactive: false },
            ],
        });
        expect(
            choiceReadOnlyDisplay(
                { ...selectField, options_json: null },
                'opt_a',
                true,
            ).kind,
        ).toBe('integrity');
    });

    it('renders textReadOnlyCapturedDisplay for missing and empty values', () => {
        expect(textReadOnlyCapturedDisplay('Hallo', false)).toBe(
            'Nicht erfasst',
        );
        expect(textReadOnlyCapturedDisplay(null, true)).toBe('–');
        expect(textReadOnlyCapturedDisplay('  ', true)).toBe('–');
        expect(textReadOnlyCapturedDisplay('Hallo', true)).toBe('Hallo');
    });
});
