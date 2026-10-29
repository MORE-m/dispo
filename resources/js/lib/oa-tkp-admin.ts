export type OaRequiredPair = {
    medium_code: string;
    inventory_code: string;
    advertising_medium_id: number | null;
    inventory_id: number | null;
    medium_name?: string;
    inventory_name?: string;
};

export type OaPriceListItemRow = {
    id?: number;
    medium_code: string;
    medium_name?: string;
    inventory_code: string;
    inventory_name?: string;
    list_tkp: string;
    minimum_tkp: string;
};

export type OaPriceListSummary = {
    id: number;
    year: number;
    name: string;
    version: string;
    revision_number: number;
    lock_version: number;
    status: 'draft' | 'active' | 'archived';
    status_label?: string;
    editable?: boolean;
    items_count: number;
    valid_from?: string | null;
    published_at?: string | null;
    archived_at?: string | null;
    items?: OaPriceListItemRow[];
};

export const OA_MEDIUM_LABELS: Record<string, string> = {
    pre_stream: 'Pre-Stream',
    in_stream: 'In-Stream',
    pre_stream_influencer: 'Pre-Stream Influencer',
    in_stream_influencer: 'In-Stream Influencer',
    midroll_music_environment: 'Midroll (Spotify / YouTube / Deezer)',
};

export const OA_STATUS_LABELS: Record<string, string> = {
    draft: 'Entwurf',
    active: 'Aktiv',
    archived: 'Archiviert',
};

/** Isolierte Abnahme-Testdaten (keine produktive Befüllung). */
export const OA_TEST_LIST_TKP: Record<string, string> = {
    pre_stream: '56.0000',
    in_stream: '44.0000',
    pre_stream_influencer: '56.0000',
    in_stream_influencer: '44.0000',
    midroll_music_environment: '80.0000',
};

export const OA_TEST_MINIMUM_TKP: Record<string, string> = {
    pre_stream: '14.0000',
    in_stream: '8.0000',
    pre_stream_influencer: '14.0000',
    in_stream_influencer: '8.0000',
    midroll_music_environment: '20.0000',
};

export function oaStatusLabel(status: string): string {
    return OA_STATUS_LABELS[status] ?? status;
}

export function oaMediumLabel(code: string, fallback?: string): string {
    return OA_MEDIUM_LABELS[code] ?? fallback ?? code;
}

export function oaCsrfHeaders(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token ? decodeURIComponent(token.slice(11)) : '',
    };
}

export function oaPairKey(medium: string, inventory: string): string {
    return `${medium}|${inventory}`;
}

export function buildMatrixRows(
    requiredPairs: OaRequiredPair[],
    items: OaPriceListItemRow[],
): OaPriceListItemRow[] {
    const byPair = new Map(
        items.map((item) => [
            oaPairKey(item.medium_code, item.inventory_code),
            item,
        ]),
    );

    return requiredPairs.map((pair) => {
        const existing = byPair.get(
            oaPairKey(pair.medium_code, pair.inventory_code),
        );

        return {
            id: existing?.id,
            medium_code: pair.medium_code,
            medium_name:
                existing?.medium_name ??
                pair.medium_name ??
                oaMediumLabel(pair.medium_code),
            inventory_code: pair.inventory_code,
            inventory_name:
                existing?.inventory_name ??
                pair.inventory_name ??
                pair.inventory_code,
            list_tkp: existing?.list_tkp ?? '',
            minimum_tkp: existing?.minimum_tkp ?? '',
        };
    });
}

export function matrixSnapshot(rows: OaPriceListItemRow[]): string {
    return JSON.stringify(
        rows.map((row) => ({
            medium_code: row.medium_code,
            inventory_code: row.inventory_code,
            list_tkp: row.list_tkp.trim(),
            minimum_tkp: row.minimum_tkp.trim(),
        })),
    );
}

export function filledMatrixItems(rows: OaPriceListItemRow[]): Array<{
    medium_code: string;
    inventory_code: string;
    list_tkp: string;
    minimum_tkp: string;
}> {
    return rows
        .filter(
            (row) =>
                row.list_tkp.trim() !== '' && row.minimum_tkp.trim() !== '',
        )
        .map((row) => ({
            medium_code: row.medium_code,
            inventory_code: row.inventory_code,
            list_tkp: row.list_tkp.trim().replace(',', '.'),
            minimum_tkp: row.minimum_tkp.trim().replace(',', '.'),
        }));
}

export type OaFieldError = {
    line: number | null;
    field: string | null;
    message: string;
};

export function firstApiError(
    result: {
        message?: string;
        errors?: string[] | Record<string, string[]>;
        field_errors?: OaFieldError[];
    },
    fallback: string,
): string {
    if (result.field_errors && result.field_errors.length > 0) {
        return result.field_errors[0].message;
    }
    if (Array.isArray(result.errors) && result.errors.length > 0) {
        return result.errors[0];
    }
    if (result.errors && typeof result.errors === 'object') {
        for (const messages of Object.values(result.errors)) {
            if (messages.length > 0) {
                return messages[0];
            }
        }
    }

    return result.message ?? fallback;
}
