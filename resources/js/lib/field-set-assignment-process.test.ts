import { describe, expect, it } from 'vitest';
import {
    allowedAssignmentProcessValues,
    filterAssignmentProcessOptions,
    resolveAssignmentProcess,
} from './field-set-assignment-process';

const allOptions = [
    { value: 'calculation', label: 'Kalkulation' },
    { value: 'dispo_order', label: 'Dispoauftrag' },
    { value: 'both', label: 'Beide' },
];

describe('field-set-assignment-process', () => {
    it('leitet zulässige Prozesse aus der Feldset-Gültigkeit ab', () => {
        expect(allowedAssignmentProcessValues('calculation')).toEqual([
            'calculation',
        ]);
        expect(allowedAssignmentProcessValues('dispo_order')).toEqual([
            'dispo_order',
        ]);
        expect(allowedAssignmentProcessValues('both')).toEqual([
            'calculation',
            'dispo_order',
            'both',
        ]);
        expect(allowedAssignmentProcessValues(undefined)).toEqual([]);
    });

    it('initialisiert den Prozess passend zum Feldset', () => {
        expect(resolveAssignmentProcess('calculation')).toBe('calculation');
        expect(resolveAssignmentProcess('dispo_order')).toBe('dispo_order');
        expect(resolveAssignmentProcess('both')).toBe('calculation');
        expect(resolveAssignmentProcess(undefined)).toBe('');
    });

    it('behält einen weiterhin zulässigen Prozess beim Feldset-Wechsel', () => {
        expect(resolveAssignmentProcess('both', 'dispo_order')).toBe(
            'dispo_order',
        );
        expect(resolveAssignmentProcess('both', 'calculation')).toBe(
            'calculation',
        );
        expect(resolveAssignmentProcess('dispo_order', 'dispo_order')).toBe(
            'dispo_order',
        );
    });

    it('korrigiert ungültige Prozesswerte bei Feldset-Wechseln', () => {
        expect(resolveAssignmentProcess('dispo_order', 'calculation')).toBe(
            'dispo_order',
        );
        expect(resolveAssignmentProcess('calculation', 'dispo_order')).toBe(
            'calculation',
        );
        expect(resolveAssignmentProcess('dispo_order', 'both')).toBe(
            'dispo_order',
        );
        expect(resolveAssignmentProcess('calculation', 'both')).toBe(
            'calculation',
        );
        expect(resolveAssignmentProcess('both', 'invalid')).toBe('calculation');
    });

    it('filtert Select-Optionen streng auf zulässige Prozesse', () => {
        expect(
            filterAssignmentProcessOptions(allOptions, 'dispo_order').map(
                (option) => option.value,
            ),
        ).toEqual(['dispo_order']);
        expect(
            filterAssignmentProcessOptions(allOptions, 'calculation').map(
                (option) => option.value,
            ),
        ).toEqual(['calculation']);
        expect(
            filterAssignmentProcessOptions(allOptions, 'both').map(
                (option) => option.value,
            ),
        ).toEqual(['calculation', 'dispo_order', 'both']);
        expect(filterAssignmentProcessOptions(allOptions, null)).toEqual([]);
    });
});
