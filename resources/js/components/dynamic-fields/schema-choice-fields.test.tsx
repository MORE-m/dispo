import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SchemaChoiceFields } from '@/components/dynamic-fields/schema-choice-fields';
import {
    CHOICE_SEARCH_MIN_OPTIONS,
    SELECT_CLEAR_VALUE,
    type SchemaChoiceField,
} from '@/lib/choice-field-values';

afterEach(() => {
    cleanup();
});

const baseOptions = [
    { key: 'opt_a', label: 'Alpha', sort: 1, is_active: true },
    { key: 'opt_b', label: 'Beta', sort: 2, is_active: true },
    { key: 'opt_old', label: 'Alt', sort: 3, is_active: false },
];

const selectField: SchemaChoiceField = {
    key: 'hdr_select',
    label: 'Kanal',
    field_type: 'select',
    required: true,
    sort: 1,
    visible: true,
    options_json: baseOptions,
};

const multiField: SchemaChoiceField = {
    key: 'hdr_multi',
    label: 'Tags',
    field_type: 'multi_select',
    required: false,
    sort: 2,
    visible: true,
    options_json: [
        ...baseOptions,
        ...Array.from({ length: 8 }, (_, index) => ({
            key: `extra_${index}`,
            label: `Extra ${index}`,
            sort: 10 + index,
            is_active: true,
        })),
    ],
};

describe('SchemaChoiceFields', () => {
    it('marks required select without blocking empty draft', () => {
        const onChange = vi.fn();
        render(
            <SchemaChoiceFields
                fields={[selectField]}
                values={{ hdr_select: null }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );

        expect(screen.getByText('Kanal *')).toBeTruthy();
        expect(
            screen.getByTestId('calc-choice-hdr_select'),
        ).toBeTruthy();
    });

    it('toggles multi options and shows selection count', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: [] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );

        expect(screen.getByTestId('calc-choice-hdr_multi-count').textContent).toContain(
            '0 ausgewählt',
        );

        fireEvent.click(screen.getByTestId('calc-choice-hdr_multi-check-opt_a'));
        expect(onChange).toHaveBeenCalledWith('hdr_multi', ['opt_a']);

        rerender(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: ['opt_a'] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );
        expect(screen.getByTestId('calc-choice-hdr_multi-count').textContent).toContain(
            '1 ausgewählt',
        );
    });

    it('disables inactive unselected multi option but keeps selected inactive removable', () => {
        const onChange = vi.fn();
        render(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: ['opt_old'] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );

        const inactiveSelected = screen.getByTestId(
            'calc-choice-hdr_multi-check-opt_old',
        );
        expect(inactiveSelected.getAttribute('disabled')).toBeNull();
        fireEvent.click(inactiveSelected);
        expect(onChange).toHaveBeenCalledWith('hdr_multi', []);
    });

    it('filters multi search locally without clearing selection', () => {
        const onChange = vi.fn();
        render(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: ['opt_a'] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );

        const search = screen.getByTestId('calc-choice-hdr_multi-search');
        fireEvent.change(search, { target: { value: 'zzz' } });
        expect(screen.getByTestId('calc-choice-hdr_multi-empty')).toBeTruthy();
        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByTestId('calc-choice-hdr_multi-count').textContent).toContain(
            '1 ausgewählt',
        );
    });

    it('hides invisible fields while parent can keep state', () => {
        const hidden: SchemaChoiceField = {
            ...selectField,
            key: 'hidden_select',
            visible: false,
        };
        render(
            <SchemaChoiceFields
                fields={[selectField, hidden]}
                values={{ hdr_select: null, hidden_select: 'opt_a' }}
                onChange={() => undefined}
                idPrefix="calc-choice"
            />,
        );

        expect(screen.getByTestId('calc-choice-hdr_select')).toBeTruthy();
        expect(screen.queryByTestId('calc-choice-hidden_select')).toBeNull();
    });

    it('shows inactive selected multi as removable and unselected inactive disabled', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: ['opt_old'] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );

        expect(
            screen.getByTestId('calc-choice-hdr_multi-inactive-badge-opt_old'),
        ).toBeTruthy();
        fireEvent.click(screen.getByTestId('calc-choice-hdr_multi-check-opt_old'));
        expect(onChange).toHaveBeenCalledWith('hdr_multi', []);

        rerender(
            <SchemaChoiceFields
                fields={[multiField]}
                values={{ hdr_multi: [] }}
                onChange={onChange}
                idPrefix="calc-choice"
            />,
        );
        expect(
            screen
                .getByTestId('calc-choice-hdr_multi-check-opt_old')
                .getAttribute('disabled'),
        ).not.toBeNull();
    });

    it('exposes search threshold constant at 10 options', () => {
        expect(CHOICE_SEARCH_MIN_OPTIONS).toBe(10);
        expect(SELECT_CLEAR_VALUE.startsWith('__dispo_choice_')).toBe(true);
    });
});
