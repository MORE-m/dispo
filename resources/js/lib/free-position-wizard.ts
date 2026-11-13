/**
 * NATIVE-3: Frontend-Helfer für die Freie Preisposition (calculation_method_key = free_position).
 *
 * Server bleibt Source of Truth für Brutto/Rabatte/AE/N/N. Hier nur Detection,
 * Draft-Hygiene (Entry/Leave), Eingabevalidierung und Payload-Aufbau.
 * Kein Medium-Code-Branching: Erkennung allein über den Methodenschlüssel.
 * Es wird nie `media_gross` aus dem Client gesendet.
 */

import { emptyOaTkpDraftFields } from '@/lib/oa-tkp-wizard';
import {
    formatFixedPriceNnInput,
    parseFixedPriceNnInput,
} from '@/lib/pricing-settlement';

export const FREE_POSITION_METHOD_KEY = 'free_position';

export type FreePositionDraftFields = {
    /** Rohe Nutzereingabe für das Feld „Preis“ (payload: free_price). */
    free_price_input: string;
};

/** Felder, die für die Methodenerkennung genügen. */
export type FreePositionMethodFields = {
    calculation_method_key: string | null;
};

export type FreePositionDiscountPayload = {
    type: string;
    custom_label: string | null;
    percent: string;
};

export type FreePositionPayloadSource = {
    id?: number | null;
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    schema_fingerprint?: string | null;
    free_price_input: string;
    position_discounts: FreePositionDiscountPayload[];
    dynamic_field_values: Record<
        string,
        string | number | boolean | null | Record<string, string | null>
    >;
};

export type FreePositionPayloadPosition = {
    id: number | null;
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    schema_fingerprint: string | null;
    calculation_method_key: typeof FREE_POSITION_METHOD_KEY;
    free_price: string;
    position_discount_percent: '0';
    ae_percent: '0';
    position_discounts: FreePositionDiscountPayload[];
    dynamic_field_values: FreePositionPayloadSource['dynamic_field_values'];
};

export function isFreePosition(
    position: Pick<FreePositionMethodFields, 'calculation_method_key'>,
): boolean {
    return position.calculation_method_key === FREE_POSITION_METHOD_KEY;
}

export function emptyFreePositionDraftFields(): FreePositionDraftFields {
    return { free_price_input: '' };
}

/**
 * Spot-/OA-/Settlement-Felder, die an einer Freien Preisposition leer sein müssen.
 * Kein Restzustand beim Wechsel Spot/TKP → Free.
 */
function clearedForeignFieldsForFreePosition(priceYear: number) {
    return {
        ...emptyOaTkpDraftFields(priceYear),
        time_ranges: [] as never[],
        planner_entries: [] as never[],
        total_spot_count: 0,
        components: [] as never[],
        component_calculation_strategy: null,
        production_lines: [] as never[],
    };
}

type FreePositionDraftPatch = Partial<FreePositionDraftFields> &
    Partial<ReturnType<typeof clearedForeignFieldsForFreePosition>>;

/**
 * Medium-/Inventory-Resolve: Zielmethode Free → Free-Draft nur neu, wenn vorher nicht Free.
 * Verlassen von Free → Preisfeld leeren (kein Restzustand an Spot/TKP-Position).
 */
export function freePositionDraftPatchAfterResolvedMethod(
    previous: Pick<FreePositionMethodFields, 'calculation_method_key'>,
    nextMethodKey: string | null | undefined,
    priceYear: number,
): FreePositionDraftPatch {
    const nextIsFree = nextMethodKey === FREE_POSITION_METHOD_KEY;
    const previousIsFree = isFreePosition(previous);

    if (nextIsFree) {
        return previousIsFree
            ? {}
            : {
                  ...emptyFreePositionDraftFields(),
                  ...clearedForeignFieldsForFreePosition(priceYear),
              };
    }

    return previousIsFree ? emptyFreePositionDraftFields() : {};
}

