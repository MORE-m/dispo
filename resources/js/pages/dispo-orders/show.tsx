import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DispoOrderApprovalActions } from '@/components/dispo-order-approval-actions';
import { DispoOrderApprovalHistory } from '@/components/dispo-order-approval-history';
import { DispoOrderReviseAction } from '@/components/dispo-order-revise-action';
import { DispoOrderStatusBadge } from '@/components/dispo-order-status-badge';
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
import { formatDateOnly, formatDateTime } from '@/lib/date-time';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
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
    max_length?: number | null;
    required?: boolean;
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
};

type OrderPosition = {
    id: number;
    inventory_name: string;
    advertising_medium_name: string;
    spot_method: string;
    spot_method_label: string;
    length_seconds: number;
    total_spot_count: number;
    price_list_version: string | null;
    media_gross: string;
    position_discount_amount: string;
    order_discount_amount: string;
    ae_amount: string;
    nn_invest: string;
    time_ranges: {
        start_hour: number;
        end_hour_exclusive: number;
        day_group?: string;
        spot_count: number;
        range_gross?: string | null;
    }[];
    position_discounts: {
        type?: string;
        custom_label?: string | null;
        percent?: string;
    }[];
    dynamic_field_values?: Record<string, unknown> & {
        period_open?: boolean | null;
        position_flight_period?: PeriodValue;
    };
    dynamic_field_captured?: Record<string, boolean> & {
        period_open?: boolean;
        position_flight_period?: boolean;
    };
};

type OrderDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    lock_version: number;
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
    positions: OrderPosition[];
};

