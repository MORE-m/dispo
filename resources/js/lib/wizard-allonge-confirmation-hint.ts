/**
 * PO-ALLONGE-1: Hinweis folgt dem Live- oder Snapshot-Pfad.
 *
 * Eine neue Position und ein Inventar-, Medium- oder Preisjahr-Wechsel
 * brauchen die bestätigte Kombination. Eine unveränderte gespeicherte
 * Position behält ihren Pin, auch wenn die Admin-Bestätigung später fehlt.
 */

import { SWF_ALLONGE_KIND } from '@/lib/wizard-trailer-medium-change';

export type AllongeHintCatalog = {
    media: Array<{ id: number; kind?: string | null }>;
    rules: Array<{
        inventory_id: number;
        advertising_medium_id: number;
        swf_allonge_confirmed_at?: string | null;
    }>;
};

export type AllongeHintPosition = {
    id?: number | null;
    inventory_id: number;
    advertising_medium_id: number;
    original_inventory_id?: number | null;
    original_advertising_medium_id?: number | null;
    price_year?: number | null;
    original_price_year?: number | null;
};

export type AllongeConfigurationHint = {
    tone: 'block' | 'snapshot';
    text: string;
};

const BLOCKED_TEXT =
    'Die Allonge-Konfiguration für dieses Inventar ist nicht bestätigt. Kalkulation ist gesperrt, bis Länge und Aufschlag in der Kombination gespeichert sind.';

const SNAPSHOT_TEXT =
    'Gespeicherte Länge, Aufschlag und Preis dieser Position bleiben gültig. Eine neue Position oder ein Wechsel von Inventar, Medium oder Preisjahr braucht eine bestätigte Kombination.';

export function allongeConfigurationHint(
    catalog: AllongeHintCatalog,
    position: AllongeHintPosition,
): AllongeConfigurationHint | null {
    const kind = catalog.media.find(
        (item) => item.id === position.advertising_medium_id,
    )?.kind;
    if (kind !== SWF_ALLONGE_KIND) {
        return null;
    }

    const rule = catalog.rules.find(
        (item) =>
            item.inventory_id === position.inventory_id &&
            item.advertising_medium_id === position.advertising_medium_id,
    );
    if (rule?.swf_allonge_confirmed_at) {
        return null;
    }

    if (needsLiveAllongeResolution(position)) {
        return { tone: 'block', text: BLOCKED_TEXT };
    }

    return { tone: 'snapshot', text: SNAPSHOT_TEXT };
}

function needsLiveAllongeResolution(position: AllongeHintPosition): boolean {
    if (position.id == null) {
        return true;
    }

    if (
        position.original_inventory_id != null &&
        position.inventory_id !== position.original_inventory_id
    ) {
        return true;
    }

    if (
        position.original_advertising_medium_id != null &&
        position.advertising_medium_id !== position.original_advertising_medium_id
    ) {
        return true;
    }

    return (
        position.original_price_year != null &&
        position.price_year !== position.original_price_year
    );
}
