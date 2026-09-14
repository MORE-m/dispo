export const PRICE_LIST_BASE_GROUPS = [
    { value: 'mo_fr', label: 'Mo–Fr' },
    { value: 'sa', label: 'Samstag' },
    { value: 'so', label: 'Sonntag' },
] as const;

export const PRICE_LIST_HOURS = Array.from({ length: 24 }, (_, hour) => hour);

export const UNSAVED_LIFECYCLE_MESSAGE =
    'Bitte zuerst die Änderungen speichern, bevor Sie veröffentlichen oder archivieren.';

export type PriceListBaseItem = {
    hour: number;
    day_group: string;
    second_price: string;
};

export function itemsFromGrid(
    grid: Record<string, string>,
): PriceListBaseItem[] {
    const items: PriceListBaseItem[] = [];
    for (const hour of PRICE_LIST_HOURS) {
        for (const group of PRICE_LIST_BASE_GROUPS) {
            const value = grid[`${hour}|${group.value}`]?.trim() ?? '';
            if (value !== '') {
                items.push({
                    hour,
                    day_group: group.value,
                    second_price: value,
                });
            }
        }
    }

    return items;
}

export function gridFromBaseItems(
    items: PriceListBaseItem[],
): Record<string, string> {
    const grid: Record<string, string> = {};
    for (const item of items) {
        grid[`${item.hour}|${item.day_group}`] = item.second_price;
    }

    return grid;
}

export function draftFormSnapshot(
    name: string,
    grid: Record<string, string>,
): string {
    return JSON.stringify({
        name: name.trim(),
        items: itemsFromGrid(grid),
    });
}

export function isDraftDirty(
    savedSnapshot: string,
    name: string,
    grid: Record<string, string>,
): boolean {
    return draftFormSnapshot(name, grid) !== savedSnapshot;
}

export function formLockAfterPreview(
    formLockVersion: number,
    _previewLockVersion: number | undefined,
): number {
    return formLockVersion;
}

/** Active/Archived bleiben read-only; während laufender Anfragen auch Entwürfe. */
export function formFieldsReadOnly(editable: boolean, busy: boolean): boolean {
    return !editable || busy;
}
