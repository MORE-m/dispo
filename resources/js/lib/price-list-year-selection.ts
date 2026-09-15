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

export type OriginalPriceListPin = {
    year: number;
    price_list_id: number | null;
    version: string | null;
    status: string | null;
};

/** Serverautoritatives aktuelles Preisjahr – kein Browser-/OS-Fallback. */
export function requireCurrentPriceYear(catalog: PriceYearCatalog): number {
    if (typeof catalog.current_price_year !== 'number') {
        throw new Error(
            'Preisjahr-Katalog unvollständig: current_price_year fehlt.',
        );
    }

    return catalog.current_price_year;
}

/** Serverautoritatives Folgejahr – kein lokaler current+1-Fallback. */
export function requireNextPriceYear(catalog: PriceYearCatalog): number {
    if (typeof catalog.next_price_year !== 'number') {
        throw new Error(
            'Preisjahr-Katalog unvollständig: next_price_year fehlt.',
        );
    }

    return catalog.next_price_year;
}

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

    if (preferred?.year !== undefined) {
        return preferred.year;
    }

    return requireCurrentPriceYear(catalog);
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

/**
 * Anzeigeoptionen: Für das ursprüngliche Pin-Jahr immer die gepinnte Identität,
 * auch wenn inzwischen eine neue Active-Revision desselben Jahres existiert.
 */
export function resolveDisplayedPriceYearOptions(
    catalog: PriceYearCatalog,
    inventoryId: number,
    selectedYear: number | null,
    originalPin?: OriginalPriceListPin | null,
): PriceYearOption[] {
    let options = [...priceYearOptionsForInventory(catalog, inventoryId)];

    if (
        originalPin &&
        !options.some((option) => option.year === originalPin.year)
    ) {
        options.unshift({
            year: originalPin.year,
            price_list_id: originalPin.price_list_id,
            version: originalPin.version,
            status: originalPin.status ?? 'archived',
            is_default: false,
            available: false,
        });
    }

    if (originalPin) {
        options = options.map((option) =>
            option.year === originalPin.year
                ? {
                      year: originalPin.year,
                      price_list_id: originalPin.price_list_id,
                      version: originalPin.version,
                      status: originalPin.status,
                      is_default: option.is_default,
                      available: false,
                  }
                : option,
        );
    }

    if (
        selectedYear !== null &&
        !options.some((option) => option.year === selectedYear)
    ) {
        options.unshift({
            year: selectedYear,
            price_list_id: null,
            version: null,
            status: 'archived',
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

export function restoreOriginalPriceListPin(
    originalPin: OriginalPriceListPin,
): {
    price_year: number;
    expected_price_list_id: number | null;
    price_list_version: string | null;
    price_list_status: string | null;
} {
    return {
        price_year: originalPin.year,
        expected_price_list_id: originalPin.price_list_id,
        price_list_version: originalPin.version,
        price_list_status: originalPin.status,
    };
}

/**
 * Budget: Folgejahr nur, wenn jedes gewählte Inventar eine aktive Folgejahresliste hat.
 */
export function budgetPriceYearOptions(
    catalog: PriceYearCatalog,
    inventoryIds: number[],
): PriceYearOption[] {
    const ids = inventoryIds.filter((id) => id > 0);
    const current = requireCurrentPriceYear(catalog);
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

    const next = requireNextPriceYear(catalog);
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
