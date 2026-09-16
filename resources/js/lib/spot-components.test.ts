import { describe, expect, it } from 'vitest';
import {
    activateComponentsFromLength,
    addAllonge,
    hasAllonge,
    removeAllonge,
    resolveStrategyFromRule,
    totalComponentLength,
    updateComponentLength,
} from './spot-components';

describe('spot-components helpers (BL-P4-02c)', () => {
    it('sums component lengths', () => {
        expect(
            totalComponentLength([
                {
                    role: 'main_spot',
                    label: 'Hauptspot',
                    length_seconds: 20,
                    sort: 0,
                },
                {
                    role: 'allonge',
                    label: 'Allonge',
                    length_seconds: 10,
                    sort: 1,
                },
            ]),
        ).toBe(30);
    });

    it('activates from legacy length and adds/removes allonge', () => {
        const base = activateComponentsFromLength(20);
        expect(base).toHaveLength(1);
        expect(base[0]?.role).toBe('main_spot');

        const withAllonge = addAllonge(base, 10);
        expect(hasAllonge(withAllonge)).toBe(true);
        expect(totalComponentLength(withAllonge)).toBe(30);

        const without = removeAllonge(withAllonge);
        expect(hasAllonge(without)).toBe(false);
        expect(without).toHaveLength(1);
    });

    it('updates length and resolves strategy', () => {
        const updated = updateComponentLength(
            activateComponentsFromLength(20),
            'main_spot',
            25,
        );
        expect(updated[0]?.length_seconds).toBe(25);
        expect(resolveStrategyFromRule('individual')).toBe('individual');
        expect(resolveStrategyFromRule(null)).toBe('shared_total_length');
    });
});
