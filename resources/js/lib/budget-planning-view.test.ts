import { describe, expect, it } from 'vitest';
import {
    isBudgetSetupPhase,
    usesRegularPlanningEditor,
} from '@/lib/budget-planning';

describe('budget planning view state', () => {
    const base = {
        planningMode: 'budget',
        budgetProposalStatus: null,
        budgetAppliedLocally: false,
        budgetProposalManual: false,
        positionsCount: 0,
        hasActiveProposal: false,
        budgetReenterSetup: false,
    };

    it('uses budget setup before proposal apply', () => {
        expect(usesRegularPlanningEditor(base)).toBe(false);
        expect(isBudgetSetupPhase(base)).toBe(true);
    });

    it('uses regular editor after applied status', () => {
        expect(
            usesRegularPlanningEditor({
                ...base,
                budgetProposalStatus: 'applied',
                positionsCount: 2,
            }),
        ).toBe(true);
        expect(
            isBudgetSetupPhase({
                ...base,
                budgetProposalStatus: 'applied',
                positionsCount: 2,
            }),
        ).toBe(false);
    });

    it('uses regular editor after local apply without saved status', () => {
        expect(
            usesRegularPlanningEditor({
                ...base,
                budgetAppliedLocally: true,
                positionsCount: 2,
            }),
        ).toBe(true);
    });

    it('returns to budget setup when reoptimizing', () => {
        expect(
            usesRegularPlanningEditor({
                ...base,
                budgetProposalStatus: 'applied',
                positionsCount: 2,
                budgetReenterSetup: true,
            }),
        ).toBe(false);
        expect(
            isBudgetSetupPhase({
                ...base,
                budgetProposalStatus: 'applied',
                positionsCount: 2,
                budgetReenterSetup: true,
            }),
        ).toBe(true);
    });

    it('manual mode always uses regular editor', () => {
        expect(
            usesRegularPlanningEditor({
                ...base,
                planningMode: 'manual',
            }),
        ).toBe(true);
    });
});
