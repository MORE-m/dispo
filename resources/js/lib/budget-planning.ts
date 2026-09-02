import {
    payloadDiscounts,
    type DiscountDraft,
} from '@/components/discount-list-editor';
import {
    payloadDistributionRanges,
    type DistributionRangeDraft,
} from '@/lib/pricing-time';

export type BudgetPositionDiscountsByInventory = Record<
    number,
    DiscountDraft[]
>;

export function payloadBudgetPositionDiscounts(
    discountsByInventory: BudgetPositionDiscountsByInventory,
    wishInventoryIds: number[],
): Array<{
    inventory_id: number;
    discounts: ReturnType<typeof payloadDiscounts>;
}> {
    return wishInventoryIds.map((inventoryId) => ({
        inventory_id: inventoryId,
        discounts: payloadDiscounts(discountsByInventory[inventoryId] ?? []),
    }));
}

export function validateBudgetBasics(targetBudget: string): string | null {
    if (targetBudget.trim() === '') {
        return 'Zielbudget N/N ist erforderlich.';
    }

    if (Number(targetBudget) <= 0) {
        return 'Zielbudget N/N muss größer als 0 sein.';
    }

    return null;
}

export function validateBudgetFrame(
    wishInventoryIds: number[],
    budgetSpotLength: number,
    budgetDistributionRanges: DistributionRangeDraft[],
): string | null {
    if (wishInventoryIds.length === 0) {
        return 'Mindestens ein Wunschsender ist erforderlich.';
    }

    if (!budgetSpotLength || budgetSpotLength < 1) {
        return 'Spotlänge ist erforderlich.';
    }

    if (budgetDistributionRanges.length === 0) {
        return 'Mindestens ein Verteilungszeitraum ist erforderlich.';
    }

    for (const range of budgetDistributionRanges) {
        if (range.end_hour_exclusive <= range.start_hour) {
            return 'Das Ende muss nach dem Beginn liegen.';
        }
    }

    return null;
}

export function buildBudgetProposalPayload({
    planningMode,
    customerName,
    agencyName,
    campaign,
    productTitle,
    briefing,
    orderDiscounts,
    aeEnabled,
    targetBudget,
    wishInventoryIds,
    budgetSpotLength,
    budgetDistributionRanges,
    budgetPositionDiscounts,
    calculationId,
    lockVersion,
}: {
    planningMode: string;
    customerName: string;
    agencyName: string;
    campaign: string;
    productTitle: string;
    briefing: string;
    orderDiscounts: DiscountDraft[];
    aeEnabled: boolean;
    targetBudget: string;
    wishInventoryIds: number[];
    budgetSpotLength: number;
    budgetDistributionRanges: DistributionRangeDraft[];
    budgetPositionDiscounts: BudgetPositionDiscountsByInventory;
    calculationId?: number;
    lockVersion?: number;
}) {
    return {
        planning_mode: planningMode,
        customer_name: customerName || null,
        agency_name: agencyName || null,
        campaign: campaign || null,
        product_title: productTitle || null,
        briefing: briefing || null,
        order_discount_percent: '0',
        order_discounts: payloadDiscounts(orderDiscounts),
        ae_enabled: aeEnabled,
        target_budget_nn: targetBudget,
        budget_strategy: 'equal_spot_count',
        budget_wish_inventory_ids: wishInventoryIds,
        budget_spot_length_seconds: budgetSpotLength,
        budget_distribution_ranges: payloadDistributionRanges(
            budgetDistributionRanges,
        ),
        budget_position_discounts_by_inventory: payloadBudgetPositionDiscounts(
            budgetPositionDiscounts,
            wishInventoryIds,
        ),
        budget_proposal_manual: false,
        positions: [],
        lock_version: lockVersion,
        calculation_id: calculationId,
    };
}
