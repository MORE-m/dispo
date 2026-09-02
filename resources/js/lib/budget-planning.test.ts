import { describe, expect, it } from 'vitest';
import {
    emptyBudgetElement,
    validateBudgetBasics,
    validateBudgetElements,
} from '@/lib/budget-planning';

describe('budget planning validation', () => {
    it('requires a positive target budget', () => {
        expect(validateBudgetBasics('')).toMatch(/Zielbudget/);
        expect(validateBudgetBasics('0')).toMatch(/größer als 0/);
        expect(validateBudgetBasics('500')).toBeNull();
    });

    it('requires budget elements with sender, spot length and ranges', () => {
        expect(validateBudgetElements([])).toMatch(/Werbeelement/);

        expect(
            validateBudgetElements([
                {
                    ...emptyBudgetElement(30),
                    inventory_id: null,
                },
            ]),
        ).toMatch(/Sender/);

        expect(
            validateBudgetElements([
                {
                    ...emptyBudgetElement(30),
                    inventory_id: 1,
                    spot_length_seconds: 0,
                },
            ]),
        ).toMatch(/Spotlänge/);

        expect(
            validateBudgetElements([
                {
                    ...emptyBudgetElement(30),
                    inventory_id: 1,
                    distribution_ranges: [],
                },
            ]),
        ).toMatch(/Verteilungszeitraum/);

        expect(
            validateBudgetElements([
                {
                    ...emptyBudgetElement(30),
                    inventory_id: 1,
                },
                {
                    ...emptyBudgetElement(30),
                    client_id: 'element-2',
                    inventory_id: 1,
                },
            ]),
        ).toMatch(/bereits in einem Werbeelement/);

        expect(
            validateBudgetElements([
                {
                    ...emptyBudgetElement(30),
                    inventory_id: 1,
                    distribution_ranges: [
                        {
                            start_hour: 12,
                            end_hour_exclusive: 8,
                            day_group: 'mo_fr',
                        },
                    ],
                },
            ]),
        ).toMatch(/Ende/);
    });
});
