import { describe, expect, it } from 'vitest';
import {
    activateComponentsFromLength,
    addAllonge,
    buildProfileComponents,
    derivedAirings,
    hasAllonge,
    isForcedProfile,
    removeAllonge,
    resolveStrategyFromRule,
    totalComponentLength,
    updateComponentLength,
    updateComponentLengthBySort,
} from './spot-components';

const tandemSlots = [
    { role: 'main_spot', label: 'Hauptspot', sort: 1 },
    { role: 'reminder', label: 'Reminder', sort: 2 },
];

const tridemSlots = [
    { role: 'main_spot', label: 'Hauptspot', sort: 1 },
    { role: 'reminder', label: 'Reminder 1', sort: 2 },
    { role: 'reminder', label: 'Reminder 2', sort: 3 },
];

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

describe('spot-components helpers (BL-P4-02e tandem/tridem)', () => {
    it('builds tandem and tridem with defaults', () => {
        const tandem = buildProfileComponents('tandem', tandemSlots);
        expect(tandem).toHaveLength(2);
        expect(tandem[0]).toMatchObject({
            role: 'main_spot',
            sort: 1,
            length_seconds: 20,
        });
        expect(tandem[1]).toMatchObject({
            role: 'reminder',
            sort: 2,
            length_seconds: 10,
        });

        const tridem = buildProfileComponents('tridem', tridemSlots);
        expect(tridem).toHaveLength(3);
        expect(tridem.map((c) => c.length_seconds)).toEqual([20, 10, 10]);
    });

    it('preserves lengths by role+sort when switching profiles', () => {
        const previous = [
            {
                role: 'main_spot' as const,
                label: 'Hauptspot',
                length_seconds: 25,
                sort: 1,
            },
            {
                role: 'reminder' as const,
                label: 'Reminder 1',
                length_seconds: 12,
                sort: 2,
            },
            {
                role: 'reminder' as const,
                label: 'Reminder 2',
                length_seconds: 8,
                sort: 3,
            },
        ];

        const tandem = buildProfileComponents('tandem', tandemSlots, previous);
        expect(tandem[0]?.length_seconds).toBe(25);
        expect(tandem[1]?.length_seconds).toBe(12);
    });

    it('updates reminder length by sort without touching other reminders', () => {
        const tridem = buildProfileComponents('tridem', tridemSlots);
        const updated = updateComponentLengthBySort(tridem, 3, 15);

        expect(updated.find((c) => c.sort === 2)?.length_seconds).toBe(10);
        expect(updated.find((c) => c.sort === 3)?.length_seconds).toBe(15);
    });

    it('detects forced profiles and derived airings', () => {
        expect(isForcedProfile('tandem')).toBe(true);
        expect(isForcedProfile('tridem')).toBe(true);
        expect(isForcedProfile(null)).toBe(false);
        expect(derivedAirings(5, 2)).toBe(10);
        expect(derivedAirings(-1, 3)).toBe(0);
    });

    it('does not add allonge helpers to forced profile builds', () => {
        const tandem = buildProfileComponents('tandem', tandemSlots);
        expect(hasAllonge(tandem)).toBe(false);
    });
});
