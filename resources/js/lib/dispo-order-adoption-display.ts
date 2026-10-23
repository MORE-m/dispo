import {
    formatImpressions,
    formatTkpDisplay,
    OA_TKP_METHOD_KEY,
} from '@/lib/oa-tkp-wizard';
import { formatPlannerEntriesSummary } from '@/lib/dispo-planner-display';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';

/** Minimale Felder für die Positionszeile im Dispo-Create-Dialog. */
export type DispoAdoptionPositionDisplay = {
    calculation_method_key?: string | null;
    engine_profile_key?: string | null;
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

export function isOaTkpAdoptionPosition(
    position: Pick<
        DispoAdoptionPositionDisplay,
        'calculation_method_key' | 'engine_profile_key'
    >,
): boolean {
    return (
        position.calculation_method_key === OA_TKP_METHOD_KEY ||
        position.engine_profile_key === 'oa_tkp'
    );
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
 * Sekundärzeile der Positionsauswahl: Spot unverändert, OA ohne Spotlänge/-anzahl.
 */
export function formatDispoAdoptionPositionDetail(
    position: DispoAdoptionPositionDisplay,
): string {
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
