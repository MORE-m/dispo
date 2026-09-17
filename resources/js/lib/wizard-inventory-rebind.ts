/**
 * BL-P4-02c: Inventar-Rebind im Wizard – Calendar-/Timing-Retention.
 *
 * Bei gleichbleibender Berechnungsmethode bleiben planner_entries / time_ranges
 * und Komponenten erhalten; die Komponentenstrategie wird aus der neuen Regel
 * neu gebunden. Kein stilles Löschen von Spotzahlen beim Inventarwechsel.
 */

import {
    methodStateAfterMediumIdChange,
    type CalculationMethodDraftState,
    type CalculationMethodOptions,
} from '@/lib/calculation-method-draft';
import {
    defaultPriceYear,
    expectedPriceListIdForYear,
    resolveDisplayedPriceYearOptions,
    type PriceYearCatalog,
} from '@/lib/price-list-year-selection';
import {
    initialTimingFieldsForMethod,
    isCalendarCalculationMethod,
    totalPlannerSpotCount,
    type PlannerEntryDraft,
} from '@/lib/pricing-calendar';
import { totalSpotCount, type TimeRangeDraft } from '@/lib/pricing-time';
import {
    resolveStrategyFromRule,
    totalComponentLength,
    type ComponentCalculationStrategy,
    type SpotComponentDraft,
} from '@/lib/spot-components';

export type InventoryRebindCatalog = PriceYearCatalog & {
    inventories: Array<{
        id: number;
        name: string;
        code: string;
    }>;
    media: Array<{
        id: number;
        default_length_seconds: number;
        calculation_method_options?: CalculationMethodOptions | null;
    }>;
    rules: Array<{
        inventory_id: number;
        advertising_medium_id: number;
        default_length_seconds: number | null;
        component_calculation_strategy?: string | null;
    }>;
};

export type InventoryRebindPosition = CalculationMethodDraftState & {
    inventory_id: number;
    inventory_name?: string | null;
    inventory_code?: string | null;
    advertising_medium_id: number;
    length_seconds: number;
    components: SpotComponentDraft[];
    component_calculation_strategy: ComponentCalculationStrategy | null;
    total_spot_count: number;
    plan_rows: Array<Record<string, unknown>>;
    time_ranges: TimeRangeDraft[];
    planner_entries: PlannerEntryDraft[];
    position_discount_percent: string;
    ae_percent: string;
    position_discounts: Array<Record<string, unknown>>;
    price_year: number;
    original_price_year: number | null;
    original_price_list_id: number | null;
    original_price_list_version: string | null;
    original_price_list_status: string | null;
    expected_price_list_id: number | null;
    price_list_version: string | null;
    price_list_status: string | null;
    schema_fingerprint?: string | null;
};

function ruleFor(
    catalog: InventoryRebindCatalog,
    inventoryId: number,
    mediumId: number,
) {
    return catalog.rules.find(
        (item) =>
            item.inventory_id === inventoryId &&
            item.advertising_medium_id === mediumId,
    );
}

/**
 * Baut den Positionszustand nach Inventarwechsel.
 * Gibt null zurück, wenn das Zielinventar kein geeignetes Medium hat.
 */
export function rebindPositionOnInventoryChange(
    position: InventoryRebindPosition,
    nextInventoryId: number,
    catalog: InventoryRebindCatalog,
    allowedMedia: InventoryRebindCatalog['media'],
): InventoryRebindPosition | null {
    const medium =
        allowedMedia.find(
            (candidate) => candidate.id === position.advertising_medium_id,
        ) ?? allowedMedia[0];

    if (!medium) {
        return null;
    }

    const rule = ruleFor(catalog, nextInventoryId, medium.id);
    const methodState = methodStateAfterMediumIdChange(
        position.advertising_medium_id,
        medium.id,
        {
            calculation_method_key: position.calculation_method_key,
            calculation_method_name: position.calculation_method_name,
            historical_calculation_method_key:
                position.historical_calculation_method_key,
            historical_calculation_method_name:
                position.historical_calculation_method_name,
        },
        medium.calculation_method_options,
    );

    const selectedInventory = catalog.inventories.find(
        (candidate) => candidate.id === nextInventoryId,
    );

    const methodUnchanged =
        methodState.calculation_method_key === position.calculation_method_key;

    const retainedComponents = position.components;
    const nextStrategy =
        retainedComponents.length > 0
            ? resolveStrategyFromRule(rule?.component_calculation_strategy)
            : null;

    const nextLengthSeconds =
        retainedComponents.length > 0
            ? totalComponentLength(retainedComponents)
            : (rule?.default_length_seconds ?? medium.default_length_seconds);

    // Preisjahr bewusst behalten (kein stiller Jahreswechsel). Fehlt das Jahr
    // im Zielinventar, bleibt expected_price_list_id leer → Preview/Speichern fail-closed.
    const nextYear = methodUnchanged
        ? position.price_year
        : defaultPriceYear(catalog, nextInventoryId);

    const nextOption = resolveDisplayedPriceYearOptions(
        catalog,
        nextInventoryId,
        nextYear,
    ).find((option) => option.year === nextYear);

    const timing = methodUnchanged
        ? {
              time_ranges: position.time_ranges,
              planner_entries: position.planner_entries,
              total_spot_count: isCalendarCalculationMethod(
                  methodState.calculation_method_key,
              )
                  ? totalPlannerSpotCount(position.planner_entries)
                  : totalSpotCount(position.time_ranges),
          }
        : initialTimingFieldsForMethod(methodState.calculation_method_key);

    return {
        ...position,
        inventory_id: nextInventoryId,
        inventory_name: selectedInventory?.name ?? null,
        inventory_code: selectedInventory?.code ?? null,
        advertising_medium_id: medium.id,
        ...methodState,
        length_seconds: nextLengthSeconds,
        components: retainedComponents,
        component_calculation_strategy: nextStrategy,
        position_discount_percent: '0',
        ae_percent: '0',
        plan_rows: [],
        ...timing,
        position_discounts: [],
        schema_fingerprint: null,
        price_year: nextYear,
        original_price_year: nextYear,
        original_price_list_id: expectedPriceListIdForYear(
            catalog,
            nextInventoryId,
            nextYear,
        ),
        original_price_list_version: nextOption?.version ?? null,
        original_price_list_status: nextOption?.status ?? null,
        expected_price_list_id: expectedPriceListIdForYear(
            catalog,
            nextInventoryId,
            nextYear,
        ),
        price_list_version: nextOption?.version ?? null,
        price_list_status: nextOption?.status ?? null,
    };
}
