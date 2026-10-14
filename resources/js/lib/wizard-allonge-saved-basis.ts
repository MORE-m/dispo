/**
 * Vergleichsbasis des Allonge-Hinweises nach erfolgreichem Save.
 *
 * original_price_year und original_price_list_* bleiben der Preislisten-Pin
 * (Dirty-Hinweis, Weg-/Zurück-Auswahl). Diese Funktion schreibt sie nicht.
 */

export type SavedPositionIdentity = {
    id?: number | null;
    client_key?: string | null;
    schema_fingerprint?: string | null;
    inventory_id?: number | null;
    advertising_medium_id?: number | null;
    price_year?: number | null;
};

export type SavedAllongeBasis = {
    saved_inventory_id: number;
    saved_advertising_medium_id: number;
    saved_price_year: number;
};

type PositionSaveTarget = {
    client_key: string;
    id?: number | null;
    schema_fingerprint?: string | null;
    saved_inventory_id: number | null;
    saved_advertising_medium_id: number | null;
    saved_price_year: number | null;
};

export function matchSavedPosition(
    position: { client_key: string },
    saved: SavedPositionIdentity[],
    index: number,
): SavedPositionIdentity | null {
    const byKey = saved.find(
        (row) =>
            row.client_key != null && row.client_key === position.client_key,
    );

    return byKey ?? saved[index] ?? null;
}

export function savedAllongeBasisFromServer(
    match: SavedPositionIdentity,
): SavedAllongeBasis | null {
    if (
        match.inventory_id == null ||
        match.advertising_medium_id == null ||
        match.price_year == null
    ) {
        return null;
    }

    return {
        saved_inventory_id: match.inventory_id,
        saved_advertising_medium_id: match.advertising_medium_id,
        saved_price_year: match.price_year,
    };
}

export function applySuccessfulPositionSave<T extends PositionSaveTarget>(
    position: T,
    saved: SavedPositionIdentity[],
    index: number,
): T {
    const match = matchSavedPosition(position, saved, index);
    if (!match) {
        return position;
    }

    const basis = savedAllongeBasisFromServer(match);

    return {
        ...position,
        id: match.id ?? position.id,
        schema_fingerprint:
            match.schema_fingerprint ?? position.schema_fingerprint,
        ...basis,
    };
}
