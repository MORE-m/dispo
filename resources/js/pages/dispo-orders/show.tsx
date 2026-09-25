import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import type { HttpExceptionResponse } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DispoOrderApprovalActions } from '@/components/dispo-order-approval-actions';
import { DispoOrderApprovalHistory } from '@/components/dispo-order-approval-history';
import {
    DispoOrderCompletionSection,
    type CompletionReadiness,
    type CompletionSummary,
} from '@/components/dispo-order-completion-section';
import { DispoOrderCompletedReopenSection } from '@/components/dispo-order-completed-reopen-section';
import {
    DispoOrderCancellationSection,
    type CancellationSummary,
} from '@/components/dispo-order-cancellation-section';
import { DispoOrderCustomerConfirmationSection } from '@/components/dispo-order-customer-confirmation-section';
import {
    DispoOrderCommunicationHistory,
    type CommunicationEntry,
} from '@/components/dispo-order-communication-history';
import { DispoOrderInvoiceEndMonths } from '@/components/dispo-order-invoice-end-months';
import {
    DispoOrderOperationalStatusActions,
    type OperationalStatusTarget,
} from '@/components/dispo-order-operational-status-actions';
import {
    DispoOrderSalesInquiryActions,
    type OpenSalesInquiry,
} from '@/components/dispo-order-sales-inquiry-actions';
import {
    DispoOrderStatusHistory,
    type StatusHistoryEntry,
} from '@/components/dispo-order-status-history';
import { DispoOrderReviseAction } from '@/components/dispo-order-revise-action';
import {
    DispoOrderSpotDistributionExport,
    type SpotDistributionExportProps,
} from '@/components/dispo-order-spot-distribution-export';
import { DispoOrderStatusBadge } from '@/components/dispo-order-status-badge';
import { SchemaChoiceFields } from '@/components/dynamic-fields/schema-choice-fields';
import { SchemaChoiceReadonlyFields } from '@/components/dynamic-fields/schema-choice-readonly';
import {
    SchemaTextFields,
    type SchemaTextField,
} from '@/components/dynamic-fields/schema-text-fields';
import { SuccessState } from '@/components/feedback/states';
import {
    formatPercent,
    formTextareaClass,
    money,
} from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { SpecialApprovalReasonsList } from '@/components/special-approval-reasons-list';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    choiceFieldsFromCustomBucket,
    choiceValuesForPayload,
    initChoiceEntriesMap,
    textFieldsFromCustomBucket,
    textReadOnlyCapturedDisplay,
    type ChoiceFieldEntry,
    type ChoiceValue,
    type SchemaChoiceField,
} from '@/lib/choice-field-values';
import { formatDateOnly, formatDateTime } from '@/lib/date-time';
import {
    derivedCampaignPeriodSummary,
    type DerivedCampaignPeriodProp,
} from '@/lib/derived-campaign-period';
import type { SnapshotFieldRule } from '@/lib/dynamic-field-rules';
import {
    formatPlannerEntryLine,
    sortPlannerEntriesForDisplay,
} from '@/lib/dispo-planner-display';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import {
    applyEffectiveRequired,
    evaluateSnapshotFieldRuntime,
    filterByEffectiveVisible,
} from '@/lib/snapshot-field-runtime';
import type {
    ApprovalHistoryEntry,
    DispoOrderRevisionLink,
    SpecialApprovalReason,
} from '@/types/dispo-order';

type PeriodValue = { start: string | null; end: string | null } | null;

type SchemaField = {
    key: string;
    label: string;
    help_text: string | null;
    field_type: string;
    scope: string;
    sort: number;
    group_key?: string | null;
    is_system?: boolean;
    editable?: boolean;
    calc_origin?: boolean;
    action_target_readonly?: boolean;
    options_json?: unknown;
    visible?: boolean;
    max_length?: number | null;
    required?: boolean;
};

type PositionFieldSchemaBucket = {
    fields?: SchemaField[];
    rules?: Array<{
        sort?: number;
        condition: { op?: string; field_key?: string; value?: unknown };
        action: { op?: string; field_key?: string };
    }>;
    editable_custom_fields?: SchemaField[];
    calc_origin_custom_fields?: SchemaField[];
};

type FieldSchema = {
    fields: SchemaField[];
    rules: Array<{
        sort?: number;
        condition: { op?: string; field_key?: string; value?: unknown };
        action: { op?: string; field_key?: string };
    }>;
    editable_custom_header_fields?: SchemaField[];
    calc_origin_custom_header_fields?: SchemaField[];
    editable_custom_position_fields?: SchemaField[];
    calc_origin_custom_position_fields?: SchemaField[];
    position_field_schemas?: Record<number | string, PositionFieldSchemaBucket>;
    rules_integrity_error?: string | null;
};

type OrderPosition = {
    id: number;
    inventory_name: string;
    advertising_medium_name: string;
    spot_method: string;
    spot_method_label: string;
    length_seconds: number;
    component_calculation_strategy?: string | null;
    component_profile?: 'tandem' | 'tridem' | null;
    derived_component_airings?: number | null;
    components?: Array<{
        role: string;
        label: string;
        length_seconds: number;
        sort?: number;
        length_index?: number | null;
        media_gross?: string | null;
    }>;
    total_spot_count: number;
    price_list_version: string | null;
    media_gross: string;
    position_discount_amount: string;
    order_discount_amount: string;
    ae_amount: string;
    nn_invest: string;
    pricing_settlement_mode?: string | null;
    fixed_price_nn?: string | null;
    calculation_method_name?: string | null;
    effective_pay_factor_percent?: string | null;
    effective_total_discount_percent?: string | null;
    time_ranges: {
        start_hour: number;
        end_hour_exclusive: number;
        day_group?: string;
        spot_count: number;
        range_gross?: string | null;
    }[];
    planner_entries?: {
        date: string;
        hour: number;
        day_group?: string;
        spot_count: number;
        line_gross?: string | null;
    }[];
    position_discounts: {
        type?: string;
        custom_label?: string | null;
        percent?: string;
    }[];
    invoice_end_months?: number[] | null;
    invoice_end_month_labels?: string[];
    invoice_end_period_state?: 'open' | 'concrete' | 'invalid';
    dynamic_field_values?: Record<string, unknown> & {
        period_open?: boolean | null;
        position_flight_period?: PeriodValue;
    };
    dynamic_field_captured?: Record<string, boolean> & {
        period_open?: boolean;
        position_flight_period?: boolean;
    };
};

const COMPONENT_PROFILE_UI: Record<
    'tandem' | 'tridem',
    { label: string; unit_label: string }
> = {
    tandem: {
        label: 'Tandem / Reminder',
        unit_label: 'Tandem-Einheiten',
    },
    tridem: {
        label: 'Tridem',
        unit_label: 'Tridem-Einheiten',
    },
};

function componentDisplayLabel(
    position: OrderPosition,
    component: NonNullable<OrderPosition['components']>[number],
): string {
    if (
        position.component_profile === 'tridem' &&
        component.role === 'reminder'
    ) {
        if (component.sort === 2) {
            return 'Reminder 1';
        }
        if (component.sort === 3) {
            return 'Reminder 2';
        }
    }

    return component.label;
}

type OrderDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    lock_version: number;
    customer_confirmation_without_upload?: boolean;
    customer_confirmation_exception_reason?: string | null;
    customer_confirmation_exception_set_by_name?: string | null;
    customer_confirmation_exception_set_at?: string | null;
    source_calculation_number: string;
    calculation_id: number;
    customer_name: string | null;
    agency_name: string | null;
    campaign: string | null;
    product_title: string | null;
    briefing: string | null;
    advisor_name: string | null;
    order_discount_percent: string;
    ae_enabled: boolean;
    target_budget_nn: string | null;
    media_gross: string;
    position_discount_total: string;
    order_discount_total: string;
    ae_total: string;
    nn_invest: string;
    requires_special_approval: boolean;
    approval_kind: string;
    approval_kind_label: string;
    special_approval_reasons: SpecialApprovalReason[];
    order_discounts: {
        type?: string;
        custom_label?: string | null;
        percent?: string;
    }[];
    source_calculation_totals: {
        media_gross: string;
        position_discount_total: string;
        order_discount_total: string;
        ae_total: string;
        nn_invest: string;
    } | null;
    creator_name: string | null;
    created_at: string | null;
    rejection_reason: string | null;
    revises_dispo_order_id: number | null;
    revises: DispoOrderRevisionLink | null;
    revision: DispoOrderRevisionLink | null;
    approval_history: ApprovalHistoryEntry[];
    status_history?: StatusHistoryEntry[];
    communication?: CommunicationEntry[];
    current_approval: ApprovalHistoryEntry | null;
    dynamic_field_values?: Record<string, unknown> & {
        billing_special_features?: string | null;
        disposition_notes?: string | null;
        campaign_period?: PeriodValue;
    };
    dynamic_field_captured?: Record<string, boolean> & {
        billing_special_features?: boolean;
        disposition_notes?: boolean;
        campaign_period?: boolean;
    };
    missing_calc_origin_keys?: string[];
    historically_uncaptured?: boolean;
    derived_campaign_period?: DerivedCampaignPeriodProp;
    positions: OrderPosition[];
};

