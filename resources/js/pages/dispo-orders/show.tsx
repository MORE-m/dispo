import type { ReactNode } from 'react';
import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DispoOrderApprovalActions } from '@/components/dispo-order-approval-actions';
import { DispoOrderApprovalHistory } from '@/components/dispo-order-approval-history';
import { DispoOrderReviseAction } from '@/components/dispo-order-revise-action';
import { DispoOrderStatusBadge } from '@/components/dispo-order-status-badge';
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

type FieldSchema = {
    fields: Array<{
        key: string;
        label: string;
        help_text: string | null;
        field_type: string;
        scope: string;
        sort: number;
        group_key?: string | null;
    }>;
    rules: Array<{
        sort?: number;
        condition: { op?: string; field_key?: string; value?: unknown };
        action: { op?: string; field_key?: string };
    }>;
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
    dynamic_field_values?: {
        period_open?: boolean | null;
        position_flight_period?: PeriodValue;
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
    dynamic_field_values?: {
        billing_special_features?: string | null;
        disposition_notes?: string | null;
        campaign_period?: PeriodValue;
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

    const [billingSpecialFeatures, setBillingSpecialFeatures] = useState(
        headerValues.billing_special_features ?? '',
    );
    const [dispositionNotes, setDispositionNotes] = useState(
        headerValues.disposition_notes ?? '',
    );
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [savingNotes, setSavingNotes] = useState(false);
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
    const billingLabel = fieldLabel(
        fieldSchema,
        'billing_special_features',
        'Besonderheiten zur Rechnungsstellung',
    );
    const dispositionLabel = fieldLabel(
        fieldSchema,
        'disposition_notes',
        'Wichtige Informationen an die Disposition',
    );
    const periodOpenLabel = fieldLabel(
        fieldSchema,
        'period_open',
        'Zeitraum offen',
    );
    const flightPeriodLabel = fieldLabel(
        fieldSchema,
        'position_flight_period',
        'Flugzeitraum',
    );

    const campaignPeriodDisplay = (() => {
        const formatted = formatPeriod(headerValues.campaign_period);
        if (formatted !== null) {
            return formatted;
        }

        const missing =
            order.missing_calc_origin_keys?.includes('campaign_period') ??
            false;
        if (order.historically_uncaptured || missing) {
            return 'Nicht erfasst';
        }

        return '–';
    })();

    function saveNotes() {
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
                            />
                        </div>
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
                                    <textarea
                                        id="dispo-order-billing-special-features"
                                        className={formTextareaClass}
                                        value={billingSpecialFeatures}
                                        data-test="dispo-order-billing-special-features"
                                        disabled={savingNotes}
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
                                    <textarea
                                        id="dispo-order-disposition-notes"
                                        className={formTextareaClass}
                                        value={dispositionNotes}
                                        data-test="dispo-order-disposition-notes"
                                        disabled={savingNotes}
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
                                    onClick={saveNotes}
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
                                        value={displayTextOrUncaptured(
                                            headerValues.billing_special_features,
                                        )}
                                    />
                                </div>
                                <div data-test="dispo-order-disposition-notes">
                                    <Detail
                                        label={dispositionLabel}
                                        value={displayTextOrUncaptured(
                                            headerValues.disposition_notes,
                                        )}
                                    />
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

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
                            const periodOpen = positionValues.period_open;
                            const flightFormatted = formatPeriod(
                                positionValues.position_flight_period,
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
                                            {periodOpen == null
                                                ? 'Nicht erfasst'
                                                : periodOpen
                                                  ? 'Ja'
                                                  : 'Nein'}
                                        </p>
                                        <p>
                                            {flightPeriodLabel}:{' '}
                                            {flightFormatted ?? 'Nicht erfasst'}
                                        </p>
                                    </div>
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

function displayTextOrUncaptured(value: string | null | undefined): string {
    if (value == null || value.trim() === '') {
        return 'Nicht erfasst';
    }

    return value;
}

function Detail({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
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
