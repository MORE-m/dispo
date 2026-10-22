/**
 * PO-CALC-TKP-1D2b: Frontend-Helfer für Normal-TKP im Kalkulationswizard.
 *
 * Server bleibt Source of Truth für Preise/Brutto/N/N/Under-Min.
 * Hier nur Detection, Payload-Hygiene, Client-Readiness und Pin/Rebind.
 */

import type { CalculationMethodOptions } from '@/lib/calculation-method-draft';
import type { WizardSelectableMedium } from '@/lib/wizard-medium-selection';
import { isSelectableForNewWizardPositions } from '@/lib/wizard-medium-selection';

export const OA_TKP_METHOD_KEY = 'tkp';

export type OaPlatformCatalogRow = {
    id: number;
    code: string;
    name: string;
    sort: number;
};

export type OaTargetingCatalogRow = {
    id: number;
    code: string;
    name: string;
    category: string;
    tkp_surcharge: string;
    revision_number: number;
};

export type OaPlatformAllocationDraft = {
    oa_platform_id: number | null;
    platform_code: string | null;
    platform_name: string | null;
    impressions_input: string;
    /** Gespeicherte inactive Platform – Display only, keine neue Option. */
    historical?: boolean;
};

export type OaTargetingSelectionDraft = {
    oa_targeting_definition_id: number | null;
    targeting_code: string;
    targeting_name: string | null;
    targeting_category: string | null;
    tkp_surcharge_snapshot: string | null;
    targeting_revision_number: number | null;
    historical?: boolean;
};

export type OaTkpMethodResult = {
    basis_tkp: string;
    list_tkp: string;
    minimum_tkp: string;
    targeting_surcharge_total: string;
    final_tkp: string;
    below_minimum_tkp: boolean;
    impressions?: number;
    price_year?: number;
};

export type OaTkpPricingMetadata = {
    advertising_medium_id: number;
    inventory_id: number;
    price_year: number;
    available_price_years: number[];
    expected_oa_tkp_price_list_id: number;
    list_tkp: string;
    minimum_tkp: string;
    price_list_version: string;
    price_list_revision: number;
};

/** Felder, die PositionDraft für TKP trägt (Teilmenge). */
export type OaTkpPositionFields = {
    calculation_method_key: string | null;
    id?: number | null;
    inventory_id: number;
    advertising_medium_id: number;
    price_year: number;
    saved_inventory_id: number | null;
    saved_advertising_medium_id: number | null;
    saved_price_year: number | null;
    expected_oa_tkp_price_list_id: number | null;
    impressions_input: string;
    basis_tkp: string;
    list_tkp_snapshot: string | null;
    minimum_tkp_snapshot: string | null;
    final_tkp: string | null;
    targeting_surcharge_total: string | null;
    below_minimum_tkp: boolean;
    oa_platform_allocations: OaPlatformAllocationDraft[];
    oa_targeting_selections: OaTargetingSelectionDraft[];
    original_oa_tkp_price_list_id: number | null;
    original_oa_list_tkp: string | null;
    original_oa_minimum_tkp: string | null;
    original_oa_price_year: number | null;
};

export function isTkpPosition(
    position: Pick<OaTkpPositionFields, 'calculation_method_key'>,
): boolean {
    return position.calculation_method_key === OA_TKP_METHOD_KEY;
}

export function mediumOffersTkpMethod(medium: {
    calculation_method_options?: CalculationMethodOptions | null;
}): boolean {
    const methods = medium.calculation_method_options?.methods ?? [];

    return methods.some((method) => method.key === OA_TKP_METHOD_KEY);
}

/**
 * Selection nur für den Kalkulationswizard (nicht SO / Budget).
 * released/bookable ODER Capability + Method Options enthalten tkp.
 */
export function isSelectableForCalculationWizardPosition(
    medium: WizardSelectableMedium & {
        calculation_method_options?: CalculationMethodOptions | null;
    },
    oaTkpWizardCapability: boolean,
): boolean {
    if (isSelectableForNewWizardPositions(medium)) {
        return true;
    }

    return (
        oaTkpWizardCapability &&
        medium.is_active &&
        mediumOffersTkpMethod(medium)
    );
}

export function emptyOaTkpDraftFields(
    priceYear: number,
): Pick<
    OaTkpPositionFields,
    | 'expected_oa_tkp_price_list_id'
    | 'impressions_input'
    | 'basis_tkp'
    | 'list_tkp_snapshot'
    | 'minimum_tkp_snapshot'
    | 'final_tkp'
    | 'targeting_surcharge_total'
    | 'below_minimum_tkp'
    | 'oa_platform_allocations'
    | 'oa_targeting_selections'
    | 'original_oa_tkp_price_list_id'
    | 'original_oa_list_tkp'
    | 'original_oa_minimum_tkp'
    | 'original_oa_price_year'
