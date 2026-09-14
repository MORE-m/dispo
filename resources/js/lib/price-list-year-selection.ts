export type PriceYearOption = {
    year: number;
    price_list_id: number | null;
    version: string | null;
    status: string | null;
    is_default: boolean;
    available: boolean;
};

export type PriceYearCatalog = {
    price_years_by_inventory?: Record<string, PriceYearOption[]>;
    current_price_year?: number;
    next_price_year?: number;
};

export function priceYearOptionsForInventory(
    catalog: PriceYearCatalog,
    inventoryId: number,
): PriceYearOption[] {
    return catalog.price_years_by_inventory?.[String(inventoryId)] ?? [];
}

export function defaultPriceYear(
    catalog: PriceYearCatalog,
    inventoryId: number,
): number {
    const options = priceYearOptionsForInventory(catalog, inventoryId);
    const preferred =
        options.find((option) => option.is_default) ?? options[0] ?? null;

    return preferred?.year ?? catalog.current_price_year ?? new Date().getFullYear();
}

export function expectedPriceListIdForYear(
    catalog: PriceYearCatalog,
    inventoryId: number,
    year: number,
): number | null {
    const option = priceYearOptionsForInventory(catalog, inventoryId).find(
        (row) => row.year === year,
    );

    return option?.price_list_id ?? null;
}

export function resolveDisplayedPriceYearOptions(
    catalog: PriceYearCatalog,
    inventoryId: number,
    selectedYear: number | null,
    historical?: {
        version: string | null;
        status: string | null;
        price_list_id?: number | null;
    },
): PriceYearOption[] {
    const options = [...priceYearOptionsForInventory(catalog, inventoryId)];

    if (
        selectedYear !== null &&
        !options.some((option) => option.year === selectedYear)
    ) {
        options.unshift({
            year: selectedYear,
            price_list_id: historical?.price_list_id ?? null,
            version: historical?.version ?? null,
            status: historical?.status ?? 'archived',
            is_default: false,
            available: false,
        });
    }

    return options;
}

export function priceYearDirty(
    originalYear: number | null | undefined,
    selectedYear: number,
): boolean {
    if (originalYear === null || originalYear === undefined) {
        return false;
    }

    return originalYear !== selectedYear;
}

/**
 * Budget: Folgejahr nur, wenn jedes gewählte Inventar eine aktive Folgejahresliste hat.
 */
export function budgetPriceYearOptions(
    catalog: PriceYearCatalog,
    inventoryIds: number[],
): PriceYearOption[] {
    const ids = inventoryIds.filter((id) => id > 0);
    const current = catalog.current_price_year ?? new Date().getFullYear();
    const currentAvailable =
        ids.length === 0
            ? false
            : ids.every((id) => {
                  const option = priceYearOptionsForInventory(catalog, id).find(
                      (row) => row.year === current,
                  );
                  return option?.available === true;
              });

    const options: PriceYearOption[] = [
        {
            year: current,
            price_list_id: null,
            version: null,
            status: currentAvailable ? 'active' : null,
            is_default: true,
            available: currentAvailable,
        },
    ];

    const next = catalog.next_price_year ?? current + 1;
    const nextAvailable =
        ids.length > 0 &&
        ids.every((id) => {
            const option = priceYearOptionsForInventory(catalog, id).find(
                (row) => row.year === next,
            );
            return option?.available === true;
        });

    if (nextAvailable) {
        options.push({
            year: next,
            price_list_id: null,
            version: null,
            status: 'active',
            is_default: false,
            available: true,
        });
    }

    return options;
}

export function expectedPriceListIdsForBudget(
    catalog: PriceYearCatalog,
    inventoryIds: number[],
    year: number,
): Record<number, number> {
    const map: Record<number, number> = {};
    for (const inventoryId of inventoryIds) {
        const id = expectedPriceListIdForYear(catalog, inventoryId, year);
        if (id !== null) {
            map[inventoryId] = id;
        }
    }
    return map;
}
