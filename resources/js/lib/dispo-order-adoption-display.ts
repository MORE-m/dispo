import { formatImpressions, formatTkpDisplay } from '@/lib/oa-tkp-wizard';
import { formatPlannerEntriesSummary } from '@/lib/dispo-planner-display';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import {
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

    return `${position.total_spot_count} Spots · ${position.length_seconds}s · ${formatPositionTiming(position)}`;
}
