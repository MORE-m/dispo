import { Head, router, usePage } from '@inertiajs/react';
import { Check, SlidersHorizontal, Wallet } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CalculationSummaryPanel } from '@/components/calculation-summary-panel';
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
    money,
} from '@/components/form-field';
import { PriceTimeRanges } from '@/components/price-time-ranges';
import {
    emptyTimeRange,
    formatHour,
    formatInclusiveEnd,
    payloadTimeRanges,
    totalSpotCount,
    type DayGroupOption,
    type TimeRangeDraft,
} from '@/lib/pricing-time';
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
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { WizardStepper } from '@/components/wizard-stepper';
import { cn } from '@/lib/utils';

type PlanRow = {
    hour: number;
    day_group: string;
    second_price?: string | null;
};

type PositionDraft = {
    id?: number;
    client_key: string;
    inventory_id: number;
    advertising_medium_id: number;
    spot_method: string;
    length_seconds: number;
    total_spot_count: number;
    needs_spot_redistribution?: boolean;
    position_discount_percent: string;
    ae_percent: string;
    plan_rows: PlanRow[];
    time_ranges: TimeRangeDraft[];
    position_discounts: DiscountDraft[];
};

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

type Proposal = {
    id?: number;
    lock_version?: number;
    strategy: string;
    target_budget_nn: string;
    used_nn: string;
    remainder: string;
    explanation: string;
    positions: {
        position_key: string;
        inventory_id: number;
        length_seconds: number;
        total_spot_count: number;
    }[];
};

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
        spot_method: string;
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
    }[];
};

const STEPS = [
    'Grunddaten',
    'Werbeelemente',
    'Konditionen',
    'Zusammenfassung',
] as const;

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

function firstValidPosition(catalog: Catalog): PositionDraft | null {
    for (const inventory of catalog.inventories.filter(
        (item) => item.is_active,
    )) {
        for (const medium of catalog.media.filter(
            (item) => item.code === 'spot_classic' && item.is_active,
        )) {
            const rule = ruleFor(catalog, inventory.id, medium.id);
            if (!rule || !rule.is_active) {
                continue;
            }

            return {
                client_key: newClientKey(),
                inventory_id: inventory.id,
                advertising_medium_id: medium.id,
                spot_method: 'average',
                length_seconds:
                    rule.default_length_seconds ??
                    medium.default_length_seconds,
                total_spot_count: 0,
                position_discount_percent: '0',
                ae_percent: '0',
                plan_rows: [],
                time_ranges: [emptyTimeRange()],
                position_discounts: [],
            };
        }
    }

    return null;
}

