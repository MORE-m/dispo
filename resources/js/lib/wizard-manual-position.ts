/**
 * PO-CALC-DISPO-UX-1 / PR1: manuelle Werbeelement-Initialisierung ohne Inventar-Default.
 *
 * - Frisches Element: kein Inventar vorausgewählt.
 * - Weiteres Element: nur Inventar des Vorgängers als Default (wenn gesetzt).
 * - Budget-Assistent bleibt hiervon unberührt.
 */

import {
    initMethodStateForNewPosition,
    type CalculationMethodOptions,
} from '@/lib/calculation-method-draft';
import { emptyFreePositionDraftFields } from '@/lib/free-position-wizard';
import {
    emptyOaTkpDraftFields,
    isSelectableForCalculationWizardPosition,
} from '@/lib/oa-tkp-wizard';
import { initialTimingFieldsForMethod } from '@/lib/pricing-calendar';
import { defaultPricingSettlementDraft } from '@/lib/pricing-settlement';
import {
    defaultPriceYear,
    expectedPriceListIdForYear,
    requireCurrentPriceYear,
    resolveDisplayedPriceYearOptions,
    type PriceYearCatalog,
} from '@/lib/price-list-year-selection';
import { isSelectableForNewWizardPositions } from '@/lib/wizard-medium-selection';

export type ManualPositionCatalog = PriceYearCatalog & {
    inventories: Array<{
        id: number;
        name: string;
        code: string;
        is_active: boolean;
    }>;
    media: Array<{
        id: number;
        is_active: boolean;
        is_bookable_for_new_positions: boolean;
        default_length_seconds: number;
        calculation_method_options?: CalculationMethodOptions | null;
    }>;
    rules: Array<{
        inventory_id: number;
        advertising_medium_id: number;
        is_active: boolean;
        default_length_seconds: number | null;
    }>;
};

export type ManualPositionDraftSeed = {
    client_key: string;
    inventory_id: number | null;
    inventory_name: string | null;
    inventory_code: string | null;
    advertising_medium_id: number | null;
    calculation_method_key: string | null;
    calculation_method_name: string | null;
    historical_calculation_method_key: string | null;
    historical_calculation_method_name: string | null;
    length_seconds: number;
    components: [];
    component_calculation_strategy: null;
    total_spot_count: number;
    position_discount_percent: string;
    ae_percent: string;
    plan_rows: [];
    time_ranges: ReturnType<typeof initialTimingFieldsForMethod>['time_ranges'];
    planner_entries: ReturnType<
        typeof initialTimingFieldsForMethod
    >['planner_entries'];
    position_discounts: [];
    period_open: boolean;
    flight_period_start: string;
    flight_period_end: string;
    custom_fields: Record<string, never>;
    custom_choice_fields: Record<string, never>;
    custom_choice_touched: Record<string, never>;
    custom_choice_meta: Record<string, never>;
    price_year: number;
    original_price_year: number;
    saved_inventory_id: null;
    saved_advertising_medium_id: null;
    saved_price_year: null;
    original_price_list_id: number | null;
    expected_price_list_id: number | null;
    price_list_version: string | null;
    price_list_status: string | null;
    original_price_list_version: string | null;
    original_price_list_status: string | null;
    pricing_settlement_mode: ReturnType<
        typeof defaultPricingSettlementDraft
    >['pricing_settlement_mode'];
    fixed_price_nn_input: string;
} & ReturnType<typeof emptyOaTkpDraftFields> &
    ReturnType<typeof emptyFreePositionDraftFields>;

export function newManualPositionClientKey(): string {
    return crypto.randomUUID();
}

function ruleFor(
    catalog: ManualPositionCatalog,
    inventoryId: number,
    mediumId: number,
) {
    return catalog.rules.find(
        (item) =>
            item.inventory_id === inventoryId &&
            item.advertising_medium_id === mediumId,
    );
}

function preferredMediumForNewPosition(
    catalog: ManualPositionCatalog,
    oaTkpWizardCapability: boolean,
) {
    return (
        catalog.media.find((item) => isSelectableForNewWizardPositions(item)) ??
        (oaTkpWizardCapability
            ? (catalog.media.find((item) =>
                  isSelectableForCalculationWizardPosition(
                      item,
                      oaTkpWizardCapability,
                  ),
              ) ?? null)
            : null)
    );
}

/** Frisches manuelles Werbeelement ohne Inventarauswahl. */
export function createEmptyManualPositionDraft(
    catalog: ManualPositionCatalog,
): ManualPositionDraftSeed {
    const year = requireCurrentPriceYear(catalog);

    return {
        client_key: newManualPositionClientKey(),
        inventory_id: null,
        inventory_name: null,
        inventory_code: null,
        advertising_medium_id: null,
        calculation_method_key: null,
        calculation_method_name: null,
        historical_calculation_method_key: null,
        historical_calculation_method_name: null,
        length_seconds: 0,
        components: [],
        component_calculation_strategy: null,
        total_spot_count: 0,
        position_discount_percent: '0',
        ae_percent: '0',
        plan_rows: [],
        time_ranges: [],
        planner_entries: [],
        position_discounts: [],
        period_open: true,
        flight_period_start: '',
        flight_period_end: '',
        custom_fields: {},
        custom_choice_fields: {},
        custom_choice_touched: {},
        custom_choice_meta: {},
        price_year: year,
        original_price_year: year,
        saved_inventory_id: null,
        saved_advertising_medium_id: null,
        saved_price_year: null,
        original_price_list_id: null,
        expected_price_list_id: null,
        price_list_version: null,
        price_list_status: null,
        original_price_list_version: null,
        original_price_list_status: null,
        ...defaultPricingSettlementDraft(),
        ...emptyOaTkpDraftFields(year),
        ...emptyFreePositionDraftFields(),
    };
}

