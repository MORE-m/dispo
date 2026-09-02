import {
    payloadDiscounts,
    type DiscountDraft,
} from '@/components/discount-list-editor';
import {
    emptyDistributionRange,
    payloadDistributionRanges,
    type DistributionRangeDraft,
} from '@/lib/pricing-time';

export type BudgetElementDraft = {
    client_id: string;
    inventory_id: number | null;
    spot_length_seconds: number;
    distribution_ranges: DistributionRangeDraft[];
};

export type BudgetPositionDiscountsByClientId = Record<string, DiscountDraft[]>;

export function newBudgetElementClientId(): string {
    return crypto.randomUUID();
}

export function emptyBudgetElement(
    defaultSpotLength: number,
): BudgetElementDraft {
    return {
        client_id: newBudgetElementClientId(),
        inventory_id: null,
        spot_length_seconds: defaultSpotLength,
        distribution_ranges: [emptyDistributionRange()],
    };
}

export function payloadBudgetElements(elements: BudgetElementDraft[]) {
    return elements
        .filter(
            (element) =>
                element.inventory_id !== null && element.inventory_id > 0,
        )
        .map((element) => ({
            client_id: element.client_id,
            inventory_id: element.inventory_id ?? 0,
            spot_length_seconds: element.spot_length_seconds,
            distribution_ranges: payloadDistributionRanges(
                element.distribution_ranges,
            ),
            position_discounts: [],
        }));
}

export function payloadBudgetElementDiscounts(
    elements: BudgetElementDraft[],
    discountsByClientId: BudgetPositionDiscountsByClientId,
) {
    return elements
        .filter(
            (element) =>
                element.inventory_id !== null && element.inventory_id > 0,
        )
        .map((element) => ({
            client_id: element.client_id,
            inventory_id: element.inventory_id ?? 0,
            position_discounts: payloadDiscounts(
                discountsByClientId[element.client_id] ?? [],
            ),
        }));
}

export function validateBudgetBasics(targetBudget: string): string | null {
    if (targetBudget.trim() === '') {
        return 'Zielbudget N/N ist erforderlich.';
    }

    if (Number(targetBudget) <= 0) {
        return 'Das Zielbudget muss größer als 0 sein.';
    }

    return null;
}

export function validateBudgetElements(
    elements: BudgetElementDraft[],
): string | null {
    if (elements.length === 0) {
        return 'Mindestens ein Budget-Werbeelement ist erforderlich.';
    }

    const seenInventoryIds = new Set<number>();

    for (const element of elements) {
        if (!element.inventory_id) {
            return 'Bitte wähle einen Sender aus.';
        }

        if (seenInventoryIds.has(element.inventory_id)) {
            return 'Dieser Sender ist bereits in einem Werbeelement enthalten.';
        }
        seenInventoryIds.add(element.inventory_id);

        if (!element.spot_length_seconds || element.spot_length_seconds < 1) {
            return 'Spotlänge ist erforderlich.';
        }

        if (element.distribution_ranges.length === 0) {
            return 'Mindestens ein Verteilungszeitraum ist erforderlich.';
        }

        for (const range of element.distribution_ranges) {
            if (range.end_hour_exclusive <= range.start_hour) {
                return 'Das Ende muss nach dem Beginn liegen.';
            }
        }
    }

    return null;
}

export function formatBudgetElementSummary(
    element: BudgetElementDraft,
    inventoryName?: string,
    dayGroupLabel?: (value: string) => string,
): string {
    const name = inventoryName ?? 'Sender';
    const dayLabel = dayGroupLabel ?? ((value: string) => value);
    const ranges = element.distribution_ranges
        .map((range) => {
            const start =
                typeof range.start_hour === 'number'
                    ? `${String(range.start_hour).padStart(2, '0')}:00`
                    : '–';
            const end =
                typeof range.end_hour_exclusive === 'number'
                    ? `${String(range.end_hour_exclusive).padStart(2, '0')}:00`
                    : '–';

            return `${dayLabel(range.day_group)} ${start}–${end}`;
        })
        .join(', ');

    return `${name} · ${element.spot_length_seconds} Sek. · ${ranges}`;
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
    budgetElements,
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
    budgetElements: BudgetElementDraft[];
    budgetPositionDiscounts: BudgetPositionDiscountsByClientId;
    calculationId?: number;
    lockVersion?: number;
}) {
    const elementsPayload = payloadBudgetElements(budgetElements).map(
        (element) => ({
            ...element,
            position_discounts:
                payloadBudgetElementDiscounts(
                    budgetElements,
                    budgetPositionDiscounts,
                ).find((row) => row.client_id === element.client_id)
                    ?.position_discounts ?? [],
        }),
    );

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
        budget_elements: elementsPayload,
        budget_proposal_manual: false,
        positions: [],
        lock_version: lockVersion,
        calculation_id: calculationId,
    };
}