> {
    return {
        expected_oa_tkp_price_list_id: null,
        impressions_input: '',
        basis_tkp: '',
        list_tkp_snapshot: null,
        minimum_tkp_snapshot: null,
        final_tkp: null,
        targeting_surcharge_total: null,
        below_minimum_tkp: false,
        oa_platform_allocations: [],
        oa_targeting_selections: [],
        original_oa_tkp_price_list_id: null,
        original_oa_list_tkp: null,
        original_oa_minimum_tkp: null,
        original_oa_price_year: priceYear,
    };
}

export function parsePositiveIntegerInput(raw: string): number | null {
    const trimmed = raw.trim();
    if (trimmed === '' || !/^\d+$/.test(trimmed)) {
        return null;
    }

    const value = Number(trimmed);
    if (!Number.isSafeInteger(value) || value < 1) {
        return null;
    }

    return value;
}

/** Basis-TKP: positiv, Punkt als Trenner, max. 4 NK – Spiegel des Server-Vertrags. */
export function isValidBasisTkpInput(raw: string): boolean {
    const trimmed = raw.trim();
    if (trimmed === '' || trimmed.includes(',') || /[eE]/.test(trimmed)) {
        return false;
    }

    const match = /^(\d+)(?:\.(\d{1,4}))?$/.exec(trimmed);
    if (!match) {
        return false;
    }

    const whole = match[1] ?? '0';
    const significant = whole.replace(/^0+/, '') || '0';
    if (
        significant === '0' &&
        (match[2] === undefined || /^0+$/.test(match[2]))
    ) {
        return false;
    }

    return true;
}

export function platformAllocationSum(
    allocations: OaPlatformAllocationDraft[],
): number {
    let sum = 0;
    for (const row of allocations) {
        const value = parsePositiveIntegerInput(row.impressions_input);
        if (value === null) {
            return Number.NaN;
        }
        sum += value;
    }

    return sum;
}

export function hasDuplicatePlatformIds(
    allocations: OaPlatformAllocationDraft[],
): boolean {
    const seen = new Set<number>();
    for (const row of allocations) {
        if (row.oa_platform_id == null) {
            continue;
        }
        if (seen.has(row.oa_platform_id)) {
            return true;
        }
        seen.add(row.oa_platform_id);
    }

    return false;
}

export function hasDuplicateTargetingCodes(
    selections: OaTargetingSelectionDraft[],
): boolean {
    const seen = new Set<string>();
    for (const row of selections) {
        const code = row.targeting_code.trim();
        if (code === '') {
            continue;
        }
        if (seen.has(code)) {
            return true;
        }
        seen.add(code);
    }

    return false;
}

export function isOaTkpTripleUnchanged(
    position: Pick<
        OaTkpPositionFields,
        | 'inventory_id'
        | 'advertising_medium_id'
        | 'price_year'
        | 'saved_inventory_id'
        | 'saved_advertising_medium_id'
        | 'saved_price_year'
    >,
): boolean {
    if (
        position.saved_inventory_id == null ||
        position.saved_advertising_medium_id == null ||
        position.saved_price_year == null
    ) {
        return false;
    }

    return (
        position.inventory_id === position.saved_inventory_id &&
        position.advertising_medium_id ===
            position.saved_advertising_medium_id &&
        position.price_year === position.saved_price_year
    );
}

export function needsOaTkpRebind(
    position: Pick<
        OaTkpPositionFields,
        | 'inventory_id'
        | 'advertising_medium_id'
        | 'price_year'
        | 'saved_inventory_id'
        | 'saved_advertising_medium_id'
        | 'saved_price_year'
        | 'id'
    >,
): boolean {
    // Neue Position: immer Metadata-Kandidat.
    if (position.id == null && position.saved_inventory_id == null) {
        return true;
    }

    return !isOaTkpTripleUnchanged(position);
}

export function restoreOriginalOaTkpPin(
    position: OaTkpPositionFields,
): Partial<OaTkpPositionFields> {
    return {
        expected_oa_tkp_price_list_id: position.original_oa_tkp_price_list_id,
        list_tkp_snapshot: position.original_oa_list_tkp,
        minimum_tkp_snapshot: position.original_oa_minimum_tkp,
        price_year:
            position.original_oa_price_year ??
            position.saved_price_year ??
            position.price_year,
    };
}