export default function DispoOrderShow({
    order,
    fieldSchema = { fields: [], rules: [] },
    canViewCalculation,
    canSubmit = false,
    canUpdate = false,
    canSyncCalculationDynamicFields = false,
    canApprove = false,
    canReject = false,
    canRevise = false,
    isCreator = false,
}: {
    order: OrderDetail;
    fieldSchema?: FieldSchema;
    canViewCalculation: boolean;
    canSubmit?: boolean;
    canUpdate?: boolean;
    canSyncCalculationDynamicFields?: boolean;
    canApprove?: boolean;
    canReject?: boolean;
    canRevise?: boolean;
    isCreator?: boolean;
}) {
    const flash = usePage().props.flash;
    const current = order.current_approval;
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
    const editableCustomFields = useMemo((): SchemaTextField[] => {
        const source =
            fieldSchema.editable_custom_header_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.editable === true &&
                    field.scope === 'header' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            );

        return source.map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            max_length: field.max_length ?? undefined,
            required: field.required === true,
        }));
    }, [fieldSchema]);
    const calcOriginCustomFields = useMemo((): SchemaTextField[] => {
        const source =
            fieldSchema.calc_origin_custom_header_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.calc_origin === true &&
                    field.scope === 'header' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            );

        return source.map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            max_length: field.max_length ?? undefined,
        }));
    }, [fieldSchema]);
    const editablePositionCustomFields = useMemo((): SchemaTextField[] => {
        const source =
            fieldSchema.editable_custom_position_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.editable === true &&
                    field.scope === 'position' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            );

        return source.map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            max_length: field.max_length ?? undefined,
            required: field.required === true,
        }));
    }, [fieldSchema]);
    const calcOriginPositionCustomFields = useMemo((): SchemaTextField[] => {
        const source =
            fieldSchema.calc_origin_custom_position_fields ??
            fieldSchema.fields.filter(
                (field) =>
                    field.is_system !== true &&
                    field.calc_origin === true &&
                    field.scope === 'position' &&
                    (field.field_type === 'short_text' ||
                        field.field_type === 'long_text'),
            );

        return source.map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            max_length: field.max_length ?? undefined,
        }));
    }, [fieldSchema]);
    const [customHeaderValues, setCustomHeaderValues] = useState<
        Record<string, string>
    >(() => {
        const initial: Record<string, string> = {};
        for (const field of editableCustomFields) {
            const raw = headerValues[field.key];
            initial[field.key] =
                typeof raw === 'string' || typeof raw === 'number'
                    ? String(raw)
                    : '';
        }
        return initial;
    });
    const [positionCustomValues, setPositionCustomValues] = useState<
        Record<number, Record<string, string>>
    >(() => {
        const initial: Record<number, Record<string, string>> = {};
        for (const position of order.positions) {
            const stored = position.dynamic_field_values ?? {};
            const row: Record<string, string> = {};
            for (const field of editablePositionCustomFields) {
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
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [savingNotes, setSavingNotes] = useState(false);
    const [savingPositionCustoms, setSavingPositionCustoms] = useState(false);
    const [syncingCalcFields, setSyncingCalcFields] = useState(false);

    const pageDescription =
        order.status === 'draft' && canUpdate
            ? 'Entwurf – Rechnungs- und Dispohinweise bearbeitbar'
            : 'Dispoauftrag (Snapshot, schreibgeschützt)';

    const campaignPeriodLabel = fieldLabel(
        fieldSchema,
        'campaign_period',
        'Kampagnenzeitraum',
    );
    const campaignPeriodHelp = fieldHelp(fieldSchema, 'campaign_period');
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

    function saveSystemNotes() {
        if (savingNotes) {
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

        setSavingNotes(true);
        setFieldErrors({});
        router.patch(
            `/dispoauftraege/${order.id}`,
            {
                lock_version: order.lock_version,
                dynamic_field_values: Object.fromEntries(
                    editableCustomFields.map((field) => [
                        field.key,
                        customHeaderValues[field.key] ?? '',
                    ]),
                ),
            },
            {
                preserveScroll: true,
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

        setSavingPositionCustoms(true);
        setFieldErrors({});
        const payload: Record<string, Record<string, string>> = {};
        for (const position of order.positions) {
            payload[String(position.id)] = Object.fromEntries(
                editablePositionCustomFields.map((field) => [
                    field.key,
                    positionCustomValues[position.id]?.[field.key] ?? '',
                ]),
            );
        }
        router.patch(
            `/dispoauftraege/${order.id}/positions-angaben`,
            {
                lock_version: order.lock_version,
                position_dynamic_field_values: payload,
            },
            {
                preserveScroll: true,
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

                <DispoOrderApprovalActions
                    orderId={order.id}
                    lockVersion={order.lock_version}
                    canSubmit={canSubmit}
                    canApprove={canApprove}
                    canReject={canReject}
                    isCreator={isCreator}
                    status={order.status}
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
                        </div>
                        {calcOriginCustomFields.length > 0 ? (
                            <div
                                className="space-y-3 border-t pt-3"
                                data-test="dispo-order-calc-origin-custom-fields"
                            >
                                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                    Aus Kalkulation
                                </p>
                                <SchemaTextFields
                                    fields={calcOriginCustomFields}
                                    values={Object.fromEntries(
                                        calcOriginCustomFields.map((field) => {
                                            const raw = headerValues[field.key];
                                            const captured =
                                                headerCaptured[field.key] ===
                                                true;
                                            if (!captured) {
                                                return [
                                                    field.key,
                                                    'Nicht erfasst',
                                                ];
                                            }
                                            return [
                                                field.key,
                                                typeof raw === 'string' ||
                                                typeof raw === 'number'
                                                    ? String(raw)
                                                    : '',
                                            ];
                                        }),
                                    )}
                                    readOnly
                                    idPrefix="dispo-calc-origin"
                                    onChange={() => undefined}
                                />
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
                            disabled={syncingCalcFields}
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
                                <div className="grid gap-2">
                                    <Label htmlFor="dispo-order-billing-special-features">
                                        {billingLabel}
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
                                        disabled={savingNotes}
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
                                <div className="grid gap-2">
                                    <Label htmlFor="dispo-order-disposition-notes">
                                        {dispositionLabel}
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
                                        disabled={savingNotes}
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
                                {fieldErrors.dynamic_field_values ||
                                fieldErrors.lock_version ? (
                                    <p className="text-destructive text-xs">
                                        {fieldErrors.dynamic_field_values ??
                                            fieldErrors.lock_version}
                                    </p>
                                ) : null}
                                <Button
                                    type="button"
                                    disabled={savingNotes}
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
                                <div data-test="dispo-order-billing-special-features">
                                    <Detail
                                        label={billingLabel}
                                        value={displayOptionalText(
                                            headerValues.billing_special_features,
                                        )}
                                        helpText={billingHelp}
                                    />
                                </div>
                                <div data-test="dispo-order-disposition-notes">
                                    <Detail
                                        label={dispositionLabel}
                                        value={displayOptionalText(
                                            headerValues.disposition_notes,
                                        )}
                                        helpText={dispositionHelp}
                                    />
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                {editableCustomFields.length > 0 ||
                Object.keys(customHeaderValues).length > 0 ? (
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
                                    <SchemaTextFields
                                        fields={editableCustomFields}
                                        values={customHeaderValues}
                                        errors={fieldErrors}
                                        disabled={savingNotes}
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
                                    <Button
                                        type="button"
                                        disabled={savingNotes}
                                        data-test="dispo-order-save-custom-headers"
                                        onClick={saveCustomHeaders}
                                    >
                                        {savingNotes
                                            ? 'Wird gespeichert…'
                                            : 'Speichern'}
                                    </Button>
                                </>
                            ) : (
                                <SchemaTextFields
                                    fields={editableCustomFields}
                                    values={Object.fromEntries(
                                        editableCustomFields.map((field) => {
                                            const raw = headerValues[field.key];
                                            return [
                                                field.key,
                                                typeof raw === 'string' ||
                                                typeof raw === 'number'
                                                    ? String(raw)
                                                    : '',
                                            ];
                                        }),
                                    )}
                                    readOnly
                                    idPrefix="dispo-custom-ro"
                                    onChange={() => undefined}
                                />
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
                                    {calcOriginPositionCustomFields.length >
                                    0 ? (
                                        <div
                                            className="mt-4 space-y-3"
                                            data-test={`dispo-order-calc-origin-position-fields-${position.id}`}
                                        >
                                            <p className="text-sm font-medium">
                                                Aus Kalkulation
                                            </p>
                                            <SchemaTextFields
                                                fields={
                                                    calcOriginPositionCustomFields
                                                }
                                                values={Object.fromEntries(
                                                    calcOriginPositionCustomFields.map(
                                                        (field) => {
                                                            const raw =
                                                                positionValues[
                                                                    field.key
                                                                ];
                                                            const captured =
                                                                positionCaptured[
                                                                    field.key
                                                                ] === true;
                                                            if (!captured) {
                                                                return [
                                                                    field.key,
                                                                    '',
                                                                ];
                                                            }
                                                            return [
                                                                field.key,
                                                                typeof raw ===
                                                                    'string' ||
                                                                typeof raw ===
                                                                    'number'
                                                                    ? String(
                                                                          raw,
                                                                      )
                                                                    : '',
                                                            ];
                                                        },
                                                    ),
                                                )}
                                                readOnly
                                                idPrefix={`dispo-pos-calc-${position.id}`}
                                                onChange={() => undefined}
                                            />
                                        </div>
                                    ) : null}
                                    {editablePositionCustomFields.length > 0 ? (
                                        <div
                                            className="mt-4 space-y-3"
                                            data-test={`dispo-order-native-position-fields-${position.id}`}
                                        >
                                            <p className="text-sm font-medium">
                                                Weitere Angaben
                                            </p>
                                            {canUpdate ? (
                                                <SchemaTextFields
                                                    fields={
                                                        editablePositionCustomFields
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
                                                    onChange={(key, value) =>
                                                        setPositionCustomValues(
                                                            (current) => ({
                                                                ...current,
                                                                [position.id]: {
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
                                            ) : (
                                                <SchemaTextFields
                                                    fields={
                                                        editablePositionCustomFields
                                                    }
                                                    values={
                                                        positionCustomValues[
                                                            position.id
                                                        ] ?? {}
                                                    }
                                                    readOnly
                                                    idPrefix={`dispo-pos-custom-${position.id}`}
                                                    onChange={() => undefined}
                                                />
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
                                    <p className="text-primary mt-2 text-sm font-semibold tabular-nums">
                                        {money(position.nn_invest)} N/N
                                    </p>
                                </section>
                            );
                        })}
                        {canUpdate &&
                        editablePositionCustomFields.length > 0 ? (
                            <Button
                                type="button"
                                disabled={savingPositionCustoms}
                                data-test="dispo-order-save-position-customs"
                                onClick={savePositionCustoms}
                            >
                                {savingPositionCustoms
                                    ? 'Wird gespeichert…'
                                    : 'Positionsangaben speichern'}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
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
