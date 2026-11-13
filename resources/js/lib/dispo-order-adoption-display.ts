import { formatImpressions, formatTkpDisplay } from '@/lib/oa-tkp-wizard';
import { formatPlannerEntriesSummary } from '@/lib/dispo-planner-display';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import {
    isFreePositionMethodPair,
    isInconsistentMethodPair,
    isOaTkpMethodPair,
    METHOD_INCONSISTENCY_MESSAGE,
    type DispoMethodClassification,
} from '@/lib/dispo-position-method-classification';

/** Minimale Felder für die Positionszeile im Dispo-Create-Dialog. */
export type DispoAdoptionPositionDisplay = {
    calculation_method_key?: string | null;
    engine_profile_key?: string | null;
    method_classification?: DispoMethodClassification | null;
    method_inconsistency_message?: string | null;
    adoptable?: boolean;
    impressions?: number | null;
    final_tkp?: string | null;
    media_gross?: string | null;
    length_seconds: number;
    total_spot_count: number;
    time_ranges: {
        start_hour: number;
        end_hour_exclusive: number;
        day_group?: string;
        spot_count: number;
    }[];
    planner_entries?: {
        date: string;
        hour: number;
        day_group?: string;
        spot_count: number;
    }[];
};

/** PO-CALC-TKP-1D3d-1: AND-Vertrag (tkp ∧ oa_tkp), keine OR-Erkennung mehr. */
export function isOaTkpAdoptionPosition(
    position: Pick<
        DispoAdoptionPositionDisplay,
        | 'calculation_method_key'
        | 'engine_profile_key'
        | 'method_classification'
    >,
): boolean {
    return isOaTkpMethodPair(position);
}

export function isInconsistentAdoptionPosition(
    position: Pick<
        DispoAdoptionPositionDisplay,
        | 'calculation_method_key'
        | 'engine_profile_key'
        | 'method_classification'
        | 'adoptable'
    >,
): boolean {
    if (position.adoptable === false) {
        return true;
    }

    return isInconsistentMethodPair(position);
}

/** NATIVE-4: Free Position ohne Spot-/Längen-Scheinwerte. */
export const FREE_POSITION_DISPLAY_LABEL = 'Freie Preisposition';

export function isFreePositionAdoptionPosition(
    position: Pick<
        DispoAdoptionPositionDisplay,
        | 'calculation_method_key'
        | 'engine_profile_key'
        | 'method_classification'
    >,
): boolean {
    return isFreePositionMethodPair(position);
}

export function formatFreePositionMoney(
    value: string | null | undefined,
): string | null {
    if (value == null || value.trim() === '') {
        return null;
    }
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return null;
    }

    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(amount);
}

function formatTimeRanges(
    ranges: DispoAdoptionPositionDisplay['time_ranges'],
): string {
    if (ranges.length === 0) {
        return '–';
    }

    return ranges
        .map(
            (range) =>
                `${formatHour(range.start_hour)}–${formatInclusiveEnd(range.end_hour_exclusive)} · ${range.spot_count} Spots`,
        )
        .join('; ');
}

function formatPositionTiming(position: DispoAdoptionPositionDisplay): string {
    if (position.planner_entries && position.planner_entries.length > 0) {
        return formatPlannerEntriesSummary(position.planner_entries);
    }

    return formatTimeRanges(position.time_ranges);
}

/**
 * Sekundärzeile der Positionsauswahl: Spot unverändert, OA ohne Spotlänge/-anzahl,
 * inkonsistente Paare ohne Spot-/OA-Scheinwerte.
 */
export function formatDispoAdoptionPositionDetail(
    position: DispoAdoptionPositionDisplay,
): string {
    if (isInconsistentAdoptionPosition(position)) {
        return (
            position.method_inconsistency_message?.trim() ||
            METHOD_INCONSISTENCY_MESSAGE
        );
    }

    if (isOaTkpAdoptionPosition(position)) {
        const parts: string[] = [];

        if (position.impressions != null && position.impressions > 0) {
            parts.push(
                `${formatImpressions(position.impressions)} Impressions`,
            );
        }

        parts.push('TKP');

        if (position.final_tkp != null && position.final_tkp !== '') {
            parts.push(formatTkpDisplay(position.final_tkp));
        }

        return parts.join(' · ');
    }

    if (isFreePositionAdoptionPosition(position)) {
        const gross = formatFreePositionMoney(position.media_gross);

        return gross === null
            ? FREE_POSITION_DISPLAY_LABEL
            : `${FREE_POSITION_DISPLAY_LABEL} · ${gross}`;
    }

    return `${position.total_spot_count} Spots · ${position.length_seconds}s · ${formatPositionTiming(position)}`;
}