type ChoiceMeta = Pick<ChoiceFieldEntry, 'initPayloadSafe' | 'issue'>;

type PositionFieldView = {
    editableText: SchemaTextField[];
    editableChoice: SchemaChoiceField[];
    calcOriginText: SchemaTextField[];
    calcOriginChoice: SchemaChoiceField[];
};

const LOCK_CONFLICT_FALLBACK =
    'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.';

export default function DispoOrderShow({
    order,
    fieldSchema = { fields: [], rules: [] },
    canViewCalculation,
    canSubmit = false,
    canUpdate = false,
    canUpdateCustomerConfirmation = false,
    canSyncCalculationDynamicFields = false,
    canApprove = false,
    canReject = false,
    canRevise = false,
    canTransitionOperationalStatus = false,
    operationalStatusTargets = [],
    canAskSalesInquiry = false,
    canAnswerSalesInquiry = false,
    openSalesInquiry = null,
    canUpdateInvoiceEndMonths = false,
    canComplete = false,
    canForceComplete = false,
    canReopenCompleted = false,
    canCancel = false,
    completionReadiness = null,
    completionSummary = null,
    cancellationSummary = null,
    isCreator = false,
    spotDistributionExport = null,
}: {
    order: OrderDetail;
    fieldSchema?: FieldSchema;
    canViewCalculation: boolean;
    canSubmit?: boolean;
    canUpdate?: boolean;
    canUpdateCustomerConfirmation?: boolean;
    canSyncCalculationDynamicFields?: boolean;
    canApprove?: boolean;
    canReject?: boolean;
    canRevise?: boolean;
    canTransitionOperationalStatus?: boolean;
    operationalStatusTargets?: OperationalStatusTarget[];
    canAskSalesInquiry?: boolean;
    canAnswerSalesInquiry?: boolean;
    openSalesInquiry?: OpenSalesInquiry | null;
    canUpdateInvoiceEndMonths?: boolean;
    canComplete?: boolean;
    canForceComplete?: boolean;
    canReopenCompleted?: boolean;
    canCancel?: boolean;
    completionReadiness?: CompletionReadiness | null;
    completionSummary?: CompletionSummary | null;
    cancellationSummary?: CancellationSummary | null;
    isCreator?: boolean;
    spotDistributionExport?: SpotDistributionExportProps | null;
}) {
    const flash = usePage().props.flash;
    const current = order.current_approval;
    const requiresExceptionAcknowledgement = Boolean(
        current?.customer_confirmation_without_upload &&
        current.customer_confirmation_exception_reason,
    );
    const headerValues = order.dynamic_field_values ?? {};
    const headerCaptured = order.dynamic_field_captured ?? {};

    const [billingSpecialFeatures, setBillingSpecialFeatures] = useState(
        typeof headerValues.billing_special_features === 'string'
            ? headerValues.billing_special_features
            : '',
    );
    const [dispositionNotes, setDispositionNotes] = useState(
        typeof headerValues.disposition_notes === 'string'
            ? headerValues.disposition_notes
            : '',
    );

    const editableHeaderSource = useMemo((): SchemaField[] => {
        return (
            fieldSchema.editable_custom_header_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.editable === true &&
                    field.scope === 'header' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            )
        );
    }, [fieldSchema]);

    const editableCustomTextFields = useMemo(
        (): SchemaTextField[] =>
            textFieldsFromCustomBucket(editableHeaderSource),
        [editableHeaderSource],
    );
    const editableCustomChoiceFields = useMemo(
        (): SchemaChoiceField[] =>
            choiceFieldsFromCustomBucket(editableHeaderSource),
        [editableHeaderSource],
    );

    const calcOriginHeaderSource = useMemo((): SchemaField[] => {
        return (
            fieldSchema.calc_origin_custom_header_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.calc_origin === true &&
                    field.scope === 'header' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            )
        );
    }, [fieldSchema]);

    const calcOriginCustomTextFields = useMemo(
        (): SchemaTextField[] =>
            textFieldsFromCustomBucket(calcOriginHeaderSource),
        [calcOriginHeaderSource],
    );
    const calcOriginCustomChoiceFields = useMemo(
        (): SchemaChoiceField[] =>
            choiceFieldsFromCustomBucket(calcOriginHeaderSource),
        [calcOriginHeaderSource],
    );

    const positionFieldViews = useMemo((): Record<
        number,
        PositionFieldView
    > => {
        const map: Record<number, PositionFieldView> = {};
        for (const position of order.positions) {
            const bucket = positionFieldBucket(fieldSchema, position.id);
            map[position.id] = {
                editableText: textFieldsFromCustomBucket(
                    bucket.editable_custom_fields,
                ),
                editableChoice: choiceFieldsFromCustomBucket(
                    bucket.editable_custom_fields,
                ),
                calcOriginText: textFieldsFromCustomBucket(
                    bucket.calc_origin_custom_fields,
                ),
                calcOriginChoice: choiceFieldsFromCustomBucket(
                    bucket.calc_origin_custom_fields,
                ),
            };
        }
        return map;
    }, [fieldSchema, order.positions]);

    const anyPositionHasEditableCustoms = useMemo(
        () =>
            order.positions.some((position) => {
                const view = positionFieldViews[position.id];
                return (
                    (view?.editableText.length ?? 0) > 0 ||
                    (view?.editableChoice.length ?? 0) > 0
                );
            }),
        [order.positions, positionFieldViews],
    );

    const [customHeaderValues, setCustomHeaderValues] = useState<
        Record<string, string>
    >(() => {
        const initial: Record<string, string> = {};
        for (const field of editableCustomTextFields) {
            const raw = headerValues[field.key];
            initial[field.key] =
                typeof raw === 'string' || typeof raw === 'number'
                    ? String(raw)
                    : '';
        }
        return initial;
    });

    const [customHeaderChoiceValues, setCustomHeaderChoiceValues] = useState<
        Record<string, ChoiceValue>
    >(() => {
        const entries = initChoiceEntriesMap(
            editableCustomChoiceFields,
            headerValues,
        );
        const initial: Record<string, ChoiceValue> = {};
        for (const [key, entry] of Object.entries(entries)) {
            initial[key] = entry.value;
        }
        return initial;
    });
    const [customHeaderChoiceTouched, setCustomHeaderChoiceTouched] = useState<
        Record<string, true>
    >({});
    const [customHeaderChoiceMeta, setCustomHeaderChoiceMeta] = useState<
        Record<string, ChoiceMeta>
    >(() => {
        const entries = initChoiceEntriesMap(
            editableCustomChoiceFields,
            headerValues,
        );
        const meta: Record<string, ChoiceMeta> = {};
        for (const [key, entry] of Object.entries(entries)) {
            meta[key] = {
                initPayloadSafe: entry.initPayloadSafe,
                issue: entry.issue,
            };
        }
        return meta;
    });

    const [positionCustomValues, setPositionCustomValues] = useState<
        Record<number, Record<string, string>>
    >(() => {
        const initial: Record<number, Record<string, string>> = {};
        for (const position of order.positions) {
            const stored = position.dynamic_field_values ?? {};
            const textFields =
                positionFieldViews[position.id]?.editableText ??
                textFieldsFromCustomBucket(
                    positionFieldBucket(fieldSchema, position.id)
                        .editable_custom_fields,
                );
            const row: Record<string, string> = {};
            for (const field of textFields) {
                const raw = stored[field.key];
                row[field.key] =
                    typeof raw === 'string' || typeof raw === 'number'
                        ? String(raw)
                        : '';
            }
            initial[position.id] = row;
        }
        return initial;
    });

    const [positionChoiceValues, setPositionChoiceValues] = useState<
        Record<number, Record<string, ChoiceValue>>
    >(() => {
        const initial: Record<number, Record<string, ChoiceValue>> = {};
        for (const position of order.positions) {
            const choiceFields =
                positionFieldViews[position.id]?.editableChoice ??
                choiceFieldsFromCustomBucket(
                    positionFieldBucket(fieldSchema, position.id)
                        .editable_custom_fields,
                );
            const entries = initChoiceEntriesMap(
                choiceFields,
                position.dynamic_field_values ?? {},
            );
            const row: Record<string, ChoiceValue> = {};
            for (const [key, entry] of Object.entries(entries)) {
                row[key] = entry.value;
            }
            initial[position.id] = row;
        }
        return initial;
    });
    const [positionChoiceTouched, setPositionChoiceTouched] = useState<
        Record<number, Record<string, true>>
    >({});
    const [positionChoiceMeta, setPositionChoiceMeta] = useState<
        Record<number, Record<string, ChoiceMeta>>
    >(() => {
        const initial: Record<number, Record<string, ChoiceMeta>> = {};
        for (const position of order.positions) {
            const choiceFields =
                positionFieldViews[position.id]?.editableChoice ??
                choiceFieldsFromCustomBucket(
                    positionFieldBucket(fieldSchema, position.id)
                        .editable_custom_fields,
                );
            const entries = initChoiceEntriesMap(
                choiceFields,
                position.dynamic_field_values ?? {},
            );
            const meta: Record<string, ChoiceMeta> = {};
            for (const [key, entry] of Object.entries(entries)) {
                meta[key] = {
                    initPayloadSafe: entry.initPayloadSafe,
                    issue: entry.issue,
                };
            }
            initial[position.id] = meta;
        }
        return initial;
    });

    const headerValuesForRules = useMemo(() => {
        const values: Record<string, unknown> = {
            ...order.dynamic_field_values,
            billing_special_features:
                billingSpecialFeatures.trim() === ''
                    ? null
                    : billingSpecialFeatures,
            disposition_notes:
                dispositionNotes.trim() === '' ? null : dispositionNotes,
        };
        for (const [key, value] of Object.entries(customHeaderValues)) {
            values[key] = value.trim() === '' ? null : value;
        }
        for (const [key, value] of Object.entries(customHeaderChoiceValues)) {
            values[key] = value;
        }

        return values;
    }, [
        order.dynamic_field_values,
        billingSpecialFeatures,
        dispositionNotes,
        customHeaderValues,
        customHeaderChoiceValues,
    ]);

    const headerRuntime = useMemo(
        () =>
            evaluateSnapshotFieldRuntime({
                fields: fieldSchema.fields.filter(
                    (field) => field.scope === 'header',
                ),
                // Basis-Snapshot-Felder inkl. Position: Integrity für Header→Position-Regeln.
                definitionFields: fieldSchema.fields,
                rules: fieldSchema.rules as SnapshotFieldRule[],
                scope: 'header',
                headerValues: headerValuesForRules,
                serverIntegrityError: fieldSchema.rules_integrity_error ?? null,
            }),
        [
            fieldSchema.fields,
            fieldSchema.rules,
            fieldSchema.rules_integrity_error,
            headerValuesForRules,
        ],
    );

    const visibleEditableHeaderTextFields = useMemo(
        () =>
            applyEffectiveRequired(
                filterByEffectiveVisible(
                    editableCustomTextFields,
                    headerRuntime,
                ),
                headerRuntime,
            ),
        [editableCustomTextFields, headerRuntime],
    );
    const visibleEditableHeaderChoiceFields = useMemo(
        () =>
            applyEffectiveRequired(
                filterByEffectiveVisible(
                    editableCustomChoiceFields,
                    headerRuntime,
                ),
                headerRuntime,
            ),
        [editableCustomChoiceFields, headerRuntime],
    );
    const visibleCalcOriginHeaderTextFields = useMemo(
        () =>
            filterByEffectiveVisible(calcOriginCustomTextFields, headerRuntime),
        [calcOriginCustomTextFields, headerRuntime],
    );
    const visibleCalcOriginHeaderChoiceFields = useMemo(
        () =>
            filterByEffectiveVisible(
                calcOriginCustomChoiceFields,
                headerRuntime,
            ),
        [calcOriginCustomChoiceFields, headerRuntime],
    );

    const positionRuntimes = useMemo(() => {
        const map: Record<
            number,
            ReturnType<typeof evaluateSnapshotFieldRuntime>
        > = {};
        for (const position of order.positions) {
            const bucket = positionFieldBucket(fieldSchema, position.id);
            const stored = position.dynamic_field_values ?? {};
            const draftText = positionCustomValues[position.id] ?? {};
            const draftChoice = positionChoiceValues[position.id] ?? {};
            const positionValues: Record<string, unknown> = { ...stored };
            for (const [key, value] of Object.entries(draftText)) {
                positionValues[key] = value.trim() === '' ? null : value;
            }
            for (const [key, value] of Object.entries(draftChoice)) {
                positionValues[key] = value;
            }
            map[position.id] = evaluateSnapshotFieldRuntime({
                fields:
                    bucket.fields.length > 0
                        ? bucket.fields
                        : [
                              ...bucket.editable_custom_fields,
                              ...bucket.calc_origin_custom_fields,
                          ],
                rules: (bucket.rules.length > 0
                    ? bucket.rules
                    : fieldSchema.rules) as SnapshotFieldRule[],
                scope: 'position',
                headerValues: headerValuesForRules,
                positionValues,
                conditionFields: fieldSchema.fields.filter(
                    (field) => field.scope === 'header',
                ),
                additionalRules: fieldSchema.rules as SnapshotFieldRule[],
                serverIntegrityError: fieldSchema.rules_integrity_error ?? null,
            });
        }

        return map;
    }, [
        order.positions,
        fieldSchema,
        positionCustomValues,
        positionChoiceValues,
        headerValuesForRules,
    ]);

    const visiblePositionFieldViews = useMemo(() => {
        const map: Record<number, PositionFieldView> = {};
        for (const position of order.positions) {
            const view = positionFieldViews[position.id] ?? {
                editableText: [],
                editableChoice: [],
                calcOriginText: [],
                calcOriginChoice: [],
            };
            const runtime = positionRuntimes[position.id] ?? headerRuntime;
            map[position.id] = {
                editableText: applyEffectiveRequired(
                    filterByEffectiveVisible(view.editableText, runtime),
                    runtime,
                ),
                editableChoice: applyEffectiveRequired(
                    filterByEffectiveVisible(view.editableChoice, runtime),
                    runtime,
                ),
                calcOriginText: filterByEffectiveVisible(
                    view.calcOriginText,
                    runtime,
                ),
                calcOriginChoice: filterByEffectiveVisible(
                    view.calcOriginChoice,
                    runtime,
                ),
            };
        }

        return map;
    }, [order.positions, positionFieldViews, positionRuntimes, headerRuntime]);

    const rulesIntegrityError = useMemo(() => {
        if (headerRuntime.integrityError) {
            return headerRuntime.integrityError;
        }
        for (const position of order.positions) {
            const error = positionRuntimes[position.id]?.integrityError;
            if (error) {
                return error;
            }
        }

        return null;
    }, [headerRuntime, positionRuntimes, order.positions]);

    const dynamicControlsLocked = rulesIntegrityError !== null;
    const billingVisible =
        !dynamicControlsLocked &&
        headerRuntime.isFieldVisible('billing_special_features');
    const dispositionVisible =
        !dynamicControlsLocked &&
        headerRuntime.isFieldVisible('disposition_notes');
    const billingRequired = headerRuntime.isFieldRequired(
        'billing_special_features',
    );
    const dispositionRequired =
        headerRuntime.isFieldRequired('disposition_notes');

    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [savingNotes, setSavingNotes] = useState(false);
    const [savingPositionCustoms, setSavingPositionCustoms] = useState(false);
    const [syncingCalcFields, setSyncingCalcFields] = useState(false);

    const pageDescription =
        order.status === 'draft' && canUpdate
            ? 'Entwurf – Rechnungs- und Dispohinweise bearbeitbar'
            : 'Dispoauftrag (Snapshot, schreibgeschützt)';

    const campaignPeriodLabel = 'Kampagnenzeitraum aus Kalkulation';
    const campaignPeriodHelp =
        fieldHelp(fieldSchema, 'campaign_period') ??
        'Aus der Kalkulation übernommener Zeitraum (unverändert).';
    const billingLabel = fieldLabel(
        fieldSchema,
        'billing_special_features',
        'Besonderheiten zur Rechnungsstellung',
    );
    const billingHelp = fieldHelp(fieldSchema, 'billing_special_features');
    const dispositionLabel = fieldLabel(
        fieldSchema,
        'disposition_notes',
        'Wichtige Informationen an die Disposition',
    );
    const dispositionHelp = fieldHelp(fieldSchema, 'disposition_notes');
    const periodOpenLabel = fieldLabel(
        fieldSchema,
        'period_open',
        'Zeitraum offen',
    );
    const periodOpenHelp = fieldHelp(fieldSchema, 'period_open');
    const flightPeriodLabel = fieldLabel(
        fieldSchema,
        'position_flight_period',
        'Flugzeitraum',
    );
    const flightPeriodHelp = fieldHelp(fieldSchema, 'position_flight_period');

    const campaignPeriodDisplay = displayCalcOriginPeriod(
        headerValues.campaign_period,
        headerCaptured.campaign_period === true,
    );

    const derivedPeriod = order.derived_campaign_period;
    const derivedSummary = derivedPeriod
        ? derivedCampaignPeriodSummary(derivedPeriod, formatDateOnly)
        : null;

    function applyLockConflict(response: HttpExceptionResponse): boolean {
        if (response.status !== 409) {
            return false;
        }

        setFieldErrors({
            lock_version:
                conflictMessage(response.data) ?? LOCK_CONFLICT_FALLBACK,
        });

        return true;
    }

    function saveSystemNotes() {
        if (savingNotes) {
            return;
        }

        if (rulesIntegrityError) {
            setFieldErrors({
                dynamic_field_values: rulesIntegrityError,
            });
            return;
        }

        setSavingNotes(true);
        setFieldErrors({});
        router.patch(
            `/dispoauftraege/${order.id}`,
            {
                lock_version: order.lock_version,
                dynamic_field_values: {
                    billing_special_features: billingSpecialFeatures,
                    disposition_notes: dispositionNotes,
                },
            },
            {
                preserveScroll: true,
                onHttpException: (response: HttpExceptionResponse) => {
                    if (!applyLockConflict(response)) {
                        return;
                    }
                    setSavingNotes(false);

                    return false;
                },
                onError: (errors) => {
                    setFieldErrors(errors);
                },
                onFinish: () => {
                    setSavingNotes(false);
                },
            },
        );
    }

    function saveCustomHeaders() {
        if (savingNotes || savingPositionCustoms) {
            return;
        }

        if (rulesIntegrityError) {
            setFieldErrors({
                dynamic_field_values: rulesIntegrityError,
            });
            return;
        }

        const choiceResult = choiceValuesForPayload(
            editableCustomChoiceFields,
            customHeaderChoiceValues,
            new Set(Object.keys(customHeaderChoiceTouched)),
            customHeaderChoiceMeta,
        );
        if (choiceResult.blockReason) {
            setFieldErrors({
                dynamic_field_values: choiceResult.blockReason,
            });
            return;
        }

        const dynamicFieldValues: Record<string, string | null | string[]> = {
            ...Object.fromEntries(
                editableCustomTextFields.map((field) => [
                    field.key,
                    customHeaderValues[field.key] ?? '',
                ]),
            ),
            ...choiceResult.payload,
        };

        if (Object.keys(dynamicFieldValues).length === 0) {
            return;
        }

        setSavingNotes(true);
        setFieldErrors({});
        router.patch(
            `/dispoauftraege/${order.id}`,
            {
                lock_version: order.lock_version,
                dynamic_field_values: dynamicFieldValues,
            },
            {
                preserveScroll: true,
                onHttpException: (response: HttpExceptionResponse) => {
                    if (!applyLockConflict(response)) {
                        return;
                    }
                    setSavingNotes(false);

                    return false;
                },
                onError: (errors) => {
                    setFieldErrors(errors);
                },
                onFinish: () => {
                    setSavingNotes(false);
                },
            },
        );
    }

    function savePositionCustoms() {
        if (savingNotes || savingPositionCustoms) {
            return;
        }

        if (rulesIntegrityError) {
            setFieldErrors({
                position_dynamic_field_values: rulesIntegrityError,
            });
            return;
        }

        const payload: Record<
            string,
            Record<string, string | null | string[]>
        > = {};

        for (const position of order.positions) {
            const view = positionFieldViews[position.id];
            const textFields = view?.editableText ?? [];
            const choiceFields = view?.editableChoice ?? [];

            if (textFields.length === 0 && choiceFields.length === 0) {
                continue;
            }

            const choiceResult = choiceValuesForPayload(
                choiceFields,
                positionChoiceValues[position.id] ?? {},
                new Set(Object.keys(positionChoiceTouched[position.id] ?? {})),
                positionChoiceMeta[position.id] ?? {},
            );
            if (choiceResult.blockReason) {
                setFieldErrors({
                    [`position_dynamic_field_values.${position.id}`]:
                        choiceResult.blockReason,
                });
                return;
            }

            const row: Record<string, string | null | string[]> = {};
            for (const field of textFields) {
                row[field.key] =
                    positionCustomValues[position.id]?.[field.key] ?? '';
            }
            Object.assign(row, choiceResult.payload);

            if (Object.keys(row).length === 0) {
                continue;
            }

            payload[String(position.id)] = row;
        }

        if (Object.keys(payload).length === 0) {
            return;
        }

        setSavingPositionCustoms(true);
        setFieldErrors({});
        router.patch(
            `/dispoauftraege/${order.id}/positions-angaben`,
            {
                lock_version: order.lock_version,
                position_dynamic_field_values: payload,
            },
            {
                preserveScroll: true,
                onHttpException: (response: HttpExceptionResponse) => {
                    if (!applyLockConflict(response)) {
                        return;
                    }
                    setSavingPositionCustoms(false);

                    return false;
                },
                onError: (errors) => {
                    setFieldErrors(errors);
                },
                onFinish: () => {
                    setSavingPositionCustoms(false);
                },
            },
        );
    }

    function syncCalculationDynamicFields() {
        if (syncingCalcFields) {
            return;
        }

        if (rulesIntegrityError) {
            setFieldErrors({
                dynamic_field_values: rulesIntegrityError,
            });
            return;
        }

        setSyncingCalcFields(true);
        router.post(
            `/dispoauftraege/${order.id}/sync-calculation-dynamic-fields`,
            { lock_version: order.lock_version },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSyncingCalcFields(false);
                },
            },
        );
    }

    const showHeaderCustomCard =
        dynamicControlsLocked ||
        visibleEditableHeaderTextFields.length > 0 ||
        visibleEditableHeaderChoiceFields.length > 0;
    const showHeaderCalcOrigin =
        visibleCalcOriginHeaderTextFields.length > 0 ||
        visibleCalcOriginHeaderChoiceFields.length > 0;

    return (
        <>
            <Head title={`Dispoauftrag ${order.number}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={order.number}
                    description={pageDescription}
                    actions={
                        <DispoOrderStatusBadge
                            status={order.status}
                            label={order.status_label}
                        />
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {rulesIntegrityError ? (
                    <p
                        className="text-destructive text-sm"
                        data-test="dispo-order-rules-integrity"
                        role="alert"
                    >
                        {rulesIntegrityError}
                    </p>
                ) : null}

                <DispoOrderApprovalActions
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    canSubmit={canSubmit}
                    canApprove={canApprove}
                    canReject={canReject}
                    isCreator={isCreator}
                    status={order.status}
                    requiresExceptionAcknowledgement={
                        requiresExceptionAcknowledgement
                    }
                />

                <DispoOrderCustomerConfirmationSection
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    status={order.status}
                    canEdit={canUpdateCustomerConfirmation}
                    withoutUpload={Boolean(
                        order.customer_confirmation_without_upload,
                    )}
                    exceptionReason={
                        order.customer_confirmation_exception_reason ?? null
                    }
                    setByName={
                        order.customer_confirmation_exception_set_by_name ??
                        null
                    }
                    setAt={order.customer_confirmation_exception_set_at ?? null}
                />

                <DispoOrderOperationalStatusActions
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    status={order.status}
                    canTransition={canTransitionOperationalStatus}
                    targets={operationalStatusTargets}
                />

                <DispoOrderCompletionSection
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    status={order.status}
                    canComplete={canComplete}
                    canForceComplete={canForceComplete}
                    readiness={completionReadiness}
                    summary={completionSummary}
                />

                <DispoOrderCompletedReopenSection
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    canReopenCompleted={canReopenCompleted}
                />

                <DispoOrderCancellationSection
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    status={order.status}
                    canCancel={canCancel}
                    summary={cancellationSummary}
                />

                <DispoOrderSalesInquiryActions
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    canAsk={canAskSalesInquiry}
                    canAnswer={canAnswerSalesInquiry}
                    openInquiry={openSalesInquiry}
                />

                <DispoOrderSpotDistributionExport
                    exportConfig={spotDistributionExport}
                />

                {canRevise ? (
                    <DispoOrderReviseAction orderId={order.id} />
                ) : null}

                {order.revision || order.revises ? (
                    <Card
                        className="border-border/70 rounded-xl shadow-xs"
                        data-test="dispo-order-revision-links"
                    >
                        <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                            <CardTitle className="text-sm font-semibold">
                                Versionen
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 px-5 py-4 text-sm">
                            {order.revises ? (
                                <p data-test="dispo-order-revises-link">
                                    Nachbesserung von{' '}
                                    <Link
                                        href={`/dispoauftraege/${order.revises.id}`}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {order.revises.number}
                                    </Link>{' '}
                                    ({order.revises.status_label})
                                </p>
                            ) : null}
                            {order.revision ? (
                                <p data-test="dispo-order-revision-link">
                                    Korrigierte Version:{' '}
                                    <Link
                                        href={`/dispoauftraege/${order.revision.id}`}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {order.revision.number}
                                    </Link>{' '}
                                    ({order.revision.status_label})
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}

                {order.status === 'awaiting_sales_approval' && current ? (
                    <Card
                        className="border-border/70 rounded-xl shadow-xs"
                        data-test="dispo-order-approval-panel"
                    >
                        <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                            <CardTitle className="text-sm font-semibold">
                                Freigabeanforderung
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 px-5 py-4 text-sm">
                            <p>
                                <span className="font-medium">
                                    {current.kind_label}
                                </span>
                                {' · '}
                                Status {current.status_label}
                            </p>
                            <p className="text-muted-foreground">
                                Eingereicht von {current.submitted_by_name} am{' '}
                                {formatDateTime(current.submitted_at)}
                            </p>
                            <p className="text-muted-foreground">
                                Vier-Augen-Prinzip: Der Ersteller darf nicht
                                selbst entscheiden.
                            </p>
                            <SpecialApprovalReasonsList
                                reasons={current.special_approval_reasons}
                            />
                        </CardContent>
                    </Card>
                ) : null}

                {order.status === 'approval_rejected' &&
                current?.rejection_reason ? (
                    <Card
                        className="border-border/70 rounded-xl shadow-xs"
                        data-test="dispo-order-rejection-panel"
                    >
                        <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                            <CardTitle className="text-sm font-semibold">
                                Ablehnung
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-5 py-4 text-sm">
                            <p data-test="dispo-order-rejection-reason">
                                {current.rejection_reason}
                            </p>
                        </CardContent>
                    </Card>
                ) : null}

                <Card className="border-border/70 rounded-xl shadow-xs">
                    <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                        <CardTitle className="text-sm font-semibold">
                            Kopfdaten
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-2">
                        <Detail label="Kunde" value={order.customer_name} />
                        <Detail label="Agentur" value={order.agency_name} />
                        <Detail label="Kampagne" value={order.campaign} />
                        <Detail
                            label="Produkt / Titel"
                            value={order.product_title}
                        />
                        <Detail
                            label="Quellkalkulation"
                            value={
                                canViewCalculation ? (
                                    <Link
                                        href={`/kalkulationen/${order.calculation_id}`}
                                        className="text-primary underline-offset-4 hover:underline"
                                        data-test="dispo-order-source-calculation-link"
                                    >
                                        {order.source_calculation_number}
                                    </Link>
                                ) : (
                                    order.source_calculation_number
                                )
                            }
                        />
                        <Detail
                            label="Mediaberater"
                            value={order.advisor_name}
                        />
                        <Detail label="Ersteller" value={order.creator_name} />
                        <Detail
                            label="Erstellt am"
                            value={formatDateTime(order.created_at)}
                        />
                        <Detail
                            label="Freigabeart"
                            value={order.approval_kind_label}
                        />
                        <div data-test="dispo-order-campaign-period">
                            <Detail
                                label={campaignPeriodLabel}
                                value={campaignPeriodDisplay}
                                helpText={campaignPeriodHelp}
                            />
                            <p className="text-muted-foreground mt-1 text-xs">
                                Aus Kalkulation übernommen
                            </p>
                        </div>
                        {derivedPeriod && derivedSummary ? (
                            <div
                                className="space-y-2 sm:col-span-2"
                                data-test="dispo-order-derived-campaign-period"
                            >
                                <Detail
                                    label="Abgeleiteter Kampagnenzeitraum (Dispo)"
                                    value={
                                        derivedSummary.rangeText ??
                                        (derivedPeriod.status === 'open' ||
                                        derivedPeriod.status === 'legacy'
                                            ? '–'
                                            : '–')
                                    }
                                    helpText="Automatisch aus den eingefrorenen Positionen dieses Auftrags berechnet. Read-only."
                                />
                                <p
                                    className="text-sm"
                                    data-test="dispo-order-derived-campaign-period-status"
                                >
                                    Status: {derivedSummary.statusLabel}
                                </p>
                                {derivedSummary.explanation ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test="dispo-order-derived-campaign-period-explanation"
                                    >
                                        {derivedSummary.explanation}
                                    </p>
                                ) : null}
                                {derivedPeriod.status === 'partial' &&
                                derivedPeriod.contributing_count != null &&
                                derivedPeriod.unresolved_count != null ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test="dispo-order-derived-campaign-period-counts"
                                    >
                                        Beitragend:{' '}
                                        {derivedPeriod.contributing_count} ·
                                        Ohne konkreten Zeitraum:{' '}
                                        {derivedPeriod.unresolved_count}
                                    </p>
                                ) : null}
                                {derivedSummary.showUnresolved &&
                                derivedPeriod.unresolved_positions.length >
                                    0 ? (
                                    <ul
                                        className="text-muted-foreground list-inside list-disc text-xs"
                                        data-test="dispo-order-derived-campaign-period-unresolved"
                                    >
                                        {derivedPeriod.unresolved_positions.map(
                                            (item) => (
                                                <li
                                                    key={
                                                        item.dispo_order_position_id
                                                    }
                                                >
                                                    {item.label}
                                                    {item.unresolved_reason_label
                                                        ? ` – ${item.unresolved_reason_label}`
                                                        : ''}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                ) : null}
                                {derivedPeriod.conflict_with_calculation ? (
                                    <p
                                        className="text-xs text-amber-800 dark:text-amber-200"
                                        data-test="dispo-order-derived-campaign-period-conflict"
                                    >
                                        Hinweis: Der Zeitraum aus der
                                        Kalkulation weicht vom abgeleiteten
                                        Dispo-Zeitraum ab. Beide Werte bleiben
                                        getrennt sichtbar; die
                                        Auftragserstellung wird dadurch nicht
                                        blockiert.
                                    </p>
                                ) : null}
                                {!derivedPeriod.conflict_with_calculation &&
                                derivedPeriod.calculation_period_complete &&
                                !derivedPeriod.derived_period_complete ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test="dispo-order-derived-campaign-period-only-calc"
                                    >
                                        Es liegt nur ein Kalkulationszeitraum
                                        vor; aus den Positionen konnte kein
                                        vollständiger Dispo-Zeitraum abgeleitet
                                        werden.
                                    </p>
                                ) : null}
                                {!derivedPeriod.conflict_with_calculation &&
                                !derivedPeriod.calculation_period_complete &&
                                derivedPeriod.derived_period_complete ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test="dispo-order-derived-campaign-period-only-derived"
                                    >
                                        Es liegt nur ein abgeleiteter
                                        Dispo-Zeitraum vor; in der Kalkulation
                                        war kein vollständiger Kampagnenzeitraum
                                        erfasst.
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        {showHeaderCalcOrigin ? (
                            <div
                                className="space-y-3 border-t pt-3 sm:col-span-2"
                                data-test="dispo-order-calc-origin-custom-fields"
                            >
                                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                    Aus Kalkulation übernommen
                                </p>
                                {visibleCalcOriginHeaderTextFields.length >
                                0 ? (
                                    <SchemaTextFields
                                        fields={
                                            visibleCalcOriginHeaderTextFields
                                        }
                                        values={Object.fromEntries(
                                            visibleCalcOriginHeaderTextFields.map(
                                                (field) => [
                                                    field.key,
                                                    textReadOnlyCapturedDisplay(
                                                        headerValues[field.key],
                                                        headerCaptured[
                                                            field.key
                                                        ] === true,
                                                    ),
                                                ],
                                            ),
                                        )}
                                        readOnly
                                        idPrefix="dispo-calc-origin"
                                        onChange={() => undefined}
                                    />
                                ) : null}
                                {visibleCalcOriginHeaderChoiceFields.length >
                                0 ? (
                                    <SchemaChoiceReadonlyFields
                                        fields={
                                            visibleCalcOriginHeaderChoiceFields
                                        }
                                        values={headerValues}
                                        captured={headerCaptured}
                                        idPrefix="dispo-calc-origin-choice"
                                    />
                                ) : null}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                {canSyncCalculationDynamicFields ? (
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            data-test="dispo-order-sync-calc-dynamic-fields"
                            disabled={
                                syncingCalcFields || dynamicControlsLocked
                            }
                            onClick={syncCalculationDynamicFields}
                        >
                            {syncingCalcFields
                                ? 'Wird übernommen…'
                                : 'Dynamische Zeitraumfelder aus Kalkulation übernehmen'}
                        </Button>
                    </div>
                ) : null}

                <Card
                    className="border-border/70 rounded-xl shadow-xs"
                    data-test="dispo-order-notes-card"
                >
                    <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                        <CardTitle className="text-sm font-semibold">
                            Rechnungs- und Dispohinweise
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 px-5 py-4">
                        {canUpdate ? (
                            <>
                                {billingVisible ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="dispo-order-billing-special-features">
                                            {billingRequired
                                                ? `${billingLabel} *`
                                                : billingLabel}
                                        </Label>
                                        {billingHelp ? (
                                            <p
                                                id="dispo-order-billing-special-features-help"
                                                className="text-muted-foreground text-xs"
                                            >
                                                {billingHelp}
                                            </p>
                                        ) : null}
                                        <textarea
                                            id="dispo-order-billing-special-features"
                                            className={formTextareaClass}
                                            value={billingSpecialFeatures}
                                            data-test="dispo-order-billing-special-features"
                                            disabled={
                                                savingNotes ||
                                                dynamicControlsLocked
                                            }
                                            aria-describedby={
                                                billingHelp
                                                    ? 'dispo-order-billing-special-features-help'
                                                    : undefined
                                            }
                                            onChange={(event) =>
                                                setBillingSpecialFeatures(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {fieldErrors[
                                            'dynamic_field_values.billing_special_features'
                                        ] ? (
                                            <p className="text-destructive text-xs">
                                                {
                                                    fieldErrors[
                                                        'dynamic_field_values.billing_special_features'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                                {dispositionVisible ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="dispo-order-disposition-notes">
                                            {dispositionRequired
                                                ? `${dispositionLabel} *`
                                                : dispositionLabel}
                                        </Label>
                                        {dispositionHelp ? (
                                            <p
                                                id="dispo-order-disposition-notes-help"
                                                className="text-muted-foreground text-xs"
                                            >
                                                {dispositionHelp}
                                            </p>
                                        ) : null}
                                        <textarea
                                            id="dispo-order-disposition-notes"
                                            className={formTextareaClass}
                                            value={dispositionNotes}
                                            data-test="dispo-order-disposition-notes"
                                            disabled={
                                                savingNotes ||
                                                dynamicControlsLocked
                                            }
                                            aria-describedby={
                                                dispositionHelp
                                                    ? 'dispo-order-disposition-notes-help'
                                                    : undefined
                                            }
                                            onChange={(event) =>
                                                setDispositionNotes(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {fieldErrors[
                                            'dynamic_field_values.disposition_notes'
                                        ] ? (
                                            <p className="text-destructive text-xs">
                                                {
                                                    fieldErrors[
                                                        'dynamic_field_values.disposition_notes'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                                {fieldErrors.dynamic_field_values ||
                                fieldErrors.lock_version ? (
                                    <p className="text-destructive text-xs">
                                        {fieldErrors.dynamic_field_values ??
                                            fieldErrors.lock_version}
                                    </p>
                                ) : null}
                                <Button
                                    type="button"
                                    disabled={
                                        savingNotes || dynamicControlsLocked
                                    }
                                    data-test="dispo-order-save-system-notes"
                                    onClick={saveSystemNotes}
                                >
                                    {savingNotes
                                        ? 'Wird gespeichert…'
                                        : 'Speichern'}
                                </Button>
                            </>
                        ) : (
                            <>
                                {headerRuntime.isFieldVisible(
                                    'billing_special_features',
                                ) ? (
                                    <div data-test="dispo-order-billing-special-features">
                                        <Detail
                                            label={billingLabel}
                                            value={displayOptionalText(
                                                headerValues.billing_special_features,
                                            )}
                                            helpText={billingHelp}
                                        />
                                    </div>
                                ) : null}
                                {headerRuntime.isFieldVisible(
                                    'disposition_notes',
                                ) ? (
                                    <div data-test="dispo-order-disposition-notes">
                                        <Detail
                                            label={dispositionLabel}
                                            value={displayOptionalText(
                                                headerValues.disposition_notes,
                                            )}
                                            helpText={dispositionHelp}
                                        />
                                    </div>
                                ) : null}
                            </>
                        )}
                    </CardContent>
                </Card>

                {showHeaderCustomCard ? (
                    <Card
                        className="border-border/70 rounded-xl shadow-xs"
                        data-test="dispo-order-custom-header-card"
                    >
                        <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                            <CardTitle className="text-sm font-semibold">
                                Weitere Angaben
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 px-5 py-4">
                            {canUpdate ? (
                                <>
                                    {visibleEditableHeaderTextFields.length >
                                    0 ? (
                                        <SchemaTextFields
                                            fields={
                                                visibleEditableHeaderTextFields
                                            }
                                            values={customHeaderValues}
                                            errors={fieldErrors}
                                            disabled={
                                                savingNotes ||
                                                dynamicControlsLocked
                                            }
                                            idPrefix="dispo-custom"
                                            onChange={(key, value) =>
                                                setCustomHeaderValues(
                                                    (current) => ({
                                                        ...current,
                                                        [key]: value,
                                                    }),
                                                )
                                            }
                                        />
                                    ) : null}
                                    {visibleEditableHeaderChoiceFields.length >
                                    0 ? (
                                        <div data-test="dispo-order-custom-header-choice-fields">
                                            <SchemaChoiceFields
                                                fields={
                                                    visibleEditableHeaderChoiceFields
                                                }
                                                values={
                                                    customHeaderChoiceValues
                                                }
                                                errors={fieldErrors}
                                                disabled={
                                                    savingNotes ||
                                                    dynamicControlsLocked
                                                }
                                                idPrefix="dispo-custom-choice"
                                                onChange={(key, value) => {
                                                    setCustomHeaderChoiceValues(
                                                        (current) => ({
                                                            ...current,
                                                            [key]: value,
                                                        }),
                                                    );
                                                    setCustomHeaderChoiceTouched(
                                                        (current) => ({
                                                            ...current,
                                                            [key]: true,
                                                        }),
                                                    );
                                                    setCustomHeaderChoiceMeta(
                                                        (current) => ({
                                                            ...current,
                                                            [key]: {
                                                                initPayloadSafe: true,
                                                                issue: null,
                                                            },
                                                        }),
                                                    );
                                                }}
                                            />
                                        </div>
                                    ) : null}
                                    {fieldErrors.dynamic_field_values ||
                                    fieldErrors.lock_version ? (
                                        <p className="text-destructive text-xs">
                                            {fieldErrors.dynamic_field_values ??
                                                fieldErrors.lock_version}
                                        </p>
                                    ) : null}
                                    <Button
                                        type="button"
                                        disabled={
                                            savingNotes || dynamicControlsLocked
                                        }
                                        data-test="dispo-order-save-custom-headers"
                                        onClick={saveCustomHeaders}
                                    >
                                        {savingNotes
                                            ? 'Wird gespeichert…'
                                            : 'Speichern'}
                                    </Button>
                                </>
                            ) : (
                                <>
                                    {visibleEditableHeaderTextFields.length >
                                    0 ? (
                                        <SchemaTextFields
                                            fields={
                                                visibleEditableHeaderTextFields
                                            }
                                            values={Object.fromEntries(
                                                visibleEditableHeaderTextFields.map(
                                                    (field) => {
                                                        const raw =
                                                            headerValues[
                                                                field.key
                                                            ];
                                                        return [
                                                            field.key,
                                                            typeof raw ===
                                                                'string' ||
                                                            typeof raw ===
                                                                'number'
                                                                ? String(raw)
                                                                : '',
                                                        ];
                                                    },
                                                ),
                                            )}
                                            readOnly
                                            idPrefix="dispo-custom-ro"
                                            onChange={() => undefined}
                                        />
                                    ) : null}
                                    {visibleEditableHeaderChoiceFields.length >
                                    0 ? (
                                        <div data-test="dispo-order-custom-header-choice-fields-ro">
                                            <SchemaChoiceReadonlyFields
                                                fields={
                                                    visibleEditableHeaderChoiceFields
                                                }
                                                values={headerValues}
                                                captured={nativeChoiceCapturedMap(
                                                    visibleEditableHeaderChoiceFields,
                                                    headerCaptured,
                                                )}
                                                idPrefix="dispo-custom-choice-ro"
                                            />
                                        </div>
                                    ) : null}
                                </>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                <Card className="border-border/70 rounded-xl shadow-xs">
                    <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                        <CardTitle className="text-sm font-semibold">
                            Summen
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 px-5 py-4 text-sm">
                        <SummaryRow
                            label="Media-Brutto"
                            value={money(order.media_gross)}
                        />
                        <SummaryRow
                            label="Positionsrabatte"
                            value={money(order.position_discount_total)}
                        />
                        <SummaryRow
                            label="Auftragsrabatte"
                            value={money(order.order_discount_total)}
                        />
                        {order.ae_enabled ? (
                            <SummaryRow
                                label="AE gesamt"
                                value={money(order.ae_total)}
                            />
                        ) : null}
                        <SummaryRow
                            label="Netto (N/N)"
                            value={money(order.nn_invest)}
                            emphasis
                            data-test="dispo-order-net-total"
                        />
                        {order.requires_special_approval ? (
                            <div
                                className="pt-2"
                                data-test="dispo-order-special-approval-hint"
                            >
                                <p className="text-primary text-xs font-medium">
                                    Sonderfreigabe erforderlich
                                </p>
                                <SpecialApprovalReasonsList
                                    reasons={order.special_approval_reasons}
                                />
                            </div>
                        ) : null}
                        {order.source_calculation_totals &&
                        order.source_calculation_totals.nn_invest !==
                            order.nn_invest ? (
                            <p
                                className="text-muted-foreground border-border/60 mt-3 border-t pt-3 text-xs"
                                data-test="dispo-order-source-calculation-totals"
                            >
                                Summe der Quellkalkulation{' '}
                                {order.source_calculation_number}:{' '}
                                {money(
                                    order.source_calculation_totals.nn_invest,
                                )}{' '}
                                N/N (nicht Auftragssumme)
                            </p>
                        ) : null}
                    </CardContent>
                </Card>

                <DispoOrderApprovalHistory entries={order.approval_history} />

                <DispoOrderStatusHistory entries={order.status_history ?? []} />

                <DispoOrderCommunicationHistory
                    entries={order.communication ?? []}
                />

                <Card className="border-border/70 rounded-xl shadow-xs">
                    <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                        <CardTitle className="text-sm font-semibold">
                            Positionen ({order.positions.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 px-5 py-4">
                        {order.positions.map((position, index) => {
                            const positionValues =
                                position.dynamic_field_values ?? {};
                            const positionCaptured =
                                position.dynamic_field_captured ?? {};
                            const periodOpen = positionValues.period_open;
                            const periodOpenCaptured =
                                positionCaptured.period_open === true;
                            const flightCaptured =
                                positionCaptured.position_flight_period ===
                                true;
                            const flightDisplay = displayCalcOriginPeriod(
                                positionValues.position_flight_period,
                                flightCaptured,
                            );
                            const view = visiblePositionFieldViews[
                                position.id
                            ] ?? {
                                editableText: [],
                                editableChoice: [],
                                calcOriginText: [],
                                calcOriginChoice: [],
                            };
                            const showCalcOrigin =
                                view.calcOriginText.length > 0 ||
                                view.calcOriginChoice.length > 0;
                            const showNative =
                                view.editableText.length > 0 ||
                                view.editableChoice.length > 0;

                            return (
                                <section
                                    key={position.id}
                                    className="rounded-lg border p-4"
                                    data-test={`dispo-order-position-${index}`}
                                >
                                    <p className="font-medium">
                                        {position.inventory_name} ·{' '}
                                        {position.advertising_medium_name}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {position.spot_method_label} ·{' '}
                                        {position.total_spot_count} Spots ·{' '}
                                        {position.length_seconds}s
                                        {position.price_list_version
                                            ? ` · Preisliste ${position.price_list_version}`
                                            : ''}
                                    </p>
                                    <DispoOrderInvoiceEndMonths
                                        orderId={order.id}
                                        positionId={position.id}
                                        lockVersion={order.lock_version}
                                        months={
                                            position.invoice_end_months ?? null
                                        }
                                        monthLabels={
                                            position.invoice_end_month_labels
                                        }
                                        periodState={
                                            position.invoice_end_period_state ??
                                            'invalid'
                                        }
                                        canEdit={canUpdateInvoiceEndMonths}
                                    />
                                    {position.components &&
                                    position.components.length > 0 ? (
                                        <div
                                            className="text-muted-foreground mt-2 space-y-1 text-xs"
                                            data-test={`dispo-order-position-components-${index}`}
                                        >
                                            {position.component_profile &&
                                            COMPONENT_PROFILE_UI[
                                                position.component_profile
                                            ] ? (
                                                <p
                                                    data-test={`dispo-order-position-profile-${index}`}
                                                >
                                                    Komponentenprofil:{' '}
                                                    {
                                                        COMPONENT_PROFILE_UI[
                                                            position
                                                                .component_profile
                                                        ].label
                                                    }
                                                    {' · '}
                                                    {
                                                        COMPONENT_PROFILE_UI[
                                                            position
                                                                .component_profile
                                                        ].unit_label
                                                    }
                                                    :{' '}
                                                    {position.total_spot_count}
                                                    {position.derived_component_airings !=
                                                    null ? (
                                                        <>
                                                            {' '}
                                                            · abgeleitete
                                                            Sendungen:{' '}
                                                            {
                                                                position.derived_component_airings
                                                            }
                                                        </>
                                                    ) : null}
                                                </p>
                                            ) : null}
                                            <p>
                                                Spot-Komponenten ·{' '}
                                                {position.component_calculation_strategy ===
                                                'individual'
                                                    ? 'Komponenten einzeln berechnen'
                                                    : 'Gemeinsame Gesamtlänge'}
                                            </p>
                                            <ul className="list-inside list-disc">
                                                {[...position.components]
                                                    .sort(
                                                        (a, b) =>
                                                            (a.sort ?? 0) -
                                                            (b.sort ?? 0),
                                                    )
                                                    .map((component) => (
                                                        <li
                                                            key={`${component.role}-${component.sort ?? 0}`}
                                                        >
                                                            {componentDisplayLabel(
                                                                position,
                                                                component,
                                                            )}
                                                            :{' '}
                                                            {
                                                                component.length_seconds
                                                            }
                                                            s
                                                            {position.component_calculation_strategy ===
                                                                'individual' &&
                                                            component.media_gross
                                                                ? ` · ${component.media_gross}`
                                                                : ''}
                                                        </li>
                                                    ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                    <div className="text-muted-foreground mt-2 space-y-1 text-xs">
                                        <p>
                                            {periodOpenLabel}:{' '}
                                            {!periodOpenCaptured
                                                ? 'Nicht erfasst'
                                                : periodOpen
                                                  ? 'Ja'
                                                  : 'Nein'}
                                        </p>
                                        {periodOpenHelp ? (
                                            <p className="text-muted-foreground/80">
                                                {periodOpenHelp}
                                            </p>
                                        ) : null}
                                        <p>
                                            {flightPeriodLabel}: {flightDisplay}
                                        </p>
                                        {flightPeriodHelp ? (
                                            <p className="text-muted-foreground/80">
                                                {flightPeriodHelp}
                                            </p>
                                        ) : null}
                                    </div>
                                    {showCalcOrigin ? (
                                        <div
                                            className="mt-4 space-y-3"
                                            data-test={`dispo-order-calc-origin-position-fields-${position.id}`}
                                        >
                                            <p className="text-sm font-medium">
                                                Aus Kalkulation übernommen
                                            </p>
                                            {view.calcOriginText.length > 0 ? (
                                                <SchemaTextFields
                                                    fields={view.calcOriginText}
                                                    values={Object.fromEntries(
                                                        view.calcOriginText.map(
                                                            (field) => [
                                                                field.key,
                                                                textReadOnlyCapturedDisplay(
                                                                    positionValues[
                                                                        field
                                                                            .key
                                                                    ],
                                                                    positionCaptured[
                                                                        field
                                                                            .key
                                                                    ] === true,
                                                                ),
                                                            ],
                                                        ),
                                                    )}
                                                    readOnly
                                                    idPrefix={`dispo-pos-calc-${position.id}`}
                                                    onChange={() => undefined}
                                                />
                                            ) : null}
                                            {view.calcOriginChoice.length >
                                            0 ? (
                                                <SchemaChoiceReadonlyFields
                                                    fields={
                                                        view.calcOriginChoice
                                                    }
                                                    values={positionValues}
                                                    captured={positionCaptured}
                                                    idPrefix={`dispo-pos-calc-choice-${position.id}`}
                                                />
                                            ) : null}
                                        </div>
                                    ) : null}
                                    {showNative ? (
                                        <div
                                            className="mt-4 space-y-3"
                                            data-test={`dispo-order-native-position-fields-${position.id}`}
                                        >
                                            <p className="text-sm font-medium">
                                                Weitere Angaben
                                            </p>
                                            {canUpdate ? (
                                                <>
                                                    {view.editableText.length >
                                                    0 ? (
                                                        <SchemaTextFields
                                                            fields={
                                                                view.editableText
                                                            }
                                                            values={
                                                                positionCustomValues[
                                                                    position.id
                                                                ] ?? {}
                                                            }
                                                            errors={fieldErrors}
                                                            errorKeyPrefixes={[
                                                                `position_dynamic_field_values.${position.id}`,
                                                            ]}
                                                            disabled={
                                                                savingPositionCustoms
                                                            }
                                                            idPrefix={`dispo-pos-custom-${position.id}`}
                                                            onChange={(
                                                                key,
                                                                value,
                                                            ) =>
                                                                setPositionCustomValues(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [position.id]:
                                                                            {
                                                                                ...current[
                                                                                    position
                                                                                        .id
                                                                                ],
                                                                                [key]: value,
                                                                            },
                                                                    }),
                                                                )
                                                            }
                                                        />
                                                    ) : null}
                                                    {view.editableChoice
                                                        .length > 0 ? (
                                                        <div
                                                            data-test={`dispo-order-native-position-choice-fields-${position.id}`}
                                                        >
                                                            <SchemaChoiceFields
                                                                fields={
                                                                    view.editableChoice
                                                                }
                                                                values={
                                                                    positionChoiceValues[
                                                                        position
                                                                            .id
                                                                    ] ?? {}
                                                                }
                                                                errors={
                                                                    fieldErrors
                                                                }
                                                                errorKeyPrefixes={[
                                                                    `position_dynamic_field_values.${position.id}`,
                                                                ]}
                                                                disabled={
                                                                    savingPositionCustoms
                                                                }
                                                                idPrefix={`dispo-pos-custom-choice-${position.id}`}
                                                                onChange={(
                                                                    key,
                                                                    value,
                                                                ) => {
                                                                    setPositionChoiceValues(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [position.id]:
                                                                                {
                                                                                    ...current[
                                                                                        position
                                                                                            .id
                                                                                    ],
                                                                                    [key]: value,
                                                                                },
                                                                        }),
                                                                    );
                                                                    setPositionChoiceTouched(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [position.id]:
                                                                                {
                                                                                    ...current[
                                                                                        position
                                                                                            .id
                                                                                    ],
                                                                                    [key]: true,
                                                                                },
                                                                        }),
                                                                    );
                                                                    setPositionChoiceMeta(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [position.id]:
                                                                                {
                                                                                    ...current[
                                                                                        position
                                                                                            .id
                                                                                    ],
                                                                                    [key]: {
                                                                                        initPayloadSafe: true,
                                                                                        issue: null,
                                                                                    },
                                                                                },
                                                                        }),
                                                                    );
                                                                }}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </>
                                            ) : (
                                                <>
                                                    {view.editableText.length >
                                                    0 ? (
                                                        <SchemaTextFields
                                                            fields={
                                                                view.editableText
                                                            }
                                                            values={
                                                                positionCustomValues[
                                                                    position.id
                                                                ] ?? {}
                                                            }
                                                            readOnly
                                                            idPrefix={`dispo-pos-custom-${position.id}`}
                                                            onChange={() =>
                                                                undefined
                                                            }
                                                        />
                                                    ) : null}
                                                    {view.editableChoice
                                                        .length > 0 ? (
                                                        <div
                                                            data-test={`dispo-order-native-position-choice-fields-ro-${position.id}`}
                                                        >
                                                            <SchemaChoiceReadonlyFields
                                                                fields={
                                                                    view.editableChoice
                                                                }
                                                                values={
                                                                    positionValues
                                                                }
                                                                captured={nativeChoiceCapturedMap(
                                                                    view.editableChoice,
                                                                    positionCaptured,
                                                                )}
                                                                idPrefix={`dispo-pos-custom-choice-ro-${position.id}`}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </>
                                            )}
                                        </div>
                                    ) : null}
                                    {position.time_ranges.length > 0 ? (
                                        <ul className="text-muted-foreground mt-2 space-y-0.5 text-xs">
                                            {position.time_ranges.map(
                                                (range) => (
                                                    <li
                                                        key={`${range.start_hour}-${range.end_hour_exclusive}-${range.day_group}`}
                                                    >
                                                        {formatHour(
                                                            range.start_hour,
                                                        )}
                                                        –
                                                        {formatInclusiveEnd(
                                                            range.end_hour_exclusive,
                                                        )}{' '}
                                                        · {range.spot_count}{' '}
                                                        Spots
                                                        {range.range_gross
                                                            ? ` · ${money(range.range_gross)}`
                                                            : ''}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : null}
                                    {position.planner_entries &&
                                    position.planner_entries.length > 0 ? (
                                        <ul
                                            className="text-muted-foreground mt-2 space-y-0.5 text-xs"
                                            data-test={`dispo-planner-entries-${position.id}`}
                                        >
                                            {sortPlannerEntriesForDisplay(
                                                position.planner_entries,
                                            ).map((entry) => (
                                                <li
                                                    key={`${entry.date}-${entry.hour}`}
                                                >
                                                    {formatPlannerEntryLine(
                                                        entry,
                                                    )}
                                                    {entry.line_gross
                                                        ? ` · ${money(entry.line_gross)}`
                                                        : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                    {position.position_discounts.length > 0 ? (
                                        <ul className="text-muted-foreground mt-2 text-xs">
                                            {position.position_discounts.map(
                                                (discount, discountIndex) => (
                                                    <li key={discountIndex}>
                                                        {discount.custom_label ??
                                                            discount.type}
                                                        {discount.percent
                                                            ? ` ${formatPercent(discount.percent)}`
                                                            : ''}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : null}
                                    {position.pricing_settlement_mode ===
                                    'fixed_price' ? (
                                        <div
                                            className="mt-3 space-y-1 text-sm"
                                            data-test={`dispo-order-fixed-price-${index}`}
                                        >
                                            <p className="font-semibold tabular-nums">
                                                Festpreis (N/N):{' '}
                                                {money(
                                                    position.fixed_price_nn ??
                                                        position.nn_invest,
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                Referenz-Mediabrutto{' '}
                                                {money(position.media_gross)}
                                            </p>
                                            {position.effective_pay_factor_percent ? (
                                                <p className="text-muted-foreground text-xs">
                                                    Payfaktor{' '}
                                                    {formatPercent(
                                                        position.effective_pay_factor_percent,
                                                    )}
                                                    {position.effective_total_discount_percent
                                                        ? ` · Gesamtabschlag (→ N/N) ${formatPercent(position.effective_total_discount_percent)}`
                                                        : ''}
                                                </p>
                                            ) : null}
                                            {Number(position.ae_amount) > 0 ? (
                                                <p
                                                    className="text-muted-foreground text-xs"
                                                    data-test={`dispo-order-fixed-price-ae-${index}`}
                                                >
                                                    AE{' '}
                                                    {money(position.ae_amount)}
                                                </p>
                                            ) : null}
                                            {position.calculation_method_name ? (
                                                <p className="text-muted-foreground text-xs">
                                                    Berechnungsbasis:{' '}
                                                    {
                                                        position.calculation_method_name
                                                    }
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <p className="text-primary mt-2 text-sm font-semibold tabular-nums">
                                            {money(position.nn_invest)} N/N
                                        </p>
                                    )}
                                </section>
                            );
                        })}
                        {canUpdate && anyPositionHasEditableCustoms ? (
                            <>
                                {fieldErrors.lock_version ? (
                                    <p className="text-destructive text-xs">
                                        {fieldErrors.lock_version}
                                    </p>
                                ) : null}
                                <Button
                                    type="button"
                                    disabled={
                                        savingPositionCustoms ||
                                        dynamicControlsLocked
                                    }
                                    data-test="dispo-order-save-position-customs"
                                    onClick={savePositionCustoms}
                                >
                                    {savingPositionCustoms
                                        ? 'Wird gespeichert…'
                                        : 'Positionsangaben speichern'}
                                </Button>
                            </>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function positionFieldBucket(
    fieldSchema: FieldSchema,
    positionId: number,
): {
    fields: SchemaField[];
    rules: FieldSchema['rules'];
    editable_custom_fields: SchemaField[];
    calc_origin_custom_fields: SchemaField[];
} {
    const schemas = fieldSchema.position_field_schemas;
    if (!schemas) {
        return {
            fields: [],
            rules: [],
            editable_custom_fields: [],
            calc_origin_custom_fields: [],
        };
    }

    const bucket = schemas[positionId] ?? schemas[String(positionId)];

    return {
        fields: bucket?.fields ?? [],
        rules: bucket?.rules ?? [],
        editable_custom_fields: bucket?.editable_custom_fields ?? [],
        calc_origin_custom_fields: bucket?.calc_origin_custom_fields ?? [],
    };
}

function nativeChoiceCapturedMap(
    fields: SchemaChoiceField[],
    captured: Record<string, boolean>,
): Record<string, boolean> {
    const result: Record<string, boolean> = {};
    for (const field of fields) {
        result[field.key] = captured[field.key] === true;
    }

    return result;
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

function fieldLabel(
    schema: FieldSchema,
    key: string,
    fallback: string,
): string {
    return schema.fields.find((field) => field.key === key)?.label ?? fallback;
}

function fieldHelp(schema: FieldSchema, key: string): string | null {
    const help = schema.fields.find((field) => field.key === key)?.help_text;
    if (help == null || help.trim() === '') {
        return null;
    }

    return help;
}

function formatPeriod(period: PeriodValue | undefined): string | null {
    if (period == null || period.start == null || period.end == null) {
        return null;
    }

    const start = formatDateOnly(period.start);
    const end = formatDateOnly(period.end);
    if (start === '–' || end === '–') {
        return null;
    }

    return `${start} – ${end}`;
}

function displayCalcOriginPeriod(
    period: PeriodValue | undefined,
    captured: boolean,
): string {
    if (!captured) {
        return 'Nicht erfasst';
    }

    return formatPeriod(period) ?? '–';
}

function displayOptionalText(value: string | null | undefined): string {
    if (value == null || value.trim() === '') {
        return '–';
    }

    return value;
}

function Detail({
    label,
    value,
    helpText,
}: {
    label: string;
    value: ReactNode;
    helpText?: string | null;
}) {
    return (
        <div>
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
            {helpText ? (
                <p className="text-muted-foreground mt-1 text-xs">{helpText}</p>
            ) : null}
            <p className="mt-1 text-sm">{value ?? '–'}</p>
        </div>
    );
}

function SummaryRow({
    label,
    value,
    emphasis,
    'data-test': dataTest,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
    'data-test'?: string;
}) {
    return (
        <div
            className="flex items-baseline justify-between gap-3"
            data-test={dataTest}
        >
            <span className={emphasis ? 'font-medium' : undefined}>
                {label}
            </span>
            <span
                className={
                    emphasis
                        ? 'text-primary text-lg font-bold tabular-nums'
                        : 'font-medium tabular-nums'
                }
            >
                {value}
            </span>
        </div>
    );
}

DispoOrderShow.layout = {
    breadcrumbs: [
        { title: 'Dispoaufträge', href: '/dispoauftraege' },
        { title: 'Detail', href: '/dispoauftraege' },
    ],
};