export function applyOaTkpMetadataCandidate(
    metadata: OaTkpPricingMetadata,
): Partial<OaTkpPositionFields> {
    return {
        expected_oa_tkp_price_list_id: metadata.expected_oa_tkp_price_list_id,
        list_tkp_snapshot: metadata.list_tkp,
        minimum_tkp_snapshot: metadata.minimum_tkp,
        price_year: metadata.price_year,
    };
}

export function isOaTkpPreviewReady(position: OaTkpPositionFields): boolean {
    if (!isTkpPosition(position)) {
        return true;
    }

    if (
        position.inventory_id < 1 ||
        position.advertising_medium_id < 1 ||
        position.price_year < 2000
    ) {
        return false;
    }

    if (
        position.expected_oa_tkp_price_list_id == null ||
        position.expected_oa_tkp_price_list_id < 1
    ) {
        return false;
    }

    if (parsePositiveIntegerInput(position.impressions_input) === null) {
        return false;
    }

    if (!isValidBasisTkpInput(position.basis_tkp)) {
        return false;
    }

    if (position.oa_platform_allocations.length < 1) {
        return false;
    }

    if (
        position.oa_platform_allocations.some(
            (row) =>
                row.oa_platform_id == null ||
                parsePositiveIntegerInput(row.impressions_input) === null,
        )
    ) {
        return false;
    }

    if (hasDuplicatePlatformIds(position.oa_platform_allocations)) {
        return false;
    }

    if (hasDuplicateTargetingCodes(position.oa_targeting_selections)) {
        return false;
    }

    const total = parsePositiveIntegerInput(position.impressions_input);
    const allocated = platformAllocationSum(position.oa_platform_allocations);
    if (total === null || !Number.isFinite(allocated) || allocated !== total) {
        return false;
    }

    return true;
}

export function draftHasTkpPositions(
    positions: Array<Pick<OaTkpPositionFields, 'calculation_method_key'>>,
): boolean {
    return positions.some((position) => isTkpPosition(position));
}

export type OaTkpPreviewPayloadPosition = {
    id?: number | null;
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    schema_fingerprint?: string | null;
    calculation_method_key: string;
    price_year: number;
    expected_oa_tkp_price_list_id: number;
    impressions: number;
    basis_tkp: string;
    oa_platform_allocations: Array<{
        oa_platform_id: number;
        platform_code?: string | null;
        impressions: number;
    }>;
    oa_targeting_selections: Array<{
        oa_targeting_definition_id?: number | null;
        targeting_code: string;
    }>;
    position_discount_percent: string;
    ae_percent: string;
    position_discounts: Array<{
        type: string;
        custom_label: string | null;
        percent: string;
    }>;
    dynamic_field_values: Record<
        string,
        string | number | boolean | null | Record<string, string | null>
    >;
};

export function buildOaTkpPositionPayload(
    position: OaTkpPositionFields & {
        client_key: string;
        schema_fingerprint?: string | null;
        position_discounts: Array<{
            type: string;
            custom_label: string | null;
            percent: string;
        }>;
        dynamic_field_values: Record<
            string,
            string | number | boolean | null | Record<string, string | null>
        >;
        id?: number | null;
    },
): OaTkpPreviewPayloadPosition {
    const impressions =
        parsePositiveIntegerInput(position.impressions_input) ?? 0;

    return {
        id: position.id ?? null,
        client_key: position.client_key,
        inventory_id: position.inventory_id,
        advertising_medium_id: position.advertising_medium_id,
        schema_fingerprint: position.schema_fingerprint ?? null,
        calculation_method_key: OA_TKP_METHOD_KEY,
        price_year: position.price_year,
        expected_oa_tkp_price_list_id:
            position.expected_oa_tkp_price_list_id ?? 0,
        impressions,
        basis_tkp: position.basis_tkp.trim(),
        oa_platform_allocations: position.oa_platform_allocations
            .filter((row) => row.oa_platform_id != null)
            .map((row) => ({
                oa_platform_id: row.oa_platform_id as number,
                platform_code: row.platform_code,
                impressions:
                    parsePositiveIntegerInput(row.impressions_input) ?? 0,
            })),
        oa_targeting_selections: position.oa_targeting_selections
            .filter((row) => row.targeting_code.trim() !== '')
            .map((row) => ({
                oa_targeting_definition_id: row.oa_targeting_definition_id,
                targeting_code: row.targeting_code,
            })),
        position_discount_percent: '0',
        ae_percent: '0',
        position_discounts: position.position_discounts,
        dynamic_field_values: position.dynamic_field_values,
    };
}

