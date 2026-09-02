import { describe, expect, it } from 'vitest';
import {
    validateBudgetBasics,
    validateBudgetFrame,
} from '@/lib/budget-planning';

describe('budget planning validation', () => {
    it('requires a positive target budget', () => {
        expect(validateBudgetBasics('')).toMatch(/Zielbudget/);
        expect(validateBudgetBasics('0')).toMatch(/größer als 0/);
        expect(validateBudgetBasics('500')).toBeNull();
    });

    it('requires senders, spot length and distribution ranges', () => {
        expect(
            validateBudgetFrame([], 30, [
                {
                    start_hour: 8,
                    end_hour_exclusive: 12,
                    day_group: 'mo_fr',
                },
            ]),
        ).toMatch(/Wunschsender/);

        expect(
            validateBudgetFrame([1], 0, [
                {
                    start_hour: 8,
                    end_hour_exclusive: 12,
                    day_group: 'mo_fr',
                },
            ]),
        ).toMatch(/Spotlänge/);

        expect(validateBudgetFrame([1], 30, [])).toMatch(/Verteilungszeitraum/);
        expect(
            validateBudgetFrame([1], 30, [
                {
                    start_hour: 12,
                    end_hour_exclusive: 8,
                    day_group: 'mo_fr',
                },
            ]),
        ).toMatch(/Ende/);
    });
});