/**
 * Baut eine neue manuelle Position für ein konkretes Inventar
 * (Medium/Preisjahr/Defaults neu; keine Preise oder Dyn-Felder kopieren).
 */
export function createManualPositionDraftForInventory(
    catalog: ManualPositionCatalog,
    inventoryId: number,
    oaTkpWizardCapability = false,
): ManualPositionDraftSeed | null {
    const inventory = catalog.inventories.find(
        (item) => item.id === inventoryId && item.is_active,
    );
    if (!inventory) {
        return null;
    }

    const preferredMedium = preferredMediumForNewPosition(
        catalog,
        oaTkpWizardCapability,
    );
    if (!preferredMedium) {
        return null;
    }

    const rule = ruleFor(catalog, inventory.id, preferredMedium.id);
    if (!rule || !rule.is_active) {
        // Kein aktives Regelpaar für das bevorzugte Medium: anderes Medium mit Regel suchen.
        for (const medium of catalog.media) {
            if (
                !isSelectableForNewWizardPositions(medium) &&
                !(
                    oaTkpWizardCapability &&
                    isSelectableForCalculationWizardPosition(
                        medium,
                        oaTkpWizardCapability,
                    )
                )
            ) {
                continue;
            }
            const candidate = ruleFor(catalog, inventory.id, medium.id);
            if (!candidate || !candidate.is_active) {
                continue;
            }
            return buildSeedForInventoryMedium(
                catalog,
                inventory,
                medium,
                candidate,
            );
        }

        return null;
    }

    return buildSeedForInventoryMedium(
        catalog,
        inventory,
        preferredMedium,
        rule,
    );
}

function buildSeedForInventoryMedium(
    catalog: ManualPositionCatalog,
    inventory: ManualPositionCatalog['inventories'][number],
    medium: ManualPositionCatalog['media'][number],
    rule: ManualPositionCatalog['rules'][number],
): ManualPositionDraftSeed {
    const methodState = initMethodStateForNewPosition(
        medium.calculation_method_options,
    );
    const year = defaultPriceYear(catalog, inventory.id);
    const yearOptions = resolveDisplayedPriceYearOptions(
        catalog,
        inventory.id,
        year,
    );
    const yearMeta = yearOptions.find((option) => option.year === year) ?? null;

    return {
        client_key: newManualPositionClientKey(),
        inventory_id: inventory.id,
        inventory_name: inventory.name,
        inventory_code: inventory.code,
        advertising_medium_id: medium.id,
        ...methodState,
        length_seconds:
            rule.default_length_seconds ?? medium.default_length_seconds,
        components: [],
        component_calculation_strategy: null,
        position_discount_percent: '0',
        ae_percent: '0',
        plan_rows: [],
        ...initialTimingFieldsForMethod(methodState.calculation_method_key),
        position_discounts: [],
        period_open: true,
        flight_period_start: '',
        flight_period_end: '',
        custom_fields: {},
        custom_choice_fields: {},
        custom_choice_touched: {},
        custom_choice_meta: {},
        price_year: year,
        original_price_year: year,
        saved_inventory_id: null,
        saved_advertising_medium_id: null,
        saved_price_year: null,
        original_price_list_id: expectedPriceListIdForYear(
            catalog,
            inventory.id,
            year,
        ),
        expected_price_list_id: expectedPriceListIdForYear(
            catalog,
            inventory.id,
            year,
        ),
        price_list_version: yearMeta?.version ?? null,
        price_list_status: yearMeta?.status ?? null,
        original_price_list_version: yearMeta?.version ?? null,
        original_price_list_status: yearMeta?.status ?? null,
        ...defaultPricingSettlementDraft(),
        ...emptyOaTkpDraftFields(year),
        ...emptyFreePositionDraftFields(),
    };
}

/** Nächstes manuelles Werbeelement: Inventar nur vom unmittelbaren Vorgänger. */
export function createNextManualPositionDraft(
    previous: { inventory_id: number | null } | null | undefined,
    catalog: ManualPositionCatalog,
    oaTkpWizardCapability = false,
): ManualPositionDraftSeed {
    const previousInventoryId = previous?.inventory_id ?? null;
    if (previousInventoryId === null) {
        return createEmptyManualPositionDraft(catalog);
    }

    return (
        createManualPositionDraftForInventory(
            catalog,
            previousInventoryId,
            oaTkpWizardCapability,
        ) ?? createEmptyManualPositionDraft(catalog)
    );
}

export function hasManualInventorySelection<
    T extends { inventory_id: number | null },
>(position: T): position is T & { inventory_id: number } {
    return position.inventory_id !== null && position.inventory_id > 0;
}

/** Inventar UND Werbemittel gesetzt (beide number) – für Payload/Preview-Helfer. */
export function hasManualPositionIds<
    T extends {
        inventory_id: number | null;
        advertising_medium_id: number | null;
    },
>(
    position: T,
): position is T & { inventory_id: number; advertising_medium_id: number } {
    return (
        hasManualInventorySelection(position) &&
        position.advertising_medium_id !== null
    );
}