function positionKey(position: PositionDraft, index: number): string {
    return position.id
        ? `id:${position.id}`
        : position.client_key || `new:${index}`;
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

export default function CalculationWizard({
    catalog,
    dayGroups,
    discountTypes,
    calculation,
    savedSummary,
    canEdit,
}: {
    catalog: Catalog;
    dayGroups: DayGroupOption[];
    discountTypes: DiscountTypeOption[];
    calculation: SavedCalculation | null;
    savedSummary: SavedSummary | null;
    canEdit: boolean;
}) {
    const flash = usePage().props.flash;
    const [step, setStep] = useState(0);
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
    const [budgetStrategy, setBudgetStrategy] = useState(
        calculation?.budget_strategy ?? 'equal_budget',
    );
    const [positions, setPositions] = useState<PositionDraft[]>(() => {
        if (calculation?.positions?.length) {
            return calculation.positions.map((position) => ({
                id: position.id,
                client_key: position.client_key ?? newClientKey(),
                inventory_id: position.inventory_id,
                advertising_medium_id: position.advertising_medium_id,
                spot_method: position.spot_method ?? 'average',
                length_seconds: position.length_seconds,
                total_spot_count: position.total_spot_count,
                needs_spot_redistribution: position.needs_spot_redistribution,
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
            }));
        }

        const first = firstValidPosition(catalog);
        return first ? [first] : [];
    });
    const [totals, setTotals] = useState<Totals | null>(null);
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const previewSeq = useRef(0);

    const payload = useMemo(
        () => ({
            planning_mode: planningMode,
            customer_name: customerName || null,
            agency_name: agencyName || null,
            campaign: campaign || null,
            product_title: productTitle || null,
            briefing: briefing || null,
            order_discount_percent: '0',
            order_discounts: payloadDiscounts(orderDiscounts),
            ae_enabled: aeEnabled,
            target_budget_nn: targetBudget === '' ? null : targetBudget,
            budget_strategy: planningMode === 'budget' ? budgetStrategy : null,
            lock_version: calculation?.lock_version,
            calculation_id: calculation?.id,
            positions: positions.map((position) => {
                const ranges = payloadTimeRanges(position.time_ranges);

                return {
                    id: position.id,
                    client_key: position.client_key,
                    inventory_id: position.inventory_id,
                    advertising_medium_id: position.advertising_medium_id,
                    spot_method: position.spot_method,
                    length_seconds: position.length_seconds,
                    total_spot_count: totalSpotCount(position.time_ranges),
                    needs_spot_redistribution:
                        position.needs_spot_redistribution ?? false,
                    position_discount_percent: '0',
                    ae_percent: '0',
                    time_ranges: ranges,
                    position_discounts: payloadDiscounts(
                        position.position_discounts,
                    ),
                    plan_rows: ranges.flatMap((range) =>
                        Array.from(
                            {
                                length:
                                    range.end_hour_exclusive - range.start_hour,
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
            orderDiscounts,
            aeEnabled,
            targetBudget,
            budgetStrategy,
            calculation,
            positions,
        ],
    );

    useEffect(() => {
        if (!canEdit || busy) {
            return;
        }

        const seq = ++previewSeq.current;
        const controller = new AbortController();

        const handle = window.setTimeout(() => {
            void jsonPost<{ totals: Totals }>(
                '/kalkulationen/vorschau',
                payload,
                controller.signal,
            )
                .then((data) => {
                    if (seq !== previewSeq.current) {
                        return;
                    }

                    setTotals(data.totals);
                    setError(null);
                    setFieldErrors({});
                })
                .catch((caught: unknown) => {
                    if (
                        caught instanceof DOMException &&
                        caught.name === 'AbortError'
                    ) {
                        return;
                    }

                    if (seq !== previewSeq.current) {
                        return;
                    }

                    if (caught instanceof JsonPostError) {
                        setFieldErrors(caught.fieldErrors);
                        setError(caught.message);
                        return;
                    }

                    setError(
                        caught instanceof Error
                            ? caught.message
                            : 'Berechnung nicht möglich.',
                    );
                });
        }, 280);

        return () => {
            window.clearTimeout(handle);
            controller.abort();
        };
    }, [payload, canEdit, busy]);

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
                medium.code === 'spot_classic' &&
                medium.is_active &&
                mediumIds.has(medium.id),
        );
    }

    function updatePosition(index: number, patch: Partial<PositionDraft>) {
        setPositions((current) =>
            current.map((item, itemIndex) => {
                if (itemIndex !== index) {
                    return item;
                }

                let next = { ...item, ...patch };

                if (patch.inventory_id !== undefined) {
                    const media = allowedMediaFor(patch.inventory_id);
                    const medium =
                        media.find(
                            (candidate) =>
                                candidate.id === item.advertising_medium_id,
                        ) ?? media[0];

                    if (!medium) {
                        return item;
                    }

                    const rule = ruleFor(
                        catalog,
                        patch.inventory_id,
                        medium.id,
                    );

                    next = {
                        ...next,
                        inventory_id: patch.inventory_id,
                        advertising_medium_id: medium.id,
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
                }

                return next;
            }),
        );
    }

    function removePosition(index: number) {
        setPositions((current) =>
            current.filter((_, itemIndex) => itemIndex !== index),
        );
    }

    function save() {
        setBusy(true);
        const url = calculation
            ? `/kalkulationen/${calculation.id}`
            : '/kalkulationen';

        const options = {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onError: (errors: Record<string, string | string[]>) => {
                const mapped: Record<string, string[]> = {};
                for (const [key, value] of Object.entries(errors)) {
                    mapped[key] = Array.isArray(value)
                        ? value
                        : [String(value)];
                }
                setFieldErrors(mapped);
                setError('Speichern nicht möglich. Angaben prüfen.');
                setBusy(false);
            },
        };

        if (calculation) {
            router.put(url, payload, options);
        } else {
            router.post(url, payload, options);
        }
    }

    async function createProposal() {
        setBusy(true);
        try {
            const data = await jsonPost<{ proposal: Proposal }>(
                '/kalkulationen/budget-vorschlag',
                payload,
            );
            setProposal(data.proposal);
            setError(null);
            setFieldErrors({});
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setFieldErrors(caught.fieldErrors);
                setError(caught.message);
            } else {
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'Vorschlag nicht möglich.',
                );
            }
        } finally {
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
                        setError('Übernahme nicht möglich.');
                        setBusy(false);
                    },
                },
            );
            return;
        }

        setPositions((current) =>
            current.map((position, index) => {
                const key = positionKey(position, index);
                const match =
                    proposal.positions.find(
                        (item) => item.position_key === key,
                    ) ??
                    proposal.positions.find(
                        (item) => item.inventory_id === position.inventory_id,
                    );

                if (!match) {
                    return {
                        ...position,
                        total_spot_count: 0,
                        time_ranges: position.time_ranges.map(
                            (range, index) => ({
                                ...range,
                                spot_count: index === 0 ? '' : range.spot_count,
                            }),
                        ),
                    };
                }

                const current = totalSpotCount(position.time_ranges);
                const target = match.total_spot_count;
                let assigned = 0;

                return {
                    ...position,
                    length_seconds: match.length_seconds,
                    total_spot_count: target,
                    time_ranges: position.time_ranges.map(
                        (range, index, all) => {
                            const last = index === all.length - 1;
                            const source =
                                typeof range.spot_count === 'number'
                                    ? range.spot_count
                                    : 0;
                            const share =
                                current < 1
                                    ? index === 0
                                        ? target
                                        : 0
                                    : last
                                      ? Math.max(0, target - assigned)
                                      : Math.floor((source / current) * target);
                            assigned += last ? 0 : share;

                            return { ...range, spot_count: share };
                        },
                    ),
                };
            }),
        );
        setProposal(null);
    }

    const displayTotals = canEdit ? totals : null;
    const summary = !canEdit && savedSummary ? savedSummary : null;
    const summaryTotals =
        displayTotals ??
        (summary
            ? {
                  media_gross: summary.media_gross,
                  position_discount_total: summary.position_discount_total,
                  order_discount_total: summary.order_discount_total,
                  ae_total: summary.ae_total,
                  nn_invest: summary.nn_invest,
                  target_budget_nn: summary.target_budget_nn,
                  requires_special_approval: summary.requires_special_approval,
                  positions: summary.positions.map((position) => ({
                      nn_invest: position.nn_invest,
                      media_gross: position.media_gross,
                      spot_count: position.total_spot_count,
                      time_ranges: position.time_ranges,
                      position_discounts: position.position_discounts?.map(
                          (discount) => ({
                              label: discount.custom_label ?? discount.type,
                              percent: discount.percent,
                          }),
                      ),
                  })),
              }
            : null);
    const hasActiveCatalog =
        catalog.inventories.some((item) => item.is_active) &&
        catalog.media.some(
            (item) => item.code === 'spot_classic' && item.is_active,
        );
    const catalogMissing = positions.length === 0 && !hasActiveCatalog;

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
                {error ? <ErrorState message={error} /> : null}

                <WizardStepper
                    steps={STEPS}
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
                                                onChange={() =>
                                                    setPlanningMode('manual')
                                                }
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
                                                onChange={() =>
                                                    setPlanningMode('budget')
                                                }
                                            >
                                                <SelectionCardOption
                                                    icon={Wallet}
                                                    title="Mit Budget planen"
                                                    description="Zielbudget und Verteilungslogik vorgeben."
                                                />
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
                                            label="Zielbudget N/N"
                                            htmlFor="budget"
                                            hint={
                                                planningMode === 'manual'
                                                    ? 'Nur Vergleich mit dem aktuellen N/N-Invest.'
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                id="budget"
                                                inputMode="decimal"
                                                value={targetBudget}
                                                onChange={(event) =>
                                                    setTargetBudget(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={!canEdit}
                                            />
                                        </FormField>
                                        {planningMode === 'budget' ? (
                                            <FormField
                                                label="Verteilung"
                                                htmlFor="strategy"
                                            >
                                                <select
                                                    id="strategy"
                                                    className={formSelectClass}
                                                    value={budgetStrategy}
                                                    onChange={(event) =>
                                                        setBudgetStrategy(
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={!canEdit}
                                                >
                                                    <option value="equal_budget">
                                                        Budget je Sender gleich
                                                        verteilen
                                                    </option>
                                                    <option value="maximize_spots">
                                                        Spotanzahl maximieren
                                                    </option>
                                                </select>
                                            </FormField>
                                        ) : null}
                                    </CardContent>
                                </Card>
                            </div>
                        ) : null}

                        {step === 1 ? (
                            catalogMissing ? (
                                <EmptyState
                                    title="Kein Katalog"
                                    description="Sender, Werbemittel und Preise fehlen. Es werden keine Beispieldaten vorgetäuscht."
                                />
                            ) : (
                                <div className="space-y-6">
                                    {positions.map((position, index) => {
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
                                                            <FormField label="Kalkulationsart">
                                                                <Input
                                                                    readOnly
                                                                    value="Durchschnitt"
                                                                    disabled
                                                                    className="bg-muted/50 text-muted-foreground"
                                                                />
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

                                                        <input
                                                            type="hidden"
                                                            value={
                                                                position.advertising_medium_id
                                                            }
                                                            readOnly
                                                        />

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
                                                        ] ? (
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
                                                        ) : canEdit ? (
                                                            <LoadingState label="Berechnet" />
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
                            <div className="space-y-6">
                                {positions.map((position, index) => {
                                    const inventory = catalog.inventories.find(
                                        (item) =>
                                            item.id === position.inventory_id,
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
                                                        {displayTotals
                                                            ?.positions[index]
                                                            ?.media_gross
                                                            ? money(
                                                                  displayTotals
                                                                      .positions[
                                                                      index
                                                                  ].media_gross,
                                                              )
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
                                                    fieldErrors={fieldErrors}
                                                    breakdown={
                                                        displayTotals
                                                            ?.positions[index]
                                                            ?.position_discounts
                                                    }
                                                    onChange={(
                                                        position_discounts,
                                                    ) =>
                                                        updatePosition(index, {
                                                            position_discounts,
                                                        })
                                                    }
                                                />
                                                {displayTotals?.positions[index]
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
                                                <input
                                                    id="ae-enabled"
                                                    data-test="ae-enabled"
                                                    type="checkbox"
                                                    className="mt-1 size-4"
                                                    checked={aeEnabled}
                                                    disabled={!canEdit}
                                                    onChange={(event) =>
                                                        setAeEnabled(
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                <span>
                                                    <span className="font-medium">
                                                        15 % AE berücksichtigen
                                                    </span>
                                                    <span className="text-muted-foreground mt-1 block text-xs">
                                                        AE wird nach allen
                                                        Positions- und
                                                        Auftragsrabatten nur auf
                                                        den AE-fähigen Anteil
                                                        angewendet.
                                                        {displayTotals?.ae_eligible_base
                                                            ? ` Berechnungsbasis ${money(displayTotals.ae_eligible_base)}.`
                                                            : ''}
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </CardContent>
                                </Card>
                                {planningMode === 'budget' && canEdit ? (
                                    <Card className={wizardCardClass}>
                                        <CardHeader
                                            className={wizardCardHeaderClass}
                                        >
                                            <CardTitle
                                                className={wizardCardTitleClass}
                                            >
                                                Budgetvorschlag
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent
                                            className={`${wizardCardContentClass} space-y-3`}
                                        >
                                            <p className="text-muted-foreground text-sm">
                                                Konditionen und Verteilungslogik
                                                müssen vor der
                                                Vorschlagsberechnung feststehen.
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    data-test="budget-propose"
                                                    onClick={() =>
                                                        void createProposal()
                                                    }
                                                    disabled={busy}
                                                >
                                                    Vorschlag erzeugen
                                                </Button>
                                                {proposal ? (
                                                    <>
                                                        <Button
                                                            type="button"
                                                            variant="secondary"
                                                            data-test="budget-apply"
                                                            onClick={
                                                                takeProposal
                                                            }
                                                            disabled={busy}
                                                        >
                                                            Vorschlag übernehmen
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setProposal(
                                                                    null,
                                                                )
                                                            }
                                                        >
                                                            Vorschlag verwerfen
                                                        </Button>
                                                    </>
                                                ) : null}
                                            </div>
                                            {proposal ? (
                                                <StatusBanner>
                                                    {proposal.explanation}{' '}
                                                    Verbrauch{' '}
                                                    {money(proposal.used_nn)},
                                                    Rest{' '}
                                                    {money(proposal.remainder)}.
                                                    Anzeigen oder Verwerfen
                                                    ändert die Kalkulation
                                                    nicht.
                                                </StatusBanner>
                                            ) : null}
                                        </CardContent>
                                    </Card>
                                ) : null}
                            </div>
                        ) : null}

                        {step === 3 ? (
                            <Card className={wizardCardClass}>
                                <CardHeader className={wizardCardHeaderClass}>
                                    <CardTitle className={wizardCardTitleClass}>
                                        Zusammenfassung
                                    </CardTitle>
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
                                                            ).map((range) => (
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
                                                            ))}
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
                                                    Die persönliche Rabattgrenze
                                                    ist überschritten.
                                                </StatusBanner>
                                            ) : null}
                                            {positions.map(
                                                (position, index) => {
                                                    const result =
                                                        displayTotals.positions[
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
                                                                        {
                                                                            discount.percent
                                                                        }{' '}
                                                                        % ·{' '}
                                                                        {money(
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
                        ) : null}
                    </div>

                    <aside className="order-2 min-w-0 lg:sticky lg:top-6 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:self-start">
                        <CalculationSummaryPanel
                            totals={summaryTotals}
                            loading={canEdit && !summaryTotals && !error}
                            positions={positions}
                            inventories={catalog.inventories}
                        />
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
                        {step < STEPS.length - 1 ? (
                            <Button
                                type="button"
                                onClick={() => setStep(step + 1)}
                            >
                                Weiter
                            </Button>
                        ) : null}
                        {canEdit ? (
                            <Button
                                type="button"
                                onClick={save}
                                disabled={busy}
                                variant={
                                    step === STEPS.length - 1
                                        ? 'default'
                                        : 'secondary'
                                }
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
