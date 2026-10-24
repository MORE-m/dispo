import { formatImpressions, formatTkpDisplay } from '@/lib/oa-tkp-wizard';
import {
    isInconsistentMethodPair,
    isOaTkpMethodPair,
    METHOD_INCONSISTENCY_MESSAGE,
    type DispoMethodClassification,
} from '@/lib/dispo-position-method-classification';

export type DispoShowOaPosition = {
    calculation_method_key?: string | null;
    engine_profile_key?: string | null;
    method_classification?: DispoMethodClassification | null;
    method_inconsistency_message?: string | null;
    impressions?: number | null;
    final_tkp?: string | null;
    basis_tkp?: string | null;
    list_tkp_snapshot?: string | null;
    minimum_tkp_snapshot?: string | null;
    targeting_surcharge_total?: string | null;
    below_minimum_tkp?: boolean | null;
    media_gross?: string | null;
    calculation_method_name?: string | null;
    oa_tkp_price_list_id?: number | null;
    oa_tkp_price_list_version?: string | null;
    oa_tkp_price_list_year?: number | null;
    oa_tkp_price_list_revision?: number | null;
    oa_platform_allocations_snapshot?: Array<{
        oa_platform_id?: number;
        platform_code?: string | null;
        platform_name?: string | null;
        impressions?: number;
        sort?: number;
    }> | null;
    oa_targeting_selections_snapshot?: Array<{
        oa_targeting_definition_id?: number;
        targeting_code?: string | null;
        targeting_name?: string | null;
        targeting_category?: string | null;
        tkp_surcharge_snapshot?: string | null;
        targeting_revision_number?: number | null;
        sort?: number;
    }> | null;
    spot_method_label?: string;
    total_spot_count?: number;
    length_seconds?: number;
    price_list_version?: string | null;
};

/** Methodenschlüssel aus Dispo-Snapshot – AND-Vertrag; kein Spot-Sentinel. */
export function isOaTkpDispoShowPosition(
    position: Pick<
        DispoShowOaPosition,
        | 'calculation_method_key'
        | 'engine_profile_key'
        | 'method_classification'
    >,
): boolean {
    return isOaTkpMethodPair(position);
}

export function isInconsistentDispoShowPosition(
    position: Pick<
        DispoShowOaPosition,
        | 'calculation_method_key'
        | 'engine_profile_key'
        | 'method_classification'
    >,
): boolean {
    return isInconsistentMethodPair(position);
}

/** Kompakte Meta-Zeile unter Inventory/Medium. */
export function formatDispoShowPositionMeta(
    position: DispoShowOaPosition,
): string {
    if (isInconsistentDispoShowPosition(position)) {
        return (
            position.method_inconsistency_message?.trim() ||
            METHOD_INCONSISTENCY_MESSAGE
        );
    }

    if (isOaTkpDispoShowPosition(position)) {
        const parts: string[] = ['TKP'];

        if (position.impressions != null && position.impressions > 0) {
            parts.push(
                `${formatImpressions(position.impressions)} Impressions`,
            );
        }

        if (position.final_tkp) {
            parts.push(formatTkpDisplay(position.final_tkp));
        }

        return parts.join(' · ');
    }

    const parts = [
        position.spot_method_label ?? '',
        `${position.total_spot_count ?? 0} Spots`,
        `${position.length_seconds ?? 0}s`,
    ].filter((part) => part !== '');

    if (position.price_list_version) {
        parts.push(`Preisliste ${position.price_list_version}`);
    }

    return parts.join(' · ');
}

export function formatOaPriceListIdentity(
    position: DispoShowOaPosition,
): string | null {
    if (!isOaTkpDispoShowPosition(position)) {
        return null;
    }

    const bits: string[] = [];

    if (position.oa_tkp_price_list_year != null) {
        bits.push(`Jahr ${position.oa_tkp_price_list_year}`);
    }

    if (position.oa_tkp_price_list_version) {
        bits.push(`Version ${position.oa_tkp_price_list_version}`);
    }

    if (position.oa_tkp_price_list_revision != null) {
        bits.push(`Revision ${position.oa_tkp_price_list_revision}`);
    }

    if (position.oa_tkp_price_list_id != null) {
        bits.push(`ID ${position.oa_tkp_price_list_id}`);
    }

    return bits.length > 0 ? bits.join(' · ') : null;
}

export { formatImpressions, formatTkpDisplay };