export function initBudgetElementsFromSources(
    latestProposal: {
        payload: {
            budget_elements?: Array<{
                client_id?: string;
                inventory_id: number;
                spot_length_seconds: number;
                distribution_ranges?: DistributionRangeDraft[];
            }>;
            wish_inventory_ids?: number[];
            spot_length_seconds?: number;
            distribution_ranges?: DistributionRangeDraft[];
        };
    } | null,
    defaultSpotLength: number,
): BudgetElementDraft[] {
    if (latestProposal?.payload.budget_elements?.length) {
        return latestProposal.payload.budget_elements.map((element) => ({
            client_id: element.client_id ?? newBudgetElementClientId(),
            inventory_id: element.inventory_id,
            spot_length_seconds: element.spot_length_seconds,
            distribution_ranges: element.distribution_ranges?.length
                ? element.distribution_ranges
                : [emptyDistributionRange()],
        }));
    }

    const wishIds = latestProposal?.payload.wish_inventory_ids ?? [];
    const length =
        latestProposal?.payload.spot_length_seconds ?? defaultSpotLength;
    const ranges = latestProposal?.payload.distribution_ranges?.length
        ? latestProposal.payload.distribution_ranges
        : [emptyDistributionRange()];

    if (wishIds.length > 0) {
        return wishIds.map((inventoryId) => ({
            client_id: `inventory:${inventoryId}`,
            inventory_id: inventoryId,
            spot_length_seconds: length,
            distribution_ranges: ranges,
        }));
    }

    return [emptyBudgetElement(defaultSpotLength)];
}

export function initBudgetPositionDiscountsByClientId(
    latestProposal: {
        payload: {
            budget_elements?: Array<{
                client_id?: string;
                inventory_id: number;
                position_discounts?: Array<{
                    type: string;
                    custom_label: string | null;
                    percent: string;
                }>;
            }>;
            budget_position_discounts_by_inventory?: Array<{
                inventory_id: number;
                discounts?: Array<{
                    type: string;
                    custom_label: string | null;
                    percent: string;
                }>;
            }>;
        };
    } | null,
    elements: BudgetElementDraft[],
    calculation: {
        positions?: Array<{
            inventory_id: number;
            position_discounts?: Array<{
                type: string;
                custom_label: string | null;
                percent: string;
            }>;
            position_discount_percent?: string;
        }>;
    } | null,
): BudgetPositionDiscountsByClientId {
    const map: BudgetPositionDiscountsByClientId = {};

    for (const element of latestProposal?.payload.budget_elements ?? []) {
        const clientId =
            element.client_id ?? `inventory:${element.inventory_id}`;
        if (element.position_discounts?.length) {
            map[clientId] = element.position_discounts.map((discount) => ({
                type: discount.type,
                custom_label: discount.custom_label ?? '',
                percent: discount.percent,
            }));
        }
    }

    for (const row of latestProposal?.payload
        .budget_position_discounts_by_inventory ?? []) {
        const element = elements.find(
            (item) => item.inventory_id === row.inventory_id,
        );
        if (!element || map[element.client_id]) {
            continue;
        }
        map[element.client_id] = (row.discounts ?? []).map((discount) => ({
            type: discount.type,
            custom_label: discount.custom_label ?? '',
            percent: discount.percent,
        }));
    }

    for (const position of calculation?.positions ?? []) {
        const element = elements.find(
            (item) => item.inventory_id === position.inventory_id,
        );
        if (!element || map[element.client_id]) {
            continue;
        }
        if (position.position_discounts?.length) {
            map[element.client_id] = position.position_discounts.map(
                (discount) => ({
                    type: discount.type,
                    custom_label: discount.custom_label ?? '',
                    percent: discount.percent,
                }),
            );
        }
    }

    return map;
}

export type BudgetPlanningViewState = {
    planningMode: string;
    budgetProposalStatus?: string | null;
    budgetAppliedLocally: boolean;
    budgetProposalManual: boolean;
    positionsCount: number;
    hasActiveProposal: boolean;
    budgetReenterSetup: boolean;
};

export function usesRegularPlanningEditor(
    state: BudgetPlanningViewState,
): boolean {
    if (state.budgetReenterSetup) {
        return false;
    }

    if (state.planningMode === 'manual') {
        return true;
    }

    if (state.planningMode !== 'budget') {
        return false;
    }

    if (state.budgetProposalManual) {
        return true;
    }

    if (state.budgetAppliedLocally) {
        return true;
    }

    if (
        state.budgetProposalStatus === 'applied' ||
        state.budgetProposalStatus === 'manual'
    ) {
        return true;
    }

    if (state.positionsCount > 0 && !state.hasActiveProposal) {
        return true;
    }

    return false;
}

export function isBudgetSetupPhase(state: BudgetPlanningViewState): boolean {
    return state.planningMode === 'budget' && !usesRegularPlanningEditor(state);
}
