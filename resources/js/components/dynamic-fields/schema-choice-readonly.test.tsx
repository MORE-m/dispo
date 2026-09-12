import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { SchemaChoiceReadonlyFields } from '@/components/dynamic-fields/schema-choice-readonly';
import type { SchemaChoiceField } from '@/lib/choice-field-values';

afterEach(() => {
    cleanup();
});

const selectField: SchemaChoiceField = {
    key: 'hdr_select',
    label: 'Kanal',
    field_type: 'select',
    required: false,
    sort: 1,
    visible: true,
    options_json: [
        { key: 'opt_a', label: 'Alpha', sort: 1, is_active: true },
        { key: 'opt_old', label: 'Alt', sort: 2, is_active: false },
    ],
};

describe('SchemaChoiceReadonlyFields', () => {
    it('shows uncaptured and inactive select labels without controls', () => {
        const { rerender } = render(
            <SchemaChoiceReadonlyFields
                fields={[selectField]}
                values={{ hdr_select: 'opt_a' }}
                captured={{ hdr_select: false }}
                idPrefix="dispo-calc-origin-choice"
            />,
        );

        expect(
            screen.getByTestId('dispo-calc-origin-choice-hdr_select-value')
                .textContent,
        ).toContain('Nicht erfasst');
        expect(screen.queryByRole('combobox')).toBeNull();

        rerender(
            <SchemaChoiceReadonlyFields
                fields={[selectField]}
                values={{ hdr_select: 'opt_old' }}
                captured={{ hdr_select: true }}
                idPrefix="dispo-calc-origin-choice"
            />,
        );

        expect(
            screen.getByTestId('dispo-calc-origin-choice-hdr_select-value')
                .textContent,
        ).toContain('Alt');
        expect(
            screen.getByTestId('dispo-calc-origin-choice-hdr_select-inactive')
                .textContent,
        ).toContain('Nicht mehr auswählbar');
    });
});