/**
 * Explizite Methodenwahl im Selector. Erneute Wahl von Free behält die Eingabe.
 * Wechsel nach Free räumt Spot-/OA-Reste; Wechsel weg von Free leert das Preisfeld.
 */
export function freePositionDraftPatchOnMethodSelect(
    previous: Pick<FreePositionMethodFields, 'calculation_method_key'>,
    nextMethodKey: string,
    priceYear: number,
): FreePositionDraftPatch {
    return freePositionDraftPatchAfterResolvedMethod(
        previous,
        nextMethodKey,
        priceYear,
    );
}

export type FreePriceValidation =
    | { valid: true; normalized: string }
    | { valid: false; message: string };

/**
 * Client-Spiegel des Serververtrags (> 0, höchstens 2 Nachkommastellen).
 * Server bleibt autoritativ; hier nur frühes Feedback und Preview-Sperre.
 */
export function validateFreePriceInput(raw: string): FreePriceValidation {
    const trimmed = raw.trim();
    if (trimmed === '') {
        return { valid: false, message: 'Preis ist erforderlich.' };
    }

    const normalized = parseFixedPriceNnInput(trimmed);
    if (normalized === null) {
        return {
            valid: false,
            message:
                'Preis muss größer als 0 sein und darf höchstens 2 Nachkommastellen haben.',
        };
    }

    return { valid: true, normalized };
}

export function isFreePositionPreviewReady(
    position: FreePositionMethodFields &
        Partial<FreePositionDraftFields> & {
            inventory_id?: number;
            advertising_medium_id?: number;
        },
): boolean {
    if (!isFreePosition(position)) {
        return true;
    }

    if (
        (position.inventory_id ?? 0) < 1 ||
        (position.advertising_medium_id ?? 0) < 1
    ) {
        return false;
    }

    return validateFreePriceInput(position.free_price_input ?? '').valid;
}

export function draftBlocksFreePositionPreview(
    positions: Array<
        FreePositionMethodFields &
            Partial<FreePositionDraftFields> & {
                inventory_id?: number;
                advertising_medium_id?: number;
            }
    >,
): boolean {
    return positions.some((position) => !isFreePositionPreviewReady(position));
}

/**
 * Payload für den Server: nur `free_price` als Preiseingabe. Kein media_gross,
 * keine Länge/Spotzahl/Planner/OA-/Settlement-Felder. Rabatte bleiben erhalten;
 * ob sie wirken, entscheidet der Server (is_discountable aus Medium/Regel).
 *
 * Ungültige Eingabe wird roh weitergereicht, damit der Server die Fehlermeldung
 * unter `positions.{i}.free_price` liefert (Preview ist clientseitig gesperrt).
 */
export function buildFreePositionPayload(
    position: FreePositionPayloadSource,
): FreePositionPayloadPosition {
    const validation = validateFreePriceInput(position.free_price_input);

    return {
        id: position.id ?? null,
        client_key: position.client_key,
        inventory_id: position.inventory_id,
        advertising_medium_id: position.advertising_medium_id,
        schema_fingerprint: position.schema_fingerprint ?? null,
        calculation_method_key: FREE_POSITION_METHOD_KEY,
        free_price: validation.valid
            ? validation.normalized
            : position.free_price_input.trim(),
        position_discount_percent: '0',
        ae_percent: '0',
        position_discounts: position.position_discounts,
        dynamic_field_values: position.dynamic_field_values,
    };
}

/**
 * Reload: `free_price` vom Server (= gespeichertes media_gross) bevorzugt, sonst media_gross.
 */
export function hydrateFreePositionFromSaved(position: {
    free_price?: string | number | null;
    media_gross?: string | number | null;
}): FreePositionDraftFields {
    const raw = position.free_price ?? position.media_gross ?? null;
    if (raw === null || raw === '') {
        return emptyFreePositionDraftFields();
    }

    return { free_price_input: formatFixedPriceNnInput(String(raw)) };
}
