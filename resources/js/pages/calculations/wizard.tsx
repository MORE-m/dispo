import type { HttpExceptionResponse } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, SlidersHorizontal, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CalculationMethodSelector } from '@/components/calculation-method-selector';
import { CalculationSummaryPanel } from '@/components/calculation-summary-panel';
import { DispoOrderCreateAction } from '@/components/dispo-order-create-action';
import { DispoOrderRevisionBanner } from '@/components/dispo-order-revision-banner';
import type { DispoOrderRevisionContext } from '@/types/dispo-order';
import { type BudgetSpotProposal } from '@/components/budget-proposal-panel';
import { BudgetConditionsStep } from '@/components/budget-conditions-step';
import { BudgetElementsStep } from '@/components/budget-elements-step';
import { BudgetPlanningSidebar } from '@/components/budget-planning-sidebar';
import { BudgetProposalResultStep } from '@/components/budget-proposal-result-step';
import {
    DiscountListEditor,
    payloadDiscounts,
    type DiscountDraft,
    type DiscountTypeOption,
} from '@/components/discount-list-editor';
import {
    FormField,
    formSelectClass,
    formTextareaClass,
    formatPercent,
    money,
    moneyDeduction,
} from '@/components/form-field';
import {
    SchemaChoiceFields,
    customHeaderChoiceFieldsFromSchema,
    customPositionChoiceFieldsFromSchema,
    visibleChoiceFields,
} from '@/components/dynamic-fields/schema-choice-fields';
import {
    SchemaTextFields,
    customHeaderTextFieldsFromSchema,
    customPositionTextFieldsFromSchema,
} from '@/components/dynamic-fields/schema-text-fields';
import {
    choiceValuesForPayload,
    initChoiceValuesMap,
    type ChoiceValue,
} from '@/lib/choice-field-values';
import { PriceTimeRanges } from '@/components/price-time-ranges';
import {
    emptyTimeRange,
    type DistributionRangeDraft,
    formatHour,
    formatInclusiveEnd,
    payloadTimeRanges,
    totalSpotCount,
    type DayGroupOption,
    type TimeRangeDraft,
} from '@/lib/pricing-time';
import {
    calculationMethodPayloadFields,
    hasSubmittableCalculationMethodKey,
    initMethodStateForExistingPosition,
    initMethodStateForNewPosition,
    methodStateAfterMediumIdChange,
    NO_CALCULATION_METHOD_MESSAGE,
    restoreHistoricalCalculationMethod,
    selectLiveCalculationMethod,
    type CalculationMethodOptions,
} from '@/lib/calculation-method-draft';
import { isSelectableForNewWizardPositions } from '@/lib/wizard-medium-selection';
import {
    EmptyState,
    ErrorState,
    LoadingState,
    StatusBanner,
    SuccessState,
} from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { LogoSlot } from '@/components/logo-slot';
import {
    SelectionCard,
    SelectionCardGrid,
    SelectionCardOption,
} from '@/components/selection-card';
import {
    PositionPriceSummary,
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';
import { useCalculationPreview } from '@/hooks/use-calculation-preview';
import {
    buildBudgetProposalPayload,
    initBudgetElementsFromSources,
    initBudgetPositionDiscountsByClientId,
    isBudgetSetupPhase,
    payloadBudgetElementDiscounts,
    payloadBudgetElements,
    usesRegularPlanningEditor,
    validateBudgetBasics,
    validateBudgetElements,
    type BudgetElementDraft,
    type BudgetPositionDiscountsByClientId,
} from '@/lib/budget-planning';
import {
    firstValidationMessage,
    mapValidationErrors,
} from '@/lib/validation-errors';
import { requiredPositionFieldKeysFromSnapshotRules } from '@/lib/dynamic-field-rules';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { WizardStepper } from '@/components/wizard-stepper';
import { cn } from '@/lib/utils';

type PlanRow = {
    hour: number;
    day_group: string;
    second_price?: string | null;
};

type FieldSchema = {
    fields: Array<{
        key: string;
        field_type: string;
        label: string;
        help_text: string | null;
        scope: string;
        sort: number;
        is_system?: boolean;
        required?: boolean;
        visible?: boolean;
        max_length?: number | null;
        validation_json?: { max_length?: number } | null;
        options_json?: Array<{
            key: string;
            label: string;
            sort: number;
            is_active: boolean;
        }> | null;
    }>;
    rules: Array<{
        condition: { op?: string; field_key?: string; value?: unknown };
        action: { op?: string; field_key?: string };
    }>;
    schema_fingerprint?: string | null;
    format_version?: number;
};

type PositionDraft = {
    id?: number;
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    /** DF-3.3a2β: Fingerprint des positionsbezogenen Effektiv-Schemas. */
    schema_fingerprint?: string | null;
    /** DF-3.3a2β: Positions-Schema (Effektiv bzw. Live je Medium). */
    field_schema?: FieldSchema | null;
    /** ADV-001c4b: fachlicher Methoden-Key (moderner Wizard-Payload). */
    calculation_method_key: string | null;
    calculation_method_name: string | null;
    historical_calculation_method_key: string | null;
    historical_calculation_method_name: string | null;
    length_seconds: number;
    total_spot_count: number;
    needs_spot_redistribution?: boolean;
    position_discount_percent: string;
    ae_percent: string;
    plan_rows: PlanRow[];
    time_ranges: TimeRangeDraft[];
    position_discounts: DiscountDraft[];
    period_open: boolean;
    flight_period_start: string;
    flight_period_end: string;
    custom_fields: Record<string, string>;
    /** DF-3-REST-C2: Select = string|null, Multi = string[]. */
    custom_choice_fields: Record<string, ChoiceValue>;
};

type PeriodValue = { start: string | null; end: string | null } | null;

type Catalog = {
    inventories: {
        id: number;
        name: string;
        code: string;
        type: string;
        logo_path: string | null;
        is_active: boolean;
    }[];
    media: {
        id: number;
        name: string;
        code: string;
        default_length_seconds: number;
        is_discountable: boolean;
        is_ae_eligible: boolean;
        is_active: boolean;
        is_bookable_for_new_positions: boolean;
        unbookable_reason: string | null;
        calculation_method_options?: CalculationMethodOptions | null;
    }[];
    rules: {
        id: number;
        inventory_id: number;
        advertising_medium_id: number;
        default_length_seconds: number | null;
        is_discountable: boolean;
        is_ae_eligible: boolean;
        is_active: boolean;
    }[];
};

type Totals = {
    media_gross: string;
    position_discount_total: string;
    order_discount_total: string;
    ae_total: string;
    nn_invest: string;
    target_budget_nn: string | null;
    budget_delta: string | null;
    requires_special_approval: boolean;
    after_position_discount_total?: string;
    after_order_discount_total?: string;
    ae_eligible_base?: string;
    ae_enabled?: boolean;
    order_discounts?: Array<{
        label: string;
        percent: string;
        amount: string;
        remaining: string;
    }>;
    positions: {
        media_gross: string;
        nn_invest: string;
        spot_count: number;
        average_second_price?: string | null;
        after_position_discount?: string;
        time_ranges?: Array<{
            start_hour: number;
            end_hour_exclusive: number;
            day_group: string;
            spot_count: number;
            average_second_price: string;
            range_gross: string;
        }>;
        position_discounts?: Array<{
            label: string;
            percent: string;
            amount: string;
            remaining: string;
        }>;
    }[];
};

type Proposal = BudgetSpotProposal;

type SavedSummary = {
    media_gross: string;
    position_discount_total: string;
    order_discount_total: string;
    ae_total: string;
    nn_invest: string;
    target_budget_nn: string | null;
    requires_special_approval: boolean;
    ae_enabled?: boolean;
    order_discounts?: Array<{
        type: string;
        custom_label: string | null;
        percent: string;
    }>;
    positions: {
        inventory_name: string;
        spot_method: string;
        price_list_version: string | null;
        length_seconds: number;
        total_spot_count: number;
        media_gross: string;
        nn_invest: string;
        position_discount_percent: string;
        ae_percent: string;
        plan_rows: PlanRow[];
        time_ranges?: Array<{
            start_hour: number;
            end_hour_exclusive: number;
            day_group: string;
            spot_count: number;
            average_second_price?: string | null;
            range_gross?: string | null;
        }>;
        position_discounts?: Array<{
            type: string;
            custom_label: string | null;
            percent: string;
        }>;
    }[];
};

type SavedCalculation = {
    id: number;
    lock_version: number;
    planning_mode: string;
    customer_name: string | null;
    agency_name: string | null;
    campaign: string | null;
    product_title: string | null;
    briefing: string | null;
    order_discount_percent: string;
    ae_enabled?: boolean;
    target_budget_nn: string | null;
    budget_strategy: string | null;
    budget_proposal_status?: string | null;
    dynamic_field_values?: Record<string, unknown> & {
        campaign_period?: PeriodValue;
    };
    order_discounts?: Array<{
        type: string;
        custom_label: string | null;
        percent: string;
    }>;
    positions: {
        id: number;
        client_key: string | null;
        inventory_id: number;
        advertising_medium_id: number;
        schema_fingerprint?: string | null;
        field_schema?: FieldSchema | null;
        spot_method?: string;
        calculation_method_key?: string | null;
        calculation_method_name?: string | null;
        length_seconds: number;
        total_spot_count: number;
        needs_spot_redistribution?: boolean;
        position_discount_percent: string;
        ae_percent: string;
        plan_rows: PlanRow[];
        time_ranges?: Array<{
            start_hour: number;
            end_hour_exclusive: number;
            day_group: string;
            spot_count: number;
        }>;
        position_discounts?: Array<{
            type: string;
            custom_label: string | null;
            percent: string;
        }>;
        dynamic_field_values?: Record<string, unknown> & {
            period_open?: boolean;
            position_flight_period?: PeriodValue;
        };
    }[];
};

const MANUAL_STEPS = [
    'Grunddaten',
    'Werbeelemente',
    'Konditionen',
    'Zusammenfassung',
] as const;

function schemaFieldsForPosition(
    position: Pick<PositionDraft, 'field_schema'>,
    fieldSchema: FieldSchema,
    existingGen3: boolean,
): FieldSchema['fields'] {
    if (position.field_schema?.fields) {
        return position.field_schema.fields;
    }

    // Gen-3-Edit ohne Effektiv-Schema: keine Positionsfelder aus der Header-Basis.
    if (existingGen3) {
        return [];
    }

    return fieldSchema.fields;
}

function schemaRulesForPosition(
    position: Pick<PositionDraft, 'field_schema'>,
    fieldSchema: FieldSchema,
    existingGen3: boolean,
): FieldSchema['rules'] {
    if (position.field_schema?.rules) {
        return position.field_schema.rules;
    }

    if (existingGen3) {
        return [];
    }

    return fieldSchema.rules;
}

const BUDGET_STEPS = [
    'Grunddaten',
    'Planungsrahmen',
    'Konditionen',
    'Budgetvorschlag',
] as const;

type LatestBudgetProposal = {
    id: number;
    input_fingerprint: string | null;
    status: string;
    payload: BudgetSpotProposal & {
        budget_elements?: Array<{
            client_id?: string;
            inventory_id: number;
            spot_length_seconds: number;
            distribution_ranges?: DistributionRangeDraft[];
            position_discounts?: Array<{
                type: string;
                custom_label: string | null;
                percent: string;
            }>;
        }>;
        wish_inventory_ids?: number[];
        spot_length_seconds?: number;
        distribution_ranges?: DistributionRangeDraft[];
        budget_position_discounts_by_inventory?: Array<{
            inventory_id: number;
            discounts?: Array<{
                type: string;
                custom_label: string | null;
                percent: string;
            }>;
        }>;
        order_discounts?: Array<{
            type: string;
            custom_label: string | null;
            percent: string;
        }>;
    };
};

type AppliedBudgetProposal = LatestBudgetProposal & {
    applied_at?: string | null;
};

function newClientKey(): string {
    return crypto.randomUUID();
}

function ruleFor(catalog: Catalog, inventoryId: number, mediumId: number) {
    return catalog.rules.find(
        (item) =>
            item.inventory_id === inventoryId &&
            item.advertising_medium_id === mediumId,
    );
}

function catalogLabel(name: string, isActive: boolean): string {
    return isActive ? name : `${name} (inaktiv – historisch)`;
}

function methodOptionsForMedium(
    catalog: Catalog,
    mediumId: number,
): CalculationMethodOptions | null {
    return (
        catalog.media.find((item) => item.id === mediumId)
            ?.calculation_method_options ?? null
    );
}

function firstValidPosition(catalog: Catalog): PositionDraft | null {
    // ADV-001c4b: erstes aktives, buchbares Medium mit Inventarregel (kein Code-Hardcode).
    const preferredMedium =
        catalog.media.find((item) => isSelectableForNewWizardPositions(item)) ??
        null;

    if (!preferredMedium) {
        return null;
    }

    for (const inventory of catalog.inventories.filter(
        (item) => item.is_active,
    )) {
        const rule = ruleFor(catalog, inventory.id, preferredMedium.id);
        if (!rule || !rule.is_active) {
            continue;
        }

        const methodState = initMethodStateForNewPosition(
            preferredMedium.calculation_method_options,
        );

        return {
            client_key: newClientKey(),
            inventory_id: inventory.id,
            advertising_medium_id: preferredMedium.id,
            ...methodState,
            length_seconds:
                rule.default_length_seconds ??
                preferredMedium.default_length_seconds,
            total_spot_count: 0,
            position_discount_percent: '0',
            ae_percent: '0',
            plan_rows: [],
            time_ranges: [emptyTimeRange()],
            position_discounts: [],
            period_open: true,
            flight_period_start: '',
            flight_period_end: '',
            custom_fields: {},
            custom_choice_fields: {},
        };
    }

    return null;
}

function draftDiscounts(
    discounts?: Array<{
        type: string;
        custom_label: string | null;
        percent: string;
    }>,
    fallbackPercent?: string,
): DiscountDraft[] {
    if (discounts?.length) {
        return discounts.map((discount) => ({
            type: discount.type,
            custom_label: discount.custom_label ?? '',
            percent: discount.percent,
        }));
    }

    if (fallbackPercent && Number(fallbackPercent) > 0) {
        return [
            {
                type: 'other',
                custom_label: 'Positionsrabatt',
                percent: fallbackPercent,
            },
        ];
    }

    return [];
}

function draftTimeRanges(
    position: SavedCalculation['positions'][number],
): TimeRangeDraft[] {
    if (position.time_ranges?.length) {
        return position.time_ranges.map((range) => ({
            start_hour: range.start_hour,
            end_hour_exclusive: range.end_hour_exclusive,
            day_group: range.day_group,
            spot_count: range.spot_count,
        }));
    }

    if (
        position.plan_rows.length === 1 &&
        !position.needs_spot_redistribution
    ) {
        return [
            {
                start_hour: position.plan_rows[0].hour,
                end_hour_exclusive: position.plan_rows[0].hour + 1,
                day_group: position.plan_rows[0].day_group,
                spot_count: position.total_spot_count,
            },
        ];
    }

    if (position.plan_rows.length > 0) {
        return position.plan_rows.map((row) => ({
            start_hour: row.hour,
            end_hour_exclusive: row.hour + 1,
            day_group: row.day_group,
            spot_count: '' as const,
        }));
    }

    return [emptyTimeRange()];
}

function conflictMessage(
    data: string | Record<string, unknown>,
): string | null {
    let payload: unknown = data;

    if (typeof payload === 'string') {
        try {
            payload = JSON.parse(payload);
        } catch {
            return null;
        }
    }

    const message = (payload as { message?: unknown } | null)?.message;

    return typeof message === 'string' && message !== '' ? message : null;
}

export default function CalculationWizard({
    catalog,
    dayGroups,
    discountTypes,
    fieldSchema,
    calculation,
    savedSummary,
    savedDisplayTotals,
    latestBudgetProposal,
    appliedBudgetProposal: _appliedBudgetProposal,
    canEdit,
    canCreateDispoOrder = false,
    dispoOrderRevision = null,
}: {
    catalog: Catalog;
    dayGroups: DayGroupOption[];
    discountTypes: DiscountTypeOption[];
    fieldSchema: FieldSchema;
    calculation: SavedCalculation | null;
    savedSummary: SavedSummary | null;
    savedDisplayTotals: Totals | null;
    latestBudgetProposal: LatestBudgetProposal | null;
    appliedBudgetProposal: AppliedBudgetProposal | null;
    canEdit: boolean;
    canCreateDispoOrder?: boolean;
    dispoOrderRevision?: DispoOrderRevisionContext | null;
}) {
    const flash = usePage().props.flash;
    const initialBudgetApplied =
        calculation?.budget_proposal_status === 'applied' ||
        calculation?.budget_proposal_status === 'manual';
    const [step, setStep] = useState(() => {
        if (calculation?.planning_mode === 'budget') {
            if (
                initialBudgetApplied &&
                (calculation.positions?.length ?? 0) > 0
            ) {
                return 1;
            }

            if (
                latestBudgetProposal &&
                (calculation.positions?.length ?? 0) === 0
            ) {
                return 3;
            }
        }

        return 0;
    });
    const [planningMode, setPlanningMode] = useState(
        calculation?.planning_mode ?? 'manual',
    );
    const [customerName, setCustomerName] = useState(
        calculation?.customer_name ?? '',
    );
    const [agencyName, setAgencyName] = useState(
        calculation?.agency_name ?? '',
    );
    const [campaign, setCampaign] = useState(calculation?.campaign ?? '');
    const [productTitle, setProductTitle] = useState(
        calculation?.product_title ?? '',
    );
    const [briefing, setBriefing] = useState(calculation?.briefing ?? '');
    const [campaignPeriodStart, setCampaignPeriodStart] = useState(
        calculation?.dynamic_field_values?.campaign_period?.start ?? '',
    );
    const [campaignPeriodEnd, setCampaignPeriodEnd] = useState(
        calculation?.dynamic_field_values?.campaign_period?.end ?? '',
    );
    const customHeaderFields = useMemo(
        () => customHeaderTextFieldsFromSchema(fieldSchema.fields),
        [fieldSchema.fields],
    );
    const customHeaderChoiceFields = useMemo(
        () => customHeaderChoiceFieldsFromSchema(fieldSchema.fields),
        [fieldSchema.fields],
    );
    const visibleHeaderChoiceFields = useMemo(
        () => visibleChoiceFields(customHeaderChoiceFields),
        [customHeaderChoiceFields],
    );
    const existingGen3 =
        calculation !== null && (fieldSchema.format_version ?? 0) >= 3;
    const [customHeaderValues, setCustomHeaderValues] = useState<
        Record<string, string>
    >(() => {
        const initial: Record<string, string> = {};
        const stored = calculation?.dynamic_field_values ?? {};
        for (const field of customHeaderTextFieldsFromSchema(
            fieldSchema.fields,
        )) {
            const raw = stored[field.key];
            initial[field.key] =
                typeof raw === 'string' || typeof raw === 'number'
                    ? String(raw)
                    : '';
        }
        return initial;
    });
    const [customHeaderChoiceValues, setCustomHeaderChoiceValues] = useState<
        Record<string, ChoiceValue>
    >(() =>
        initChoiceValuesMap(
            customHeaderChoiceFieldsFromSchema(fieldSchema.fields),
            calculation?.dynamic_field_values ?? {},
        ),
    );
    const [orderDiscounts, setOrderDiscounts] = useState<DiscountDraft[]>(() =>
        draftDiscounts(
            calculation?.order_discounts,
            calculation?.order_discount_percent,
        ),
    );
    const [aeEnabled, setAeEnabled] = useState(
        calculation?.ae_enabled ?? false,
    );
    const [targetBudget, setTargetBudget] = useState(
        calculation?.target_budget_nn ?? '',
    );
    const spotClassicMedium = catalog.media.find((item) =>
        isSelectableForNewWizardPositions(item),
    );
    const defaultSpotLength =
        catalog.rules.find(
            (rule) =>
                rule.is_active &&
                rule.advertising_medium_id === spotClassicMedium?.id,
        )?.default_length_seconds ??
        spotClassicMedium?.default_length_seconds ??
        30;
    const [budgetElements, setBudgetElements] = useState<BudgetElementDraft[]>(
        () =>
            initBudgetElementsFromSources(
                latestBudgetProposal,
                defaultSpotLength,
            ),
    );
    const [budgetPositionDiscounts, setBudgetPositionDiscounts] =
        useState<BudgetPositionDiscountsByClientId>(() =>
            initBudgetPositionDiscountsByClientId(
                latestBudgetProposal,
                initBudgetElementsFromSources(
                    latestBudgetProposal,
                    defaultSpotLength,
                ),
                calculation,
            ),
        );
    const [budgetProposalManual, setBudgetProposalManual] = useState(
        calculation?.budget_proposal_status === 'manual',
    );
    const [budgetAppliedLocally, setBudgetAppliedLocally] = useState(false);
    const [inputsRevision, setInputsRevision] = useState(0);
    const [proposalAtRevision, setProposalAtRevision] = useState<number | null>(
        latestBudgetProposal ? 0 : null,
    );
    const [proposalLoading, setProposalLoading] = useState(false);
    const [proposalError, setProposalError] = useState<string | null>(null);
    const [positions, setPositions] = useState<PositionDraft[]>(() => {
        if (calculation?.positions?.length) {
            return calculation.positions.map((position) => {
                const methodState = initMethodStateForExistingPosition(
                    methodOptionsForMedium(
                        catalog,
                        position.advertising_medium_id,
                    ),
                    position.calculation_method_key ??
                        position.spot_method ??
                        null,
                    position.calculation_method_name ?? null,
                );

                return {
                    id: position.id,
                    client_key: position.client_key ?? newClientKey(),
                    inventory_id: position.inventory_id,
                    advertising_medium_id: position.advertising_medium_id,
                    schema_fingerprint: position.schema_fingerprint ?? null,
                    field_schema: position.field_schema ?? null,
                    ...methodState,
                    length_seconds: position.length_seconds,
                    total_spot_count: position.total_spot_count,
                    needs_spot_redistribution:
                        position.needs_spot_redistribution,
                    position_discount_percent: String(
                        position.position_discount_percent,
                    ),
                    ae_percent: String(position.ae_percent),
                    plan_rows: position.plan_rows.map((row) => ({
                        hour: row.hour,
                        day_group: row.day_group,
                        second_price: row.second_price,
                    })),
                    time_ranges: draftTimeRanges(position),
                    position_discounts: draftDiscounts(
                        position.position_discounts,
                        position.position_discount_percent,
                    ),
                    period_open:
                        position.dynamic_field_values?.period_open ?? true,
                    flight_period_start:
                        position.dynamic_field_values?.position_flight_period
                            ?.start ?? '',
                    flight_period_end:
                        position.dynamic_field_values?.position_flight_period
                            ?.end ?? '',
                    custom_fields: Object.fromEntries(
                        customPositionTextFieldsFromSchema(
                            schemaFieldsForPosition(
                                { field_schema: position.field_schema ?? null },
                                fieldSchema,
                                (fieldSchema.format_version ?? 0) >= 3,
                            ),
                        ).map((field) => {
                            const raw =
                                position.dynamic_field_values?.[field.key];
                            return [
                                field.key,
                                typeof raw === 'string' ||
                                typeof raw === 'number'
                                    ? String(raw)
                                    : '',
                            ];
                        }),
                    ),
                    custom_choice_fields: initChoiceValuesMap(
                        customPositionChoiceFieldsFromSchema(
                            schemaFieldsForPosition(
                                { field_schema: position.field_schema ?? null },
                                fieldSchema,
                                (fieldSchema.format_version ?? 0) >= 3,
                            ),
                        ),
                        position.dynamic_field_values ?? {},
                    ),
                };
            });
        }

        if (calculation?.planning_mode === 'budget') {
            return [];
        }

        const first = firstValidPosition(catalog);
        return first ? [first] : [];
    });

    // DF-3.3a2β: Positions-Fingerprints und -Schemas nachladen (Create oder Mediumwechsel).
    useEffect(() => {
        const missing = positions.filter(
            (position) =>
                position.advertising_medium_id > 0 &&
                (position.schema_fingerprint === null ||
                    position.schema_fingerprint === undefined ||
                    position.schema_fingerprint === '' ||
                    position.field_schema == null),
        );

        if (missing.length === 0) {
            return;
        }

        let cancelled = false;

        void (async () => {
            const updates = new Map<
                string,
                { fingerprint: string; fieldSchema: FieldSchema }
            >();

            for (const position of missing) {
                try {
                    const response = await jsonPost<{
                        fieldSchema?: FieldSchema;
                    }>('/kalkulationen/feldschema', {
                        advertising_medium_id: position.advertising_medium_id,
                        ...(calculation?.id
                            ? { calculation_id: calculation.id }
                            : {}),
                    });
                    const nextSchema = response.fieldSchema;
                    const fingerprint = nextSchema?.schema_fingerprint ?? null;
                    if (fingerprint && nextSchema) {
                        updates.set(position.client_key, {
                            fingerprint,
                            fieldSchema: nextSchema,
                        });
                    }
                } catch {
                    // Nachladen fehlgeschlagen: Speichern bleibt fail-closed.
                }
            }

            if (cancelled || updates.size === 0) {
                return;
            }

            setPositions((current) =>
                current.map((position) => {
                    const next = updates.get(position.client_key);
                    if (!next) {
                        return position;
                    }

                    const nextCustomKeys = customPositionTextFieldsFromSchema(
                        next.fieldSchema.fields,
                    );
                    const custom_fields = Object.fromEntries(
                        nextCustomKeys.map((field) => [
                            field.key,
                            position.custom_fields[field.key] ?? '',
                        ]),
                    );
                    const nextChoiceFields =
                        customPositionChoiceFieldsFromSchema(
                            next.fieldSchema.fields,
                        );
                    const custom_choice_fields: Record<string, ChoiceValue> = {
                        ...initChoiceValuesMap(nextChoiceFields, {}),
                    };
                    for (const field of nextChoiceFields) {
                        if (
                            Object.prototype.hasOwnProperty.call(
                                position.custom_choice_fields,
                                field.key,
                            )
                        ) {
                            custom_choice_fields[field.key] =
                                position.custom_choice_fields[field.key];
                        }
                    }

                    return {
                        ...position,
                        schema_fingerprint: next.fingerprint,
                        field_schema: next.fieldSchema,
                        custom_fields,
                        custom_choice_fields,
                    };
                }),
            );
        })();

        return () => {
            cancelled = true;
        };
    }, [calculation?.id, positions]);

    const [proposal, setProposal] = useState<Proposal | null>(
        initialBudgetApplied ? null : (latestBudgetProposal?.payload ?? null),
    );
    const [busy, setBusy] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [saveFieldErrors, setSaveFieldErrors] = useState<
        Record<string, string[]>
    >({});

    const [budgetReenterSetup, setBudgetReenterSetup] = useState(false);

    const planningViewState = {
        planningMode,
        budgetProposalStatus: calculation?.budget_proposal_status,
        budgetAppliedLocally,
        budgetProposalManual,
        positionsCount: positions.length,
        hasActiveProposal: proposal !== null,
        budgetReenterSetup,
    };
    const usesRegularPlanningEditorView =
        usesRegularPlanningEditor(planningViewState);
    const isBudgetSetup = isBudgetSetupPhase(planningViewState);

    const payload = useMemo(
        () => ({
            planning_mode: planningMode,
            customer_name: customerName || null,
            agency_name: agencyName || null,
            campaign: campaign || null,
            product_title: productTitle || null,
            briefing: briefing || null,
            dynamic_field_values: {
                campaign_period:
                    campaignPeriodStart || campaignPeriodEnd
                        ? {
                              start: campaignPeriodStart || null,
                              end: campaignPeriodEnd || null,
                          }
                        : null,
                ...Object.fromEntries(
                    customHeaderFields.map((field) => [
                        field.key,
                        customHeaderValues[field.key] ?? '',
                    ]),
                ),
                ...choiceValuesForPayload(
                    customHeaderChoiceFields,
                    customHeaderChoiceValues,
                ),
            },
            order_discount_percent: '0',
            order_discounts: payloadDiscounts(orderDiscounts),
            ae_enabled: aeEnabled,
            target_budget_nn: targetBudget === '' ? null : targetBudget,
            budget_strategy:
                planningMode === 'budget' ? 'equal_spot_count' : null,
            ...(isBudgetSetup && planningMode === 'budget'
                ? {
                      budget_elements: payloadBudgetElements(
                          budgetElements,
                      ).map((element) => ({
                          ...element,
                          position_discounts:
                              payloadBudgetElementDiscounts(
                                  budgetElements,
                                  budgetPositionDiscounts,
                              ).find(
                                  (row) => row.client_id === element.client_id,
                              )?.position_discounts ?? [],
                      })),
                  }
                : {}),
            budget_proposal_manual: budgetProposalManual,
            lock_version: calculation?.lock_version,
            calculation_id: calculation?.id,
            schema_fingerprint: fieldSchema.schema_fingerprint ?? null,
            positions: isBudgetSetup
                ? []
                : positions.map((position) => {
                      const ranges = payloadTimeRanges(position.time_ranges);
                      const methodPayload =
                          calculationMethodPayloadFields(position);

                      return {
                          id: position.id,
                          client_key: position.client_key,
                          inventory_id: position.inventory_id,
                          advertising_medium_id: position.advertising_medium_id,
                          schema_fingerprint:
                              position.schema_fingerprint ?? null,
                          ...methodPayload,
                          length_seconds: position.length_seconds,
                          total_spot_count: totalSpotCount(
                              position.time_ranges,
                          ),
                          needs_spot_redistribution:
                              position.needs_spot_redistribution ?? false,
                          position_discount_percent: '0',
                          ae_percent: '0',
                          time_ranges: ranges,
                          position_discounts: payloadDiscounts(
                              position.position_discounts,
                          ),
                          dynamic_field_values: {
                              period_open: position.period_open,
                              position_flight_period:
                                  position.flight_period_start ||
                                  position.flight_period_end
                                      ? {
                                            start:
                                                position.flight_period_start ||
                                                null,
                                            end:
                                                position.flight_period_end ||
                                                null,
                                        }
                                      : null,
                              ...Object.fromEntries(
                                  customPositionTextFieldsFromSchema(
                                      schemaFieldsForPosition(
                                          position,
                                          fieldSchema,
                                          existingGen3,
                                      ),
                                  ).map((field) => [
                                      field.key,
                                      position.custom_fields[field.key] ?? '',
                                  ]),
                              ),
                              ...choiceValuesForPayload(
                                  customPositionChoiceFieldsFromSchema(
                                      schemaFieldsForPosition(
                                          position,
                                          fieldSchema,
                                          existingGen3,
                                      ),
                                  ),
                                  position.custom_choice_fields,
                              ),
                          },
                          plan_rows: ranges.flatMap((range) =>
                              Array.from(
                                  {
                                      length:
                                          range.end_hour_exclusive -
                                          range.start_hour,
                                  },
                                  (_, offset) => ({
                                      hour: range.start_hour + offset,
                                      day_group: range.day_group,
                                  }),
                              ),
                          ),
                      };
                  }),
        }),
        [
            planningMode,
            customerName,
            agencyName,
            campaign,
            productTitle,
            briefing,
            campaignPeriodStart,
            campaignPeriodEnd,
            customHeaderFields,
            customHeaderChoiceFields,
            existingGen3,
            customHeaderValues,
            customHeaderChoiceValues,
            fieldSchema,
            orderDiscounts,
            aeEnabled,
            targetBudget,
            budgetElements,
            budgetPositionDiscounts,
            budgetProposalManual,
            isBudgetSetup,
            usesRegularPlanningEditorView,
            calculation,
            positions,
        ],
    );

    const steps = isBudgetSetup ? BUDGET_STEPS : MANUAL_STEPS;
    const budgetPreviewReady =
        planningMode === 'budget' &&
        usesRegularPlanningEditorView &&
        positions.length > 0;
    const showBudgetPlanningSidebar = isBudgetSetup;
    const showSaveButton = canEdit && !(isBudgetSetup && step === 3);

    function markBudgetInputsChanged() {
        setInputsRevision((value) => value + 1);
    }

    const {
        totals,
        previewLoading,
        error: previewError,
        fieldErrors: previewFieldErrors,
    } = useCalculationPreview<Totals>({
        url: '/kalkulationen/vorschau',
        payload,
        enabled: canEdit && (planningMode !== 'budget' || budgetPreviewReady),
        blocked: busy || proposalLoading,
    });
    const error = previewError ?? saveError ?? proposalError;
    const pageValidationErrors = mapValidationErrors(
        (usePage().props.errors ?? {}) as Record<string, string | string[]>,
    );
    const fieldErrors = {
        ...pageValidationErrors,
        ...previewFieldErrors,
        ...saveFieldErrors,
    };

    function allowedMediaFor(inventoryId: number) {
        const mediumIds = new Set(
            catalog.rules
                .filter(
                    (rule) =>
                        rule.inventory_id === inventoryId && rule.is_active,
                )
                .map((rule) => rule.advertising_medium_id),
        );

        return catalog.media.filter(
            (medium) =>
                isSelectableForNewWizardPositions(medium) &&
                mediumIds.has(medium.id),
        );
    }

    function updatePosition(index: number, patch: Partial<PositionDraft>) {
        if (usesRegularPlanningEditorView && planningMode === 'budget') {
            setBudgetProposalManual(true);
        }

        setPositions((current) =>
            current.map((item, itemIndex) => {
                if (itemIndex !== index) {
                    return item;
                }

                let next = { ...item, ...patch };

                if (
                    patch.inventory_id !== undefined ||
                    patch.advertising_medium_id !== undefined
                ) {
                    // Mediumwechsel: Fingerprint und Schema müssen neu geladen werden.
                    next = {
                        ...next,
                        schema_fingerprint: null,
                        field_schema: null,
                    };
                }

                if (patch.inventory_id !== undefined) {
                    const media = allowedMediaFor(patch.inventory_id);
                    const medium =
                        media.find(
                            (candidate) =>
                                candidate.id === item.advertising_medium_id,
                        ) ??
                        media.find(
                            (candidate) =>
                                candidate.id === next.advertising_medium_id,
                        ) ??
                        media[0];

                    if (!medium) {
                        return item;
                    }

                    const rule = ruleFor(
                        catalog,
                        patch.inventory_id,
                        medium.id,
                    );
                    const methodState = methodStateAfterMediumIdChange(
                        item.advertising_medium_id,
                        medium.id,
                        {
                            calculation_method_key: item.calculation_method_key,
                            calculation_method_name:
                                item.calculation_method_name,
                            historical_calculation_method_key:
                                item.historical_calculation_method_key,
                            historical_calculation_method_name:
                                item.historical_calculation_method_name,
                        },
                        medium.calculation_method_options,
                    );

                    next = {
                        ...next,
                        inventory_id: patch.inventory_id,
                        advertising_medium_id: medium.id,
                        ...methodState,
                        length_seconds:
                            rule?.default_length_seconds ??
                            medium.default_length_seconds,
                        position_discount_percent: '0',
                        ae_percent: '0',
                        plan_rows: [],
                        time_ranges: [emptyTimeRange()],
                        position_discounts: [],
                        total_spot_count: 0,
                    };
                } else if (patch.advertising_medium_id !== undefined) {
                    const medium = catalog.media.find(
                        (candidate) =>
                            candidate.id === patch.advertising_medium_id,
                    );
                    if (!medium) {
                        return item;
                    }

                    const rule = ruleFor(catalog, next.inventory_id, medium.id);
                    const methodState = methodStateAfterMediumIdChange(
                        item.advertising_medium_id,
                        medium.id,
                        {
                            calculation_method_key: item.calculation_method_key,
                            calculation_method_name:
                                item.calculation_method_name,
                            historical_calculation_method_key:
                                item.historical_calculation_method_key,
                            historical_calculation_method_name:
                                item.historical_calculation_method_name,
                        },
                        medium.calculation_method_options,
                    );

                    next = {
                        ...next,
                        advertising_medium_id: medium.id,
                        ...methodState,
                        length_seconds:
                            rule?.default_length_seconds ??
                            medium.default_length_seconds,
                        schema_fingerprint: null,
                        field_schema: null,
                    };
                }

                return next;
            }),
        );
    }

    function removePosition(index: number) {
        if (usesRegularPlanningEditorView && planningMode === 'budget') {
            setBudgetProposalManual(true);
        }

        setPositions((current) =>
            current.filter((_, itemIndex) => itemIndex !== index),
        );
    }

    function save() {
        const missingMethod = positions.find(
            (position) => !hasSubmittableCalculationMethodKey(position),
        );
        if (missingMethod && !isBudgetSetup) {
            setSaveError(
                `Speichern nicht möglich: ${NO_CALCULATION_METHOD_MESSAGE}`,
            );
            return;
        }

        setBusy(true);
        setSaveError(null);
        setSaveFieldErrors({});
        const url = calculation
            ? `/kalkulationen/${calculation.id}`
            : '/kalkulationen';

        const options = {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setBusy(false),
            // Freeze-Konflikt: keine Validierung, sondern veraltetes Feldschema.
            onHttpException: (response: HttpExceptionResponse) => {
                if (response.status !== 409) {
                    return;
                }

                setSaveError(
                    conflictMessage(response.data) ??
                        'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
                );
                setBusy(false);

                return false;
            },
            onError: (errors: Record<string, string | string[]>) => {
                const mapped = mapValidationErrors(errors);
                setSaveFieldErrors(mapped);
                const detail = firstValidationMessage(mapped);
                setSaveError(
                    detail
                        ? `Speichern nicht möglich: ${detail}`
                        : 'Speichern nicht möglich. Angaben prüfen.',
                );
                setBusy(false);
            },
        };

        if (calculation) {
            router.put(url, payload, options);
        } else {
            router.post(url, payload, options);
        }
    }

    const proposalStatus =
        proposal &&
        proposalAtRevision !== null &&
        inputsRevision !== proposalAtRevision
            ? 'stale'
            : latestBudgetProposal?.status === 'stale'
              ? 'stale'
              : (proposal?.status ??
                calculation?.budget_proposal_status ??
                'current');

    async function createProposal() {
        const basicsError = validateBudgetBasics(targetBudget);
        if (basicsError) {
            setProposalError(basicsError);
            return;
        }

        const frameError = validateBudgetElements(budgetElements);
        if (frameError) {
            setProposalError(frameError);
            return;
        }

        setProposalLoading(true);
        setBusy(true);
        setProposalError(null);
        setSaveError(null);
        setSaveFieldErrors({});
        try {
            const proposalPayload = buildBudgetProposalPayload({
                planningMode,
                customerName,
                agencyName,
                campaign,
                productTitle,
                briefing,
                orderDiscounts,
                aeEnabled,
                targetBudget,
                budgetElements,
                budgetPositionDiscounts,
                calculationId: calculation?.id,
                lockVersion: calculation?.lock_version,
            });
            const data = await jsonPost<{ proposal: Proposal }>(
                '/kalkulationen/budget-vorschlag',
                proposalPayload,
            );
            setProposal(data.proposal);
            setProposalAtRevision(inputsRevision);
            setStep(3);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setSaveFieldErrors(caught.fieldErrors);
                setProposalError(caught.message);
            } else {
                setProposalError(
                    caught instanceof Error
                        ? caught.message
                        : 'Vorschlag nicht möglich.',
                );
            }
        } finally {
            setProposalLoading(false);
            setBusy(false);
        }
    }

    function takeProposal() {
        if (!proposal) {
            return;
        }

        if (calculation?.id && proposal.id) {
            setBusy(true);
            router.post(
                `/kalkulationen/${calculation.id}/budget-vorschlaege/${proposal.id}/uebernehmen`,
                {},
                {
                    preserveState: false,
                    onSuccess: () => setProposal(null),
                    onFinish: () => setBusy(false),
                    onError: () => {
                        setSaveError('Übernahme nicht möglich.');
                        setBusy(false);
                    },
                },
            );
            return;
        }

        setPositions(
            proposal.positions.map((item) => {
                const existing = positions.find(
                    (position) => position.inventory_id === item.inventory_id,
                );
                const mediumId =
                    spotClassicMedium?.id ??
                    existing?.advertising_medium_id ??
                    0;
                const proposalDiscounts = (
                    item as Proposal['positions'][number] & {
                        position_discounts?: Array<{
                            type: string;
                            custom_label: string | null;
                            percent: string;
                        }>;
                    }
                ).position_discounts;
                const element = budgetElements.find(
                    (candidate) => candidate.inventory_id === item.inventory_id,
                );

                const time_ranges = (item.time_ranges ?? [])
                    .filter((range) => Number(range.spot_count) > 0)
                    .map((range) => ({
                        start_hour: range.start_hour,
                        end_hour_exclusive: range.end_hour_exclusive,
                        day_group: range.day_group,
                        spot_count: range.spot_count,
                    }));
                const summedSpotCount = time_ranges.reduce(
                    (sum, range) => sum + Number(range.spot_count ?? 0),
                    0,
                );

                return {
                    id: existing?.id,
                    client_key: existing?.client_key ?? newClientKey(),
                    inventory_id: item.inventory_id,
                    advertising_medium_id: mediumId,
                    ...initMethodStateForNewPosition(
                        methodOptionsForMedium(catalog, mediumId),
                    ),
                    length_seconds:
                        item.length_seconds ??
                        element?.spot_length_seconds ??
                        defaultSpotLength,
                    total_spot_count:
                        summedSpotCount > 0
                            ? summedSpotCount
                            : item.total_spot_count,
                    needs_spot_redistribution: false,
                    position_discount_percent: '0',
                    ae_percent: '0',
                    time_ranges,
                    position_discounts:
                        proposalDiscounts?.map((discount) => ({
                            type: discount.type,
                            custom_label: discount.custom_label ?? '',
                            percent: discount.percent,
                        })) ??
                        (element
                            ? (budgetPositionDiscounts[element.client_id] ?? [])
                            : []),
                    plan_rows: [],
                    period_open: existing?.period_open ?? true,
                    flight_period_start: existing?.flight_period_start ?? '',
                    flight_period_end: existing?.flight_period_end ?? '',
                    custom_fields: existing?.custom_fields ?? {},
                    custom_choice_fields: existing?.custom_choice_fields ?? {},
                };
            }),
        );
        setProposal(null);
        setProposalAtRevision(null);
        setBudgetAppliedLocally(true);
        setBudgetReenterSetup(false);
        setStep(1);
    }

    function startBudgetReoptimize() {
        const confirmed = window.confirm(
            'Bei einer Neuoptimierung können deine manuellen Änderungen an Spotzahlen und Zeiträumen ersetzt werden.',
        );
        if (!confirmed) {
            return;
        }

        setBudgetReenterSetup(true);
        setBudgetAppliedLocally(false);
        setBudgetProposalManual(false);
        setProposal(null);
        setProposalAtRevision(null);
        setProposalError(null);
        setStep(0);
    }

    const displayTotals = canEdit ? totals : null;
    const summary = !canEdit && savedSummary ? savedSummary : null;
    const summaryTotals = displayTotals ?? savedDisplayTotals ?? null;
    const hasActiveCatalog =
        catalog.inventories.some((item) => item.is_active) &&
        catalog.media.some((item) => isSelectableForNewWizardPositions(item));
    const catalogMissing =
        planningMode === 'manual' &&
        positions.length === 0 &&
        !hasActiveCatalog;

    function goNext() {
        if (isBudgetSetup) {
            if (step === 0) {
                const basicsError = validateBudgetBasics(targetBudget);
                if (basicsError) {
                    setProposalError(basicsError);
                    return;
                }
            }

            if (step === 1) {
                const frameError = validateBudgetElements(budgetElements);
                if (frameError) {
                    setProposalError(frameError);
                    return;
                }
            }
        }

        setProposalError(null);
        setStep(step + 1);
    }

    const nextLabel =
        isBudgetSetup && step === 1 ? 'Weiter zu Konditionen' : 'Weiter';

    return (
        <>
            <Head
                title={
                    calculation
                        ? `Kalkulation ${calculation.id}`
                        : 'Neue Kalkulation'
                }
            />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
                <PageHeader
                    title={calculation ? `Kalkulation` : 'Neue Kalkulation'}
                />
                {!canEdit ? (
                    <StatusBanner>
                        Lesemodus – keine Änderungen möglich.
                    </StatusBanner>
                ) : null}
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {dispoOrderRevision ? (
                    <DispoOrderRevisionBanner revision={dispoOrderRevision} />
                ) : null}
                {planningMode === 'budget' && usesRegularPlanningEditorView ? (
                    <div
                        className="flex flex-wrap items-center gap-3"
                        data-test="budget-applied-hint"
                    >
                        <StatusBanner className="flex-1">
                            Mit Budget geplant · anschließend frei bearbeitbar
                            {budgetProposalManual ? ' · manuell angepasst' : ''}
                            {targetBudget.trim() !== ''
                                ? ` · Zielbudget: ${money(targetBudget)} N/N`
                                : ''}
                        </StatusBanner>
                        {canEdit ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-test="budget-reoptimize"
                                onClick={startBudgetReoptimize}
                            >
                                Budget neu optimieren
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                {error ? (
                    <ErrorState message={error} data-test="preview-error" />
                ) : null}

                <WizardStepper
                    steps={steps}
                    currentStep={step}
                    onStepChange={setStep}
                />

                <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:grid-rows-[auto_auto] lg:items-start">
                    <div className="order-1 min-w-0 space-y-6 lg:col-start-1 lg:row-start-1">
                        {step === 0 ? (
                            <div className="space-y-6">
                                <Card className={wizardCardClass}>
                                    <CardHeader
                                        className={wizardCardHeaderClass}
                                    >
                                        <CardTitle
                                            className={wizardCardTitleClass}
                                        >
                                            Planungsweg
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent
                                        className={wizardCardContentClass}
                                    >
                                        <SelectionCardGrid className="sm:grid-cols-2">
                                            <SelectionCard
                                                name="planning_mode"
                                                checked={
                                                    planningMode === 'manual'
                                                }
                                                disabled={!canEdit}
                                                onChange={() => {
                                                    setPlanningMode('manual');
                                                    if (
                                                        positions.length ===
                                                            0 &&
                                                        hasActiveCatalog
                                                    ) {
                                                        const first =
                                                            firstValidPosition(
                                                                catalog,
                                                            );
                                                        if (first) {
                                                            setPositions([
                                                                first,
                                                            ]);
                                                        }
                                                    }
                                                }}
                                            >
                                                <SelectionCardOption
                                                    icon={SlidersHorizontal}
                                                    title="Selbst planen"
                                                    description="Sender, Werbeelemente und Konditionen manuell festlegen."
                                                />
                                            </SelectionCard>
                                            <SelectionCard
                                                name="planning_mode"
                                                checked={
                                                    planningMode === 'budget'
                                                }
                                                disabled={!canEdit}
                                                onChange={() => {
                                                    setPlanningMode('budget');
                                                    if (
                                                        !calculation?.positions
                                                            ?.length
                                                    ) {
                                                        setPositions([]);
                                                        setProposal(null);
                                                        setProposalAtRevision(
                                                            null,
                                                        );
                                                    }
                                                }}
                                            >
                                                <div data-test="planning-mode-budget">
                                                    <SelectionCardOption
                                                        icon={Wallet}
                                                        title="Mit Budget planen"
                                                        description="Zielbudget und Verteilungslogik vorgeben."
                                                    />
                                                </div>
                                            </SelectionCard>
                                        </SelectionCardGrid>
                                    </CardContent>
                                </Card>

                                <Card className={wizardCardClass}>
                                    <CardHeader
                                        className={wizardCardHeaderClass}
                                    >
                                        <CardTitle
                                            className={wizardCardTitleClass}
                                        >
                                            Grunddaten
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent
                                        className={`${wizardCardContentClass} grid gap-4 sm:grid-cols-2`}
                                    >
                                        <FormField
                                            label="Kunde"
                                            htmlFor="customer"
                                        >
                                            <Input
                                                id="customer"
                                                value={customerName}
                                                onChange={(event) =>
                                                    setCustomerName(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        <FormField
                                            label="Agentur"
                                            htmlFor="agency"
                                        >
                                            <Input
                                                id="agency"
                                                value={agencyName}
                                                onChange={(event) =>
                                                    setAgencyName(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        <FormField
                                            label="Kampagne"
                                            htmlFor="campaign"
                                        >
                                            <Input
                                                id="campaign"
                                                value={campaign}
                                                onChange={(event) =>
                                                    setCampaign(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        <FormField
                                            label="Produkt / Titel"
                                            htmlFor="product"
                                        >
                                            <Input
                                                id="product"
                                                value={productTitle}
                                                onChange={(event) =>
                                                    setProductTitle(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        <FormField
                                            label="Briefing (optional)"
                                            htmlFor="briefing"
                                        >
                                            <textarea
                                                id="briefing"
                                                className={`${formTextareaClass} sm:col-span-2`}
                                                value={briefing}
                                                onChange={(event) =>
                                                    setBriefing(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        <FormField
                                            label={
                                                fieldSchema.fields.find(
                                                    (field) =>
                                                        field.key ===
                                                        'campaign_period',
                                                )?.label ?? 'Kampagnenzeitraum'
                                            }
                                            htmlFor="campaign-period-start"
                                            hint={
                                                fieldSchema.fields.find(
                                                    (field) =>
                                                        field.key ===
                                                        'campaign_period',
                                                )?.help_text ?? undefined
                                            }
                                        >
                                            <div className="grid grid-cols-2 gap-2">
                                                <Input
                                                    id="campaign-period-start"
                                                    type="date"
                                                    value={campaignPeriodStart}
                                                    onChange={(event) =>
                                                        setCampaignPeriodStart(
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={!canEdit}
                                                    data-test="campaign-period-start"
                                                />
                                                <Input
                                                    id="campaign-period-end"
                                                    type="date"
                                                    value={campaignPeriodEnd}
                                                    onChange={(event) =>
                                                        setCampaignPeriodEnd(
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={!canEdit}
                                                    data-test="campaign-period-end"
                                                />
                                            </div>
                                        </FormField>
                                        {customHeaderFields.length > 0 ||
                                        visibleHeaderChoiceFields.length > 0 ? (
                                            <div
                                                className="space-y-3 sm:col-span-2"
                                                data-test="calculation-custom-header-fields"
                                            >
                                                <h3 className="text-sm font-semibold">
                                                    Weitere Angaben
                                                </h3>
                                                <SchemaTextFields
                                                    fields={customHeaderFields}
                                                    values={customHeaderValues}
                                                    errors={fieldErrors}
                                                    disabled={!canEdit}
                                                    idPrefix="calc-custom"
                                                    onChange={(key, value) =>
                                                        setCustomHeaderValues(
                                                            (current) => ({
                                                                ...current,
                                                                [key]: value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                <SchemaChoiceFields
                                                    fields={
                                                        customHeaderChoiceFields
                                                    }
                                                    values={
                                                        customHeaderChoiceValues
                                                    }
                                                    errors={fieldErrors}
                                                    disabled={!canEdit}
                                                    idPrefix="calc-choice"
                                                    onChange={(key, value) =>
                                                        setCustomHeaderChoiceValues(
                                                            (current) => ({
                                                                ...current,
                                                                [key]: value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </div>
                                        ) : null}
                                        <FormField
                                            label="Zielbudget N/N"
                                            htmlFor="budget"
                                            hint={
                                                planningMode === 'manual'
                                                    ? 'Nur Vergleich mit dem aktuellen N/N-Invest.'
                                                    : 'Das Zielbudget wird bei der Berechnung nicht überschritten.'
                                            }
                                        >
                                            <Input
                                                id="budget"
                                                inputMode="decimal"
                                                value={targetBudget}
                                                onChange={(event) => {
                                                    setTargetBudget(
                                                        event.target.value,
                                                    );
                                                    markBudgetInputsChanged();
                                                }}
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                    </CardContent>
                                </Card>
                            </div>
                        ) : null}

                        {step === 1 ? (
                            catalogMissing ||
                            (isBudgetSetup && !hasActiveCatalog) ? (
                                <EmptyState
                                    title="Kein Katalog"
                                    description="Sender, Werbemittel und Preise fehlen. Es werden keine Beispieldaten vorgetäuscht."
                                />
                            ) : isBudgetSetup ? (
                                <BudgetElementsStep
                                    elements={budgetElements}
                                    inventories={catalog.inventories}
                                    dayGroups={dayGroups}
                                    canEdit={canEdit}
                                    fieldErrors={fieldErrors}
                                    onChange={(elements) => {
                                        setBudgetElements(elements);
                                        markBudgetInputsChanged();
                                    }}
                                />
                            ) : (
                                <div className="space-y-6">
                                    {positions.map((position, index) => {
                                        const positionFields =
                                            schemaFieldsForPosition(
                                                position,
                                                fieldSchema,
                                                existingGen3,
                                            );
                                        const positionRules =
                                            schemaRulesForPosition(
                                                position,
                                                fieldSchema,
                                                existingGen3,
                                            );
                                        const positionCustomFields =
                                            customPositionTextFieldsFromSchema(
                                                positionFields,
                                            );
                                        const positionChoiceFields =
                                            customPositionChoiceFieldsFromSchema(
                                                positionFields,
                                            );
                                        const visiblePositionChoiceFields =
                                            visibleChoiceFields(
                                                positionChoiceFields,
                                            );

                                        return (
                                            <section
                                                key={position.client_key}
                                                aria-labelledby={`pos-${index}`}
                                            >
                                                <Card
                                                    className={wizardCardClass}
                                                >
                                                    <CardHeader
                                                        className={
                                                            wizardCardHeaderClass
                                                        }
                                                    >
                                                        <CardTitle
                                                            id={`pos-${index}`}
                                                            className={
                                                                wizardCardTitleClass
                                                            }
                                                        >
                                                            Werbeelement{' '}
                                                            {index + 1}
                                                        </CardTitle>
                                                    </CardHeader>
                                                    <CardContent
                                                        className={`${wizardCardContentClass} space-y-6`}
                                                    >
                                                        <div className="space-y-3">
                                                            <p className="text-sm font-medium">
                                                                Sender / Kombi
                                                            </p>
                                                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                                {catalog.inventories.map(
                                                                    (item) => {
                                                                        const selected =
                                                                            position.inventory_id ===
                                                                            item.id;
                                                                        const disabled =
                                                                            !canEdit ||
                                                                            (!item.is_active &&
                                                                                item.id !==
                                                                                    position.inventory_id);

                                                                        return (
                                                                            <button
                                                                                key={
                                                                                    item.id
                                                                                }
                                                                                type="button"
                                                                                disabled={
                                                                                    disabled
                                                                                }
                                                                                aria-pressed={
                                                                                    selected
                                                                                }
                                                                                onClick={() =>
                                                                                    updatePosition(
                                                                                        index,
                                                                                        {
                                                                                            inventory_id:
                                                                                                item.id,
                                                                                        },
                                                                                    )
                                                                                }
                                                                                className={cn(
                                                                                    'focus-visible:ring-primary/25 relative flex w-full flex-col gap-3 rounded-xl border-2 p-4 text-left transition-all outline-none focus-visible:ring-[3px]',
                                                                                    selected
                                                                                        ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                                                                                        : 'border-border/80 bg-card hover:border-primary/45 hover:bg-muted/20',
                                                                                    disabled &&
                                                                                        'cursor-not-allowed opacity-50',
                                                                                )}
                                                                            >
                                                                                {selected ? (
                                                                                    <span className="bg-primary text-primary-foreground pointer-events-none absolute top-3 right-3 flex size-5 items-center justify-center rounded-full">
                                                                                        <Check
                                                                                            className="size-3"
                                                                                            aria-hidden="true"
                                                                                        />
                                                                                    </span>
                                                                                ) : null}
                                                                                <LogoSlot
                                                                                    name={catalogLabel(
                                                                                        item.name,
                                                                                        item.is_active,
                                                                                    )}
                                                                                    logoPath={
                                                                                        item.logo_path
                                                                                    }
                                                                                    variant="card"
                                                                                />
                                                                                <span className="pr-6 text-sm leading-snug font-semibold">
                                                                                    {catalogLabel(
                                                                                        item.name,
                                                                                        item.is_active,
                                                                                    )}
                                                                                </span>
                                                                            </button>
                                                                        );
                                                                    },
                                                                )}
                                                            </div>
                                                            <select
                                                                className="sr-only"
                                                                data-test={`position-inventory-${index}`}
                                                                value={
                                                                    position.inventory_id
                                                                }
                                                                disabled={
                                                                    !canEdit
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updatePosition(
                                                                        index,
                                                                        {
                                                                            inventory_id:
                                                                                Number(
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                        },
                                                                    )
                                                                }
                                                                tabIndex={-1}
                                                                aria-hidden="true"
                                                            >
                                                                {catalog.inventories.map(
                                                                    (item) => (
                                                                        <option
                                                                            key={
                                                                                item.id
                                                                            }
                                                                            value={
                                                                                item.id
                                                                            }
                                                                            disabled={
                                                                                !item.is_active &&
                                                                                item.id !==
                                                                                    position.inventory_id
                                                                            }
                                                                        >
                                                                            {catalogLabel(
                                                                                item.name,
                                                                                item.is_active,
                                                                            )}
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </div>

                                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                                            <FormField
                                                                label="Werbemittel"
                                                                htmlFor={`medium-${index}`}
                                                            >
                                                                <select
                                                                    id={`medium-${index}`}
                                                                    data-test={`position-medium-${index}`}
                                                                    className={
                                                                        formSelectClass
                                                                    }
                                                                    value={
                                                                        position.advertising_medium_id
                                                                    }
                                                                    disabled={
                                                                        !canEdit
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updatePosition(
                                                                            index,
                                                                            {
                                                                                advertising_medium_id:
                                                                                    Number(
                                                                                        event
                                                                                            .target
                                                                                            .value,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {(() => {
                                                                        const allowed =
                                                                            allowedMediaFor(
                                                                                position.inventory_id,
                                                                            );
                                                                        const current =
                                                                            catalog.media.find(
                                                                                (
                                                                                    medium,
                                                                                ) =>
                                                                                    medium.id ===
                                                                                    position.advertising_medium_id,
                                                                            );
                                                                        const options =
                                                                            current &&
                                                                            !allowed.some(
                                                                                (
                                                                                    medium,
                                                                                ) =>
                                                                                    medium.id ===
                                                                                    current.id,
                                                                            )
                                                                                ? [
                                                                                      current,
                                                                                      ...allowed,
                                                                                  ]
                                                                                : allowed;

                                                                        return options.map(
                                                                            (
                                                                                medium,
                                                                            ) => (
                                                                                <option
                                                                                    key={
                                                                                        medium.id
                                                                                    }
                                                                                    value={
                                                                                        medium.id
                                                                                    }
                                                                                >
                                                                                    {catalogLabel(
                                                                                        medium.name,
                                                                                        medium.is_active,
                                                                                    )}
                                                                                </option>
                                                                            ),
                                                                        );
                                                                    })()}
                                                                </select>
                                                            </FormField>
                                                            <FormField
                                                                label="Länge (Sekunden)"
                                                                htmlFor={`length-${index}`}
                                                            >
                                                                <Input
                                                                    id={`length-${index}`}
                                                                    data-test={`position-length-seconds-${index}`}
                                                                    type="number"
                                                                    min={1}
                                                                    value={
                                                                        position.length_seconds
                                                                    }
                                                                    disabled={
                                                                        !canEdit
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updatePosition(
                                                                            index,
                                                                            {
                                                                                length_seconds:
                                                                                    Number(
                                                                                        event
                                                                                            .target
                                                                                            .value,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormField>
                                                            <FormField
                                                                label={
                                                                    positionFields.find(
                                                                        (
                                                                            field,
                                                                        ) =>
                                                                            field.key ===
                                                                            'period_open',
                                                                    )?.label ??
                                                                    'Zeitraum offen'
                                                                }
                                                                htmlFor={`period-open-${index}`}
                                                                hint={
                                                                    positionFields.find(
                                                                        (
                                                                            field,
                                                                        ) =>
                                                                            field.key ===
                                                                            'period_open',
                                                                    )
                                                                        ?.help_text ??
                                                                    undefined
                                                                }
                                                            >
                                                                <Checkbox
                                                                    id={`period-open-${index}`}
                                                                    data-test={`period-open-${index}`}
                                                                    checked={
                                                                        position.period_open
                                                                    }
                                                                    disabled={
                                                                        !canEdit
                                                                    }
                                                                    onCheckedChange={(
                                                                        checked,
                                                                    ) =>
                                                                        updatePosition(
                                                                            index,
                                                                            {
                                                                                period_open:
                                                                                    checked ===
                                                                                    true,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormField>
                                                        </div>

                                                        <CalculationMethodSelector
                                                            positionIndex={
                                                                index
                                                            }
                                                            options={methodOptionsForMedium(
                                                                catalog,
                                                                position.advertising_medium_id,
                                                            )}
                                                            state={{
                                                                calculation_method_key:
                                                                    position.calculation_method_key,
                                                                calculation_method_name:
                                                                    position.calculation_method_name,
                                                                historical_calculation_method_key:
                                                                    position.historical_calculation_method_key,
                                                                historical_calculation_method_name:
                                                                    position.historical_calculation_method_name,
                                                            }}
                                                            disabled={!canEdit}
                                                            error={
                                                                fieldErrors[
                                                                    `positions.${index}.calculation_method_key`
                                                                ]?.[0]
                                                            }
                                                            onSelectLiveKey={(
                                                                key,
                                                            ) => {
                                                                const next =
                                                                    selectLiveCalculationMethod(
                                                                        {
                                                                            calculation_method_key:
                                                                                position.calculation_method_key,
                                                                            calculation_method_name:
                                                                                position.calculation_method_name,
                                                                            historical_calculation_method_key:
                                                                                position.historical_calculation_method_key,
                                                                            historical_calculation_method_name:
                                                                                position.historical_calculation_method_name,
                                                                        },
                                                                        methodOptionsForMedium(
                                                                            catalog,
                                                                            position.advertising_medium_id,
                                                                        ),
                                                                        key,
                                                                    );
                                                                updatePosition(
                                                                    index,
                                                                    next,
                                                                );
                                                            }}
                                                            onRestoreHistorical={() =>
                                                                updatePosition(
                                                                    index,
                                                                    restoreHistoricalCalculationMethod(
                                                                        {
                                                                            calculation_method_key:
                                                                                position.calculation_method_key,
                                                                            calculation_method_name:
                                                                                position.calculation_method_name,
                                                                            historical_calculation_method_key:
                                                                                position.historical_calculation_method_key,
                                                                            historical_calculation_method_name:
                                                                                position.historical_calculation_method_name,
                                                                        },
                                                                    ),
                                                                )
                                                            }
                                                        />

                                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                                            {requiredPositionFieldKeysFromSnapshotRules(
                                                                positionRules,
                                                                {
                                                                    period_open:
                                                                        position.period_open,
                                                                    position_flight_period:
                                                                        position.flight_period_start ||
                                                                        position.flight_period_end
                                                                            ? {
                                                                                  start: position.flight_period_start,
                                                                                  end: position.flight_period_end,
                                                                              }
                                                                            : null,
                                                                },
                                                            ).includes(
                                                                'position_flight_period',
                                                            ) ? (
                                                                <FormField
                                                                    label={
                                                                        positionFields.find(
                                                                            (
                                                                                field,
                                                                            ) =>
                                                                                field.key ===
                                                                                'position_flight_period',
                                                                        )
                                                                            ?.label ??
                                                                        'Flugzeitraum'
                                                                    }
                                                                    htmlFor={`flight-start-${index}`}
                                                                    hint={
                                                                        positionFields.find(
                                                                            (
                                                                                field,
                                                                            ) =>
                                                                                field.key ===
                                                                                'position_flight_period',
                                                                        )
                                                                            ?.help_text ??
                                                                        undefined
                                                                    }
                                                                >
                                                                    <div className="grid grid-cols-2 gap-2">
                                                                        <Input
                                                                            id={`flight-start-${index}`}
                                                                            type="date"
                                                                            value={
                                                                                position.flight_period_start
                                                                            }
                                                                            disabled={
                                                                                !canEdit
                                                                            }
                                                                            data-test={`flight-period-start-${index}`}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updatePosition(
                                                                                    index,
                                                                                    {
                                                                                        flight_period_start:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    },
                                                                                )
                                                                            }
                                                                        />
                                                                        <Input
                                                                            id={`flight-end-${index}`}
                                                                            type="date"
                                                                            value={
                                                                                position.flight_period_end
                                                                            }
                                                                            disabled={
                                                                                !canEdit
                                                                            }
                                                                            data-test={`flight-period-end-${index}`}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updatePosition(
                                                                                    index,
                                                                                    {
                                                                                        flight_period_end:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    },
                                                                                )
                                                                            }
                                                                        />
                                                                    </div>
                                                                </FormField>
                                                            ) : null}
                                                            {positionCustomFields.length >
                                                                0 ||
                                                            visiblePositionChoiceFields.length >
                                                                0 ? (
                                                                <div
                                                                    className="space-y-3 sm:col-span-2"
                                                                    data-test={`calculation-custom-position-fields-${position.client_key}`}
                                                                >
                                                                    <h3 className="text-sm font-semibold">
                                                                        Weitere
                                                                        Angaben
                                                                    </h3>
                                                                    <SchemaTextFields
                                                                        fields={
                                                                            positionCustomFields
                                                                        }
                                                                        values={
                                                                            position.custom_fields
                                                                        }
                                                                        errors={
                                                                            fieldErrors
                                                                        }
                                                                        errorKeyPrefixes={[
                                                                            `positions.${index}.dynamic_field_values`,
                                                                        ]}
                                                                        disabled={
                                                                            !canEdit
                                                                        }
                                                                        idPrefix={`calc-pos-custom-${position.client_key}`}
                                                                        onChange={(
                                                                            key,
                                                                            value,
                                                                        ) =>
                                                                            updatePosition(
                                                                                index,
                                                                                {
                                                                                    custom_fields:
                                                                                        {
                                                                                            ...position.custom_fields,
                                                                                            [key]: value,
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    <SchemaChoiceFields
                                                                        fields={
                                                                            positionChoiceFields
                                                                        }
                                                                        values={
                                                                            position.custom_choice_fields
                                                                        }
                                                                        errors={
                                                                            fieldErrors
                                                                        }
                                                                        errorKeyPrefixes={[
                                                                            `positions.${index}.dynamic_field_values`,
                                                                        ]}
                                                                        disabled={
                                                                            !canEdit
                                                                        }
                                                                        idPrefix={`calc-pos-choice-${position.client_key}`}
                                                                        onChange={(
                                                                            key,
                                                                            value,
                                                                        ) =>
                                                                            updatePosition(
                                                                                index,
                                                                                {
                                                                                    custom_choice_fields:
                                                                                        {
                                                                                            ...position.custom_choice_fields,
                                                                                            [key]: value,
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                </div>
                                                            ) : null}
                                                            {canEdit &&
                                                            positions.length >
                                                                1 ? (
                                                                <div className="flex items-end">
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="sm"
                                                                        onClick={() =>
                                                                            removePosition(
                                                                                index,
                                                                            )
                                                                        }
                                                                    >
                                                                        Entfernen
                                                                    </Button>
                                                                </div>
                                                            ) : null}
                                                        </div>

                                                        <PriceTimeRanges
                                                            positionIndex={
                                                                index
                                                            }
                                                            ranges={
                                                                position.time_ranges
                                                            }
                                                            dayGroups={
                                                                dayGroups
                                                            }
                                                            canEdit={canEdit}
                                                            fieldErrors={
                                                                fieldErrors
                                                            }
                                                            rangeTotals={
                                                                displayTotals
                                                                    ?.positions[
                                                                    index
                                                                ]?.time_ranges
                                                            }
                                                            legacyTotalSpotCount={
                                                                position.needs_spot_redistribution
                                                                    ? position.total_spot_count
                                                                    : null
                                                            }
                                                            needsRedistribution={
                                                                position.needs_spot_redistribution
                                                            }
                                                            onChange={(
                                                                time_ranges,
                                                            ) =>
                                                                updatePosition(
                                                                    index,
                                                                    {
                                                                        time_ranges,
                                                                        total_spot_count:
                                                                            totalSpotCount(
                                                                                time_ranges,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                        />

                                                        {displayTotals
                                                            ?.positions[
                                                            index
                                                        ] && !previewLoading ? (
                                                            <PositionPriceSummary
                                                                averageSecondPrice={
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ]
                                                                        .average_second_price
                                                                }
                                                                mediaGross={
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ]
                                                                        .media_gross
                                                                }
                                                                nnInvest={
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ].nn_invest
                                                                }
                                                            />
                                                        ) : previewLoading ? (
                                                            <LoadingState
                                                                label="Berechnet"
                                                                data-test="preview-loading"
                                                            />
                                                        ) : null}
                                                    </CardContent>
                                                </Card>
                                            </section>
                                        );
                                    })}
                                    {canEdit ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="w-full border-dashed"
                                            onClick={() => {
                                                const next =
                                                    firstValidPosition(catalog);
                                                if (next) {
                                                    setPositions([
                                                        ...positions,
                                                        next,
                                                    ]);
                                                }
                                            }}
                                        >
                                            Werbeelement hinzufügen
                                        </Button>
                                    ) : null}
                                </div>
                            )
                        ) : null}

                        {step === 2 ? (
                            isBudgetSetup ? (
                                <div className="space-y-6">
                                    <BudgetConditionsStep
                                        budgetElements={budgetElements}
                                        inventories={catalog.inventories}
                                        catalogRules={catalog.rules}
                                        budgetPositionDiscounts={
                                            budgetPositionDiscounts
                                        }
                                        orderDiscounts={orderDiscounts}
                                        aeEnabled={aeEnabled}
                                        discountTypes={discountTypes}
                                        canEdit={canEdit}
                                        fieldErrors={fieldErrors}
                                        onBudgetPositionDiscountsChange={(
                                            discounts,
                                        ) => {
                                            setBudgetPositionDiscounts(
                                                discounts,
                                            );
                                            markBudgetInputsChanged();
                                        }}
                                        onOrderDiscountsChange={(discounts) => {
                                            setOrderDiscounts(discounts);
                                            markBudgetInputsChanged();
                                        }}
                                        onAeEnabledChange={(enabled) => {
                                            setAeEnabled(enabled);
                                            markBudgetInputsChanged();
                                        }}
                                    />
                                    {proposalStatus === 'stale' ? (
                                        <StatusBanner data-test="budget-stale-hint">
                                            Die Eingaben haben sich geändert.
                                            Berechne den Vorschlag neu, bevor du
                                            ihn übernimmst.
                                        </StatusBanner>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="space-y-6">
                                    {positions.map((position, index) => {
                                        const inventory =
                                            catalog.inventories.find(
                                                (item) =>
                                                    item.id ===
                                                    position.inventory_id,
                                            );
                                        const rule = ruleFor(
                                            catalog,
                                            position.inventory_id,
                                            position.advertising_medium_id,
                                        );

                                        return (
                                            <Card
                                                key={`cond-${position.client_key}`}
                                                className={wizardCardClass}
                                            >
                                                <CardHeader
                                                    className={
                                                        wizardCardHeaderClass
                                                    }
                                                >
                                                    <CardTitle
                                                        className={`${wizardCardTitleClass} flex items-center gap-2`}
                                                    >
                                                        <LogoSlot
                                                            name={
                                                                inventory?.name ??
                                                                'Sender'
                                                            }
                                                            logoPath={
                                                                inventory?.logo_path
                                                            }
                                                        />
                                                        Rabatte für{' '}
                                                        {inventory?.name ??
                                                            'dieses Werbeelement'}
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent
                                                    className={`${wizardCardContentClass} space-y-4`}
                                                >
                                                    <p className="text-muted-foreground text-sm">
                                                        Bruttoausgangswert{' '}
                                                        <span className="text-foreground font-medium">
                                                            {displayTotals &&
                                                            !previewLoading &&
                                                            displayTotals
                                                                ?.positions[
                                                                index
                                                            ]?.media_gross
                                                                ? money(
                                                                      displayTotals
                                                                          .positions[
                                                                          index
                                                                      ]
                                                                          .media_gross,
                                                                  )
                                                                : previewLoading
                                                                  ? '…'
                                                                  : '–'}
                                                        </span>
                                                    </p>
                                                    <DiscountListEditor
                                                        title={`Rabatte für ${inventory?.name ?? 'dieses Werbeelement'}`}
                                                        description="Diese Rabatte gelten nur für dieses Werbeelement und werden nacheinander gerechnet."
                                                        discounts={
                                                            position.position_discounts
                                                        }
                                                        types={discountTypes}
                                                        canEdit={canEdit}
                                                        disabled={
                                                            rule?.is_discountable ===
                                                            false
                                                        }
                                                        fieldPrefix={`positions.${index}.position_discounts`}
                                                        fieldErrors={
                                                            fieldErrors
                                                        }
                                                        breakdown={
                                                            displayTotals
                                                                ?.positions[
                                                                index
                                                            ]
                                                                ?.position_discounts
                                                        }
                                                        onChange={(
                                                            position_discounts,
                                                        ) =>
                                                            updatePosition(
                                                                index,
                                                                {
                                                                    position_discounts,
                                                                },
                                                            )
                                                        }
                                                    />
                                                    {displayTotals?.positions[
                                                        index
                                                    ]
                                                        ?.after_position_discount ? (
                                                        <p className="text-sm">
                                                            Verbleibende
                                                            Positionssumme{' '}
                                                            <span className="font-medium">
                                                                {money(
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ]
                                                                        .after_position_discount,
                                                                )}
                                                            </span>
                                                        </p>
                                                    ) : null}
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                    <Card className={wizardCardClass}>
                                        <CardHeader
                                            className={wizardCardHeaderClass}
                                        >
                                            <CardTitle
                                                className={wizardCardTitleClass}
                                            >
                                                Auftragskonditionen
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent
                                            className={wizardCardContentClass}
                                        >
                                            <div className="space-y-4">
                                                <DiscountListEditor
                                                    title="Rabatte auf den Gesamtauftrag"
                                                    description="Diese Rabatte werden nach den Rabatten der einzelnen Werbeelemente auf die verbleibende Auftragssumme angewendet."
                                                    discounts={orderDiscounts}
                                                    types={discountTypes}
                                                    canEdit={canEdit}
                                                    fieldPrefix="order_discounts"
                                                    fieldErrors={fieldErrors}
                                                    breakdown={
                                                        displayTotals?.order_discounts
                                                    }
                                                    onChange={setOrderDiscounts}
                                                />
                                                <label className="flex items-start gap-3 text-sm">
                                                    <Checkbox
                                                        id="ae-enabled"
                                                        data-test="ae-enabled"
                                                        className="mt-0.5"
                                                        checked={aeEnabled}
                                                        disabled={!canEdit}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setAeEnabled(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <span className="font-medium">
                                                            15 % AE
                                                            berücksichtigen
                                                        </span>
                                                        <span className="text-muted-foreground mt-1 block text-xs">
                                                            AE wird nach allen
                                                            Positions- und
                                                            Auftragsrabatten nur
                                                            auf den AE-fähigen
                                                            Anteil angewendet.
                                                            {displayTotals?.ae_eligible_base
                                                                ? ` Berechnungsbasis ${money(displayTotals.ae_eligible_base)}.`
                                                                : ''}
                                                        </span>
                                                    </span>
                                                </label>
                                                {displayTotals &&
                                                !previewLoading ? (
                                                    <div
                                                        className="border-border/60 space-y-2 border-t pt-4 text-sm"
                                                        data-test="step-3-totals"
                                                    >
                                                        <p>
                                                            Ausgangssumme für
                                                            Auftragsrabatte{' '}
                                                            <span className="font-medium">
                                                                {money(
                                                                    displayTotals.after_position_discount_total ??
                                                                        displayTotals.media_gross,
                                                                )}
                                                            </span>
                                                        </p>
                                                        {aeEnabled ||
                                                        Number(
                                                            displayTotals.ae_total,
                                                        ) > 0 ? (
                                                            <p>
                                                                AE-Abzug{' '}
                                                                <span
                                                                    className="font-medium"
                                                                    data-test="ae-deduction"
                                                                >
                                                                    {money(
                                                                        displayTotals.ae_total,
                                                                    )}
                                                                </span>
                                                            </p>
                                                        ) : null}
                                                        <p className="font-medium">
                                                            Netto-Endsumme{' '}
                                                            <span
                                                                className="text-primary text-base"
                                                                data-test="preview-net-total"
                                                            >
                                                                {money(
                                                                    displayTotals.nn_invest,
                                                                )}
                                                            </span>
                                                        </p>
                                                    </div>
                                                ) : previewLoading ? (
                                                    <LoadingState
                                                        label="Berechnet"
                                                        data-test="preview-loading"
                                                    />
                                                ) : null}
                                            </div>
                                        </CardContent>
                                    </Card>
                                    {isBudgetSetup &&
                                    proposalStatus === 'stale' ? (
                                        <StatusBanner data-test="budget-stale-hint">
                                            Die Konditionen haben sich geändert.
                                            Optimiere den Vorschlag erneut, um
                                            das Zielbudget bestmöglich
                                            auszuschöpfen.
                                        </StatusBanner>
                                    ) : null}
                                </div>
                            )
                        ) : null}

                        {step === 3 ? (
                            isBudgetSetup ? (
                                <BudgetProposalResultStep
                                    proposal={proposal}
                                    proposalStatus={proposalStatus}
                                    proposalLoading={proposalLoading}
                                    busy={busy}
                                    onApply={takeProposal}
                                    onEditInputs={() => setStep(0)}
                                    onRecalculate={() => void createProposal()}
                                    showApplyHint={isBudgetSetup}
                                />
                            ) : (
                                <Card className={wizardCardClass}>
                                    <CardHeader
                                        className={`${wizardCardHeaderClass} flex flex-row items-center justify-between gap-3`}
                                    >
                                        <CardTitle
                                            className={wizardCardTitleClass}
                                        >
                                            Zusammenfassung
                                        </CardTitle>
                                        {calculation?.id &&
                                        canCreateDispoOrder ? (
                                            <DispoOrderCreateAction
                                                calculationId={calculation.id}
                                                revision={dispoOrderRevision}
                                            />
                                        ) : null}
                                    </CardHeader>
                                    <CardContent
                                        className={`${wizardCardContentClass} space-y-4 text-sm`}
                                    >
                                        {summary ? (
                                            <>
                                                <dl className="grid gap-2 sm:grid-cols-2">
                                                    <SummaryDetail
                                                        label="Media-Brutto"
                                                        value={money(
                                                            summary.media_gross,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="Rabatte Werbeelemente"
                                                        value={money(
                                                            summary.position_discount_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="Rabatte Auftrag"
                                                        value={money(
                                                            summary.order_discount_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="AE gesamt"
                                                        value={money(
                                                            summary.ae_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="N/N-Invest"
                                                        value={money(
                                                            summary.nn_invest,
                                                        )}
                                                        emphasis
                                                    />
                                                    {summary.target_budget_nn ? (
                                                        <SummaryDetail
                                                            label="Zielbudget"
                                                            value={money(
                                                                summary.target_budget_nn,
                                                            )}
                                                        />
                                                    ) : null}
                                                </dl>
                                                {summary.positions.map(
                                                    (position) => (
                                                        <section
                                                            key={
                                                                position.inventory_name
                                                            }
                                                            className="rounded-lg border p-4"
                                                        >
                                                            <p className="font-medium">
                                                                {
                                                                    position.inventory_name
                                                                }{' '}
                                                                ·{' '}
                                                                {
                                                                    position.spot_method
                                                                }{' '}
                                                                · Preisliste{' '}
                                                                {
                                                                    position.price_list_version
                                                                }
                                                            </p>
                                                            <p className="text-muted-foreground mt-1">
                                                                {
                                                                    position.total_spot_count
                                                                }{' '}
                                                                Spots à{' '}
                                                                {
                                                                    position.length_seconds
                                                                }
                                                                s · Pos.-Rab.{' '}
                                                                {
                                                                    position.position_discount_percent
                                                                }
                                                                % · AE{' '}
                                                                {
                                                                    position.ae_percent
                                                                }
                                                                %
                                                            </p>
                                                            <ul className="text-muted-foreground mt-2 list-inside list-disc">
                                                                {(position
                                                                    .time_ranges
                                                                    ?.length
                                                                    ? position.time_ranges
                                                                    : []
                                                                ).map(
                                                                    (range) => (
                                                                        <li
                                                                            key={`${range.start_hour}-${range.end_hour_exclusive}-${range.day_group}`}
                                                                        >
                                                                            {String(
                                                                                range.start_hour,
                                                                            ).padStart(
                                                                                2,
                                                                                '0',
                                                                            )}
                                                                            :00–
                                                                            {String(
                                                                                range.end_hour_exclusive -
                                                                                    1,
                                                                            ).padStart(
                                                                                2,
                                                                                '0',
                                                                            )}
                                                                            :59,{' '}
                                                                            {
                                                                                range.day_group
                                                                            }
                                                                            ,{' '}
                                                                            {
                                                                                range.spot_count
                                                                            }{' '}
                                                                            Spots
                                                                            {range.range_gross
                                                                                ? ` · ${money(range.range_gross)}`
                                                                                : ''}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                            <p className="mt-2">
                                                                {money(
                                                                    position.media_gross,
                                                                )}{' '}
                                                                Brutto ·{' '}
                                                                {money(
                                                                    position.nn_invest,
                                                                )}{' '}
                                                                N/N
                                                            </p>
                                                        </section>
                                                    ),
                                                )}
                                            </>
                                        ) : displayTotals ? (
                                            <>
                                                <dl className="grid gap-2 sm:grid-cols-2">
                                                    <SummaryDetail
                                                        label="Media-Brutto"
                                                        value={money(
                                                            displayTotals.media_gross,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="Rabatte Werbeelemente"
                                                        value={money(
                                                            displayTotals.position_discount_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="Rabatte Auftrag"
                                                        value={money(
                                                            displayTotals.order_discount_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="AE gesamt"
                                                        value={money(
                                                            displayTotals.ae_total,
                                                        )}
                                                    />
                                                    <SummaryDetail
                                                        label="N/N-Invest"
                                                        value={money(
                                                            displayTotals.nn_invest,
                                                        )}
                                                        emphasis
                                                    />
                                                    {displayTotals.target_budget_nn ? (
                                                        <SummaryDetail
                                                            label="Zielbudget"
                                                            value={money(
                                                                displayTotals.target_budget_nn,
                                                            )}
                                                        />
                                                    ) : null}
                                                </dl>
                                                {displayTotals.target_budget_nn &&
                                                displayTotals.budget_delta !==
                                                    null ? (
                                                    <p className="text-muted-foreground">
                                                        {Number(
                                                            displayTotals.budget_delta,
                                                        ) >= 0
                                                            ? `Rest ${money(displayTotals.budget_delta)}`
                                                            : `Überschreitung ${money(Math.abs(Number(displayTotals.budget_delta)))}`}
                                                    </p>
                                                ) : null}
                                                {displayTotals.requires_special_approval ? (
                                                    <StatusBanner tone="warning">
                                                        Die persönliche
                                                        Rabattgrenze ist
                                                        überschritten.
                                                    </StatusBanner>
                                                ) : null}
                                                {positions.map(
                                                    (position, index) => {
                                                        const result =
                                                            displayTotals
                                                                .positions[
                                                                index
                                                            ];
                                                        const inventory =
                                                            catalog.inventories.find(
                                                                (item) =>
                                                                    item.id ===
                                                                    position.inventory_id,
                                                            );

                                                        return (
                                                            <section
                                                                key={
                                                                    position.client_key
                                                                }
                                                                className="rounded-lg border p-4"
                                                            >
                                                                <p className="font-medium">
                                                                    {inventory?.name ??
                                                                        'Sender'}
                                                                </p>
                                                                <p className="text-muted-foreground mt-1">
                                                                    {
                                                                        position.total_spot_count
                                                                    }{' '}
                                                                    Spots à{' '}
                                                                    {
                                                                        position.length_seconds
                                                                    }
                                                                    s
                                                                </p>
                                                                <ul className="text-muted-foreground mt-2 list-inside list-disc">
                                                                    {(
                                                                        result?.time_ranges ??
                                                                        []
                                                                    ).map(
                                                                        (
                                                                            range,
                                                                        ) => (
                                                                            <li
                                                                                key={`${range.start_hour}-${range.end_hour_exclusive}-${range.day_group}`}
                                                                            >
                                                                                {formatHour(
                                                                                    range.start_hour,
                                                                                )}
                                                                                –
                                                                                {formatInclusiveEnd(
                                                                                    range.end_hour_exclusive,
                                                                                )}
                                                                                ,{' '}
                                                                                {
                                                                                    range.spot_count
                                                                                }{' '}
                                                                                Spots
                                                                                {range.range_gross
                                                                                    ? ` · ${money(range.range_gross)}`
                                                                                    : ''}
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                                {(
                                                                    result?.position_discounts ??
                                                                    []
                                                                ).map(
                                                                    (
                                                                        discount,
                                                                        discountIndex,
                                                                    ) => (
                                                                        <p
                                                                            key={`${discount.label}-${discountIndex}`}
                                                                            className="text-muted-foreground mt-1"
                                                                        >
                                                                            {
                                                                                discount.label
                                                                            }{' '}
                                                                            {formatPercent(
                                                                                discount.percent,
                                                                            )}{' '}
                                                                            ·{' '}
                                                                            {moneyDeduction(
                                                                                discount.amount,
                                                                            )}
                                                                        </p>
                                                                    ),
                                                                )}
                                                                {result?.media_gross ? (
                                                                    <p className="mt-2">
                                                                        {money(
                                                                            result.media_gross,
                                                                        )}{' '}
                                                                        Brutto ·{' '}
                                                                        {money(
                                                                            result.nn_invest,
                                                                        )}{' '}
                                                                        N/N
                                                                    </p>
                                                                ) : null}
                                                            </section>
                                                        );
                                                    },
                                                )}
                                            </>
                                        ) : (
                                            <LoadingState />
                                        )}
                                    </CardContent>
                                </Card>
                            )
                        ) : null}
                    </div>

                    <aside className="order-2 min-w-0 lg:sticky lg:top-6 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:self-start">
                        {showBudgetPlanningSidebar ? (
                            <BudgetPlanningSidebar
                                targetBudget={targetBudget}
                                budgetElements={budgetElements}
                                inventories={catalog.inventories}
                                dayGroups={dayGroups}
                            />
                        ) : (
                            <CalculationSummaryPanel
                                totals={summaryTotals}
                                loading={canEdit && previewLoading}
                                aeEnabled={aeEnabled}
                                positions={positions}
                                inventories={catalog.inventories}
                            />
                        )}
                    </aside>

                    <div className="border-border order-3 flex flex-wrap gap-3 border-t pt-6 lg:col-start-1 lg:row-start-2">
                        {step > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                Zurück
                            </Button>
                        ) : null}
                        {step < steps.length - 1 &&
                        !(isBudgetSetup && step === 2) ? (
                            <Button type="button" onClick={goNext}>
                                {nextLabel}
                            </Button>
                        ) : null}
                        {isBudgetSetup && step === 2 ? (
                            <Button
                                type="button"
                                data-test="budget-propose"
                                onClick={() => void createProposal()}
                                disabled={busy || proposalLoading}
                            >
                                Budgetvorschlag berechnen
                            </Button>
                        ) : null}
                        {canEdit && showSaveButton ? (
                            <Button
                                type="button"
                                onClick={save}
                                disabled={
                                    busy ||
                                    (planningMode === 'budget' &&
                                        positions.length === 0 &&
                                        !isBudgetSetup)
                                }
                                variant={
                                    step === steps.length - 1
                                        ? 'default'
                                        : 'secondary'
                                }
                                data-test="wizard-save"
                            >
                                Speichern
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}

function SummaryDetail({
    label,
    value,
    emphasis,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={
                    emphasis ? 'text-primary font-semibold' : 'font-medium'
                }
            >
                {value}
            </dd>
        </div>
    );
}

CalculationWizard.layout = {
    breadcrumbs: [
        { title: 'Kalkulationen', href: '/kalkulationen' },
        { title: 'Wizard', href: '/kalkulationen/neu' },
    ],
};