export function platformDistributionStatus(
    totalInput: string,
    allocations: OaPlatformAllocationDraft[],
): {
    total: number | null;
    allocated: number | null;
    open: number | null;
    message: string;
} {
    const total = parsePositiveIntegerInput(totalInput);
    const allocatedRaw = platformAllocationSum(allocations);
    const allocated = Number.isFinite(allocatedRaw) ? allocatedRaw : null;

    if (total === null) {
        return {
            total: null,
            allocated,
            open: null,
            message: 'Gesamt-Impressions noch unvollständig.',
        };
    }

    if (allocated === null) {
        return {
            total,
            allocated: null,
            open: null,
            message: 'Platform-Impressions unvollständig.',
        };
    }

    const open = total - allocated;
    if (open === 0) {
        return {
            total,
            allocated,
            open: 0,
            message: `Gesamt: ${formatImpressions(total)} · verteilt: ${formatImpressions(allocated)} · offen: 0`,
        };
    }

    if (open > 0) {
        return {
            total,
            allocated,
            open,
            message: `Gesamt: ${formatImpressions(total)} · verteilt: ${formatImpressions(allocated)} · offen: ${formatImpressions(open)}`,
        };
    }

    return {
        total,
        allocated,
        open,
        message: `${formatImpressions(Math.abs(open))} zu viel verteilt.`,
    };
}

export function formatImpressions(value: number): string {
    return new Intl.NumberFormat('de-DE').format(value);
}

export function formatTkpDisplay(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '–';
    }

    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return '–';
    }

    return `${new Intl.NumberFormat('de-DE', {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
    }).format(amount)} €`;
}

/**
 * Active Targeting darf nicht gewählt werden, wenn Historical mit gleichem Code
 * noch ausgewählt ist.
 */
export function isActiveTargetingBlockedByHistorical(
    activeCode: string,
    selections: OaTargetingSelectionDraft[],
): boolean {
    return selections.some(
        (row) =>
            row.historical === true &&
            row.targeting_code.trim() === activeCode.trim(),
    );
}

export function hydrateOaTkpFromSaved(position: {
    expected_oa_tkp_price_list_id?: number | null;
    impressions?: number | null;
    basis_tkp?: string | null;
    list_tkp_snapshot?: string | null;
    minimum_tkp_snapshot?: string | null;
    final_tkp?: string | null;
    targeting_surcharge_total?: string | null;
    below_minimum_tkp?: boolean;
    price_year?: number | null;
    oa_platform_allocations?: Array<{
        oa_platform_id?: number | null;
        platform_code?: string | null;
        platform_name?: string | null;
        impressions?: number | null;
    }>;
    oa_targeting_selections?: Array<{
        oa_targeting_definition_id?: number | null;
        targeting_code?: string | null;
        targeting_name?: string | null;
        targeting_category?: string | null;
        tkp_surcharge_snapshot?: string | null;
        targeting_revision_number?: number | null;
    }>;
}): ReturnType<typeof emptyOaTkpDraftFields> {
    const year = position.price_year ?? null;
    const pinId = position.expected_oa_tkp_price_list_id ?? null;

    return {
        expected_oa_tkp_price_list_id: pinId,
        impressions_input:
            position.impressions != null && position.impressions > 0
                ? String(position.impressions)
                : '',
        basis_tkp: position.basis_tkp ?? '',
        list_tkp_snapshot: position.list_tkp_snapshot ?? null,
        minimum_tkp_snapshot: position.minimum_tkp_snapshot ?? null,
        final_tkp: position.final_tkp ?? null,
        targeting_surcharge_total: position.targeting_surcharge_total ?? null,
        below_minimum_tkp: Boolean(position.below_minimum_tkp),
        oa_platform_allocations: (position.oa_platform_allocations ?? []).map(
            (row) => ({
                oa_platform_id: row.oa_platform_id ?? null,
                platform_code: row.platform_code ?? null,
                platform_name: row.platform_name ?? null,
                impressions_input:
                    row.impressions != null ? String(row.impressions) : '',
                historical: true,
            }),
        ),
        oa_targeting_selections: (position.oa_targeting_selections ?? []).map(
            (row) => ({
                oa_targeting_definition_id:
                    row.oa_targeting_definition_id ?? null,
                targeting_code: row.targeting_code ?? '',
                targeting_name: row.targeting_name ?? null,
                targeting_category: row.targeting_category ?? null,
                tkp_surcharge_snapshot: row.tkp_surcharge_snapshot ?? null,
                targeting_revision_number:
                    row.targeting_revision_number ?? null,
                historical: true,
            }),
        ),
        original_oa_tkp_price_list_id: pinId,
        original_oa_list_tkp: position.list_tkp_snapshot ?? null,
        original_oa_minimum_tkp: position.minimum_tkp_snapshot ?? null,
        original_oa_price_year: year,
    };
}
