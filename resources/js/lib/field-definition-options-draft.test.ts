import { describe, expect, it } from 'vitest';
import {
    applyLabelChange,
    buildLocalChangeSummary,
    canEditKey,
    canRemoveDraftRow,
    createEmptyDraft,
    hasZeroActiveWarning,
    parseStrictSortInput,
    rowsFromServer,
    slugFromLabel,
    toPayloadOptions,
    type DraftOptionRow,
} from './field-definition-options-draft';

describe('field-definition-options-draft', () => {
    it('suggests key from label like slugFromLabel', () => {
        expect(slugFromLabel('Hallo Welt')).toBe('hallo_welt');
        expect(slugFromLabel('Äpfel & Birnen!')).toBe('apfel_birnen');
    });

    it('does not overwrite manually touched draft keys on later label changes', () => {
        const draft: DraftOptionRow = {
            ...createEmptyDraft(10),
            label: 'Eins',
            key: 'eins',
            keyTouched: false,
        };
        const suggested = applyLabelChange(draft, 'Zwei');
        expect(suggested.key).toBe('zwei');

        const touched = applyLabelChange(
            { ...suggested, keyTouched: true, key: 'custom_key' },
            'Drei',
        );
        expect(touched.key).toBe('custom_key');
        expect(touched.label).toBe('Drei');
    });

    it('marks persisted keys read-only and only deactivatable', () => {
        const [persisted] = rowsFromServer([
            { key: 'a', label: 'A', sort: 1, is_active: true },
        ]);
        expect(canEditKey(persisted)).toBe(false);
        expect(canRemoveDraftRow(persisted)).toBe(false);

        const unsaved = createEmptyDraft(2);
        expect(canEditKey(unsaved)).toBe(true);
        expect(canRemoveDraftRow(unsaved)).toBe(true);
    });

    it('builds deterministic change summary and dirty/noop detection', () => {
        const previous = [
            { key: 'a', label: 'A', sort: 1, is_active: true },
            { key: 'b', label: 'B', sort: 2, is_active: false },
        ];
        const next = [
            { key: 'a', label: 'A2', sort: 3, is_active: false },
            { key: 'b', label: 'B', sort: 2, is_active: true },
            { key: 'c', label: 'C', sort: 4, is_active: true },
        ];
        const summary = buildLocalChangeSummary(previous, next);
        expect(summary.unchanged).toBe(false);
        expect(summary.added).toEqual(['c']);
        expect(summary.label_changed).toEqual(['a']);
        expect(summary.sort_changed).toEqual(['a']);
        expect(summary.deactivated).toEqual(['a']);
        expect(summary.reactivated).toEqual(['b']);

        const noop = buildLocalChangeSummary(previous, previous);
        expect(noop.unchanged).toBe(true);
    });

    it('parses sort as strict integer without permissive coercion', () => {
        expect(parseStrictSortInput('10')).toEqual({ value: 10, error: null });
        expect(parseStrictSortInput('10.5').value).toBeNull();
        expect(parseStrictSortInput('01').value).toBeNull();
        expect(parseStrictSortInput('true').value).toBeNull();
        expect(parseStrictSortInput('-1').value).toBeNull();
        expect(parseStrictSortInput('').value).toBeNull();
    });

    it('warns when zero options are active and prepares payload', () => {
        const drafts = rowsFromServer([
            { key: 'a', label: 'A', sort: 1, is_active: false },
        ]);
        expect(hasZeroActiveWarning(drafts)).toBe(true);
        const { options, errors } = toPayloadOptions(drafts);
        expect(errors).toEqual({});
        expect(options[0]?.is_active).toBe(false);
    });
});
