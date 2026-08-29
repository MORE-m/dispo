import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CalculationSummaryPanel } from '@/components/calculation-summary-panel';
import { FormField, money } from '@/components/form-field';
import {
    EmptyState,
    ErrorState,
    LoadingState,
    StatusBanner,
    SuccessState,
} from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { LogoSlot } from '@/components/logo-slot';
import { SelectionCard, SelectionCardGrid } from '@/components/selection-card';
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
    position_discount_percent: string;
    ae_percent: string;
    plan_rows: PlanRow[];
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
    positions: {
        media_gross: string;
        nn_invest: string;
        spot_count: number;
        average_second_price?: string | null;
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
    target_budget_nn: string | null;
    budget_strategy: string | null;
    positions: {
        id: number;
        client_key: string | null;
        inventory_id: number;
        advertising_medium_id: number;
        spot_method: string;
        length_seconds: number;
        total_spot_count: number;
        position_discount_percent: string;
        ae_percent: string;
        plan_rows: PlanRow[];
    }[];
};

const STEPS = [
    'Grunddaten',
    'Werbeelemente',
    'Konditionen',
    'Zusammenfassung',
] as const;

const DAY_GROUPS = [
    { value: 'mo_fr', label: 'Mo–Fr' },
    { value: 'sa', label: 'Sa' },
    { value: 'so', label: 'So' },
    { value: 'mo_sa', label: 'Mo–Sa' },
    { value: 'mo_so', label: 'Mo–So' },
];

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
                ae_percent: medium.is_ae_eligible ? '15' : '0',
                plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
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

export default function CalculationWizard({
    catalog,
    calculation,
    savedSummary,
    canEdit,
}: {
    catalog: Catalog;
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
    const [orderDiscount, setOrderDiscount] = useState(
        calculation?.order_discount_percent ?? '0',
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
                position_discount_percent: String(
                    position.position_discount_percent,
                ),
                ae_percent: String(position.ae_percent),
                plan_rows: position.plan_rows.map((row) => ({
                    hour: row.hour,
                    day_group: row.day_group,
                    second_price: row.second_price,
                })),
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
            order_discount_percent: orderDiscount,
            target_budget_nn: targetBudget === '' ? null : targetBudget,
            budget_strategy: planningMode === 'budget' ? budgetStrategy : null,
            lock_version: calculation?.lock_version,
            calculation_id: calculation?.id,
            positions,
        }),
        [
            planningMode,
            customerName,
            agencyName,
            campaign,
            productTitle,
            briefing,
            orderDiscount,
            targetBudget,
            budgetStrategy,
            calculation,
            positions,
        ],
    );

    useEffect(() => {
        if (!canEdit) {
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
    }, [payload, canEdit]);

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
                        ae_percent: medium.is_ae_eligible ? '15' : '0',
                        plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
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

    function removePlanRow(positionIndex: number, rowIndex: number) {
        setPositions((current) =>
            current.map((position, itemIndex) => {
                if (itemIndex !== positionIndex) {
                    return position;
                }

                const rows = position.plan_rows.filter(
                    (_, index) => index !== rowIndex,
                );

                return {
                    ...position,
                    plan_rows: rows.length ? rows : position.plan_rows,
                };
            }),
        );
    }

    function save() {
        setBusy(true);
        const url = calculation
            ? `/kalkulationen/${calculation.id}`
            : '/kalkulationen';

        router.visit(url, {
            method: calculation ? 'put' : 'post',
            data: payload,
            onFinish: () => setBusy(false),
            onError: (errors) => {
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
        });
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
                    return { ...position, total_spot_count: 0 };
                }

                return {
                    ...position,
                    length_seconds: match.length_seconds,
                    total_spot_count: match.total_spot_count,
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

                <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                    <div className="min-w-0 space-y-6">
                        {step === 0 ? (
                            <div className="space-y-6">
                                <Card className="gap-0 py-0 shadow-xs">
                                    <CardHeader className="border-b py-4">
                                        <CardTitle className="text-base">
                                            Planungsweg
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="py-4">
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
                                                <span className="font-medium">
                                                    Selbst planen
                                                </span>
                                                <span className="text-muted-foreground mt-1 text-sm">
                                                    Sender, Werbeelemente und
                                                    Konditionen manuell
                                                    festlegen.
                                                </span>
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
                                                <span className="font-medium">
                                                    Mit Budget planen
                                                </span>
                                                <span className="text-muted-foreground mt-1 text-sm">
                                                    Zielbudget und
                                                    Verteilungslogik vorgeben.
                                                </span>
                                            </SelectionCard>
                                        </SelectionCardGrid>
                                    </CardContent>
                                </Card>

                                <Card className="gap-0 py-0 shadow-xs">
                                    <CardHeader className="border-b py-4">
                                        <CardTitle className="text-base">
                                            Grunddaten
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 py-4 sm:grid-cols-2">
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
                                                className="border-input focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted/40 min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70 sm:col-span-2"
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
                                                    className="border-input disabled:bg-muted/40 h-9 w-full rounded-md border bg-transparent px-3 text-sm disabled:cursor-not-allowed disabled:opacity-70"
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
                                        const inventory =
                                            catalog.inventories.find(
                                                (item) =>
                                                    item.id ===
                                                    position.inventory_id,
                                            );

                                        return (
                                            <section
                                                key={position.client_key}
                                                aria-labelledby={`pos-${index}`}
                                            >
                                                <Card className="gap-0 py-0 shadow-xs">
                                                    <CardHeader className="border-b py-4">
                                                        <CardTitle
                                                            id={`pos-${index}`}
                                                            className="flex items-center gap-2 text-base"
                                                        >
                                                            <LogoSlot
                                                                name={
                                                                    inventory
                                                                        ? catalogLabel(
                                                                              inventory.name,
                                                                              inventory.is_active,
                                                                          )
                                                                        : 'Sender'
                                                                }
                                                                logoPath={
                                                                    inventory?.logo_path
                                                                }
                                                            />
                                                            Werbeelement{' '}
                                                            {index + 1}
                                                        </CardTitle>
                                                    </CardHeader>
                                                    <CardContent className="space-y-6 py-4">
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
                                                                                    'focus-visible:ring-ring/50 relative flex flex-col items-start rounded-xl border-2 p-4 text-left transition-colors outline-none focus-visible:ring-[3px]',
                                                                                    selected
                                                                                        ? 'border-primary bg-accent/50'
                                                                                        : 'border-border bg-card hover:border-primary/40',
                                                                                    disabled &&
                                                                                        'cursor-not-allowed opacity-50',
                                                                                )}
                                                                            >
                                                                                <LogoSlot
                                                                                    name={catalogLabel(
                                                                                        item.name,
                                                                                        item.is_active,
                                                                                    )}
                                                                                    logoPath={
                                                                                        item.logo_path
                                                                                    }
                                                                                    className="mb-2"
                                                                                />
                                                                                <span className="text-sm font-medium">
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

                                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                            <FormField label="Kalkulationsart">
                                                                <Input
                                                                    readOnly
                                                                    value="Durchschnitt"
                                                                    disabled
                                                                    className="bg-muted/40"
                                                                />
                                                            </FormField>
                                                            <FormField
                                                                label="Spotanzahl gesamt"
                                                                htmlFor={`spots-${index}`}
                                                                error={
                                                                    fieldErrors[
                                                                        `positions.${index}.total_spot_count`
                                                                    ]?.[0]
                                                                }
                                                            >
                                                                <Input
                                                                    id={`spots-${index}`}
                                                                    data-test={`position-total-spots-${index}`}
                                                                    type="number"
                                                                    min={0}
                                                                    step={1}
                                                                    value={
                                                                        position.total_spot_count
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
                                                                                total_spot_count:
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

                                                        <div className="space-y-3">
                                                            <p className="text-sm font-medium">
                                                                Preisstunden
                                                                (Durchschnitt)
                                                            </p>
                                                            <div className="space-y-2">
                                                                {position.plan_rows.map(
                                                                    (
                                                                        row,
                                                                        rowIndex,
                                                                    ) => (
                                                                        <div
                                                                            key={`${row.hour}-${row.day_group}-${rowIndex}`}
                                                                            className="bg-muted/20 flex flex-wrap items-end gap-2 rounded-lg border p-3"
                                                                        >
                                                                            <FormField
                                                                                label="Stunde"
                                                                                htmlFor={`hour-${index}-${rowIndex}`}
                                                                            >
                                                                                <Input
                                                                                    id={`hour-${index}-${rowIndex}`}
                                                                                    type="number"
                                                                                    min={
                                                                                        0
                                                                                    }
                                                                                    max={
                                                                                        23
                                                                                    }
                                                                                    className="w-20"
                                                                                    value={
                                                                                        row.hour
                                                                                    }
                                                                                    disabled={
                                                                                        !canEdit
                                                                                    }
                                                                                    onChange={(
                                                                                        event,
                                                                                    ) => {
                                                                                        const next =
                                                                                            [
                                                                                                ...position.plan_rows,
                                                                                            ];
                                                                                        next[
                                                                                            rowIndex
                                                                                        ] =
                                                                                            {
                                                                                                ...row,
                                                                                                hour: Number(
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                                ),
                                                                                            };
                                                                                        updatePosition(
                                                                                            index,
                                                                                            {
                                                                                                plan_rows:
                                                                                                    next,
                                                                                            },
                                                                                        );
                                                                                    }}
                                                                                />
                                                                            </FormField>
                                                                            <FormField label="Tagesgruppe">
                                                                                <select
                                                                                    className="border-input disabled:bg-muted/40 h-9 rounded-md border bg-transparent px-3 text-sm disabled:cursor-not-allowed disabled:opacity-70"
                                                                                    value={
                                                                                        row.day_group
                                                                                    }
                                                                                    disabled={
                                                                                        !canEdit
                                                                                    }
                                                                                    onChange={(
                                                                                        event,
                                                                                    ) => {
                                                                                        const next =
                                                                                            [
                                                                                                ...position.plan_rows,
                                                                                            ];
                                                                                        next[
                                                                                            rowIndex
                                                                                        ] =
                                                                                            {
                                                                                                ...row,
                                                                                                day_group:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            };
                                                                                        updatePosition(
                                                                                            index,
                                                                                            {
                                                                                                plan_rows:
                                                                                                    next,
                                                                                            },
                                                                                        );
                                                                                    }}
                                                                                >
                                                                                    {DAY_GROUPS.map(
                                                                                        (
                                                                                            group,
                                                                                        ) => (
                                                                                            <option
                                                                                                key={
                                                                                                    group.value
                                                                                                }
                                                                                                value={
                                                                                                    group.value
                                                                                                }
                                                                                            >
                                                                                                {
                                                                                                    group.label
                                                                                                }
                                                                                            </option>
                                                                                        ),
                                                                                    )}
                                                                                </select>
                                                                            </FormField>
                                                                            {canEdit &&
                                                                            position
                                                                                .plan_rows
                                                                                .length >
                                                                                1 ? (
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="outline"
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        removePlanRow(
                                                                                            index,
                                                                                            rowIndex,
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Stunde
                                                                                    entfernen
                                                                                </Button>
                                                                            ) : null}
                                                                        </div>
                                                                    ),
                                                                )}
                                                                {canEdit ? (
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="sm"
                                                                        onClick={() =>
                                                                            updatePosition(
                                                                                index,
                                                                                {
                                                                                    plan_rows:
                                                                                        [
                                                                                            ...position.plan_rows,
                                                                                            {
                                                                                                hour: 9,
                                                                                                day_group:
                                                                                                    'mo_fr',
                                                                                            },
                                                                                        ],
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        Preisstunde
                                                                        hinzufügen
                                                                    </Button>
                                                                ) : null}
                                                            </div>
                                                        </div>

                                                        {displayTotals
                                                            ?.positions[
                                                            index
                                                        ] ? (
                                                            <p className="text-muted-foreground text-sm">
                                                                Ø-Sekundenpreis{' '}
                                                                {displayTotals
                                                                    .positions[
                                                                    index
                                                                ]
                                                                    .average_second_price ??
                                                                    '–'}{' '}
                                                                ·{' '}
                                                                {money(
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ]
                                                                        .media_gross,
                                                                )}{' '}
                                                                Brutto ·{' '}
                                                                {money(
                                                                    displayTotals
                                                                        .positions[
                                                                        index
                                                                    ].nn_invest,
                                                                )}{' '}
                                                                N/N
                                                            </p>
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
                                            className="gap-0 py-0 shadow-xs"
                                        >
                                            <CardHeader className="border-b py-4">
                                                <CardTitle className="flex items-center gap-2 text-base">
                                                    <LogoSlot
                                                        name={
                                                            inventory?.name ??
                                                            'Sender'
                                                        }
                                                        logoPath={
                                                            inventory?.logo_path
                                                        }
                                                    />
                                                    {inventory?.name}
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid gap-4 py-4 sm:grid-cols-2">
                                                <FormField
                                                    label="Positionsrabatt %"
                                                    error={
                                                        fieldErrors[
                                                            `positions.${index}.position_discount_percent`
                                                        ]?.[0]
                                                    }
                                                >
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        max={100}
                                                        value={
                                                            position.position_discount_percent
                                                        }
                                                        disabled={
                                                            !canEdit ||
                                                            rule?.is_discountable ===
                                                                false
                                                        }
                                                        className={
                                                            rule?.is_discountable ===
                                                            false
                                                                ? 'bg-muted/40'
                                                                : undefined
                                                        }
                                                        onChange={(event) =>
                                                            updatePosition(
                                                                index,
                                                                {
                                                                    position_discount_percent:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </FormField>
                                                <FormField label="AE %">
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        max={100}
                                                        value={
                                                            position.ae_percent
                                                        }
                                                        disabled={
                                                            !canEdit ||
                                                            rule?.is_ae_eligible ===
                                                                false
                                                        }
                                                        className={
                                                            rule?.is_ae_eligible ===
                                                            false
                                                                ? 'bg-muted/40'
                                                                : undefined
                                                        }
                                                        onChange={(event) =>
                                                            updatePosition(
                                                                index,
                                                                {
                                                                    ae_percent:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </FormField>
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                                <Card className="gap-0 py-0 shadow-xs">
                                    <CardHeader className="border-b py-4">
                                        <CardTitle className="text-base">
                                            Auftragskonditionen
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="py-4">
                                        <FormField
                                            label="Zusätzlicher Auftragsrabatt %"
                                            htmlFor="order-discount"
                                        >
                                            <Input
                                                id="order-discount"
                                                type="number"
                                                min={0}
                                                max={100}
                                                value={orderDiscount}
                                                disabled={!canEdit}
                                                onChange={(event) =>
                                                    setOrderDiscount(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </FormField>
                                    </CardContent>
                                </Card>
                                {planningMode === 'budget' && canEdit ? (
                                    <Card className="gap-0 py-0 shadow-xs">
                                        <CardHeader className="border-b py-4">
                                            <CardTitle className="text-base">
                                                Budgetvorschlag
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-3 py-4">
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
                            <Card className="gap-0 py-0 shadow-xs">
                                <CardHeader className="border-b py-4">
                                    <CardTitle className="text-base">
                                        Zusammenfassung
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 py-4 text-sm">
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
                                                    label="Positionsrabatte"
                                                    value={money(
                                                        summary.position_discount_total,
                                                    )}
                                                />
                                                <SummaryDetail
                                                    label="Auftragsrabatt"
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
                                                            {position.plan_rows.map(
                                                                (row) => (
                                                                    <li
                                                                        key={`${row.hour}-${row.day_group}`}
                                                                    >
                                                                        Stunde{' '}
                                                                        {
                                                                            row.hour
                                                                        }
                                                                        ,{' '}
                                                                        {
                                                                            row.day_group
                                                                        }
                                                                        ,{' '}
                                                                        {
                                                                            row.second_price
                                                                        }{' '}
                                                                        €/s
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
                                                    label="Positionsrabatte"
                                                    value={money(
                                                        displayTotals.position_discount_total,
                                                    )}
                                                />
                                                <SummaryDetail
                                                    label="Auftragsrabatt"
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
                                        </>
                                    ) : (
                                        <LoadingState />
                                    )}
                                </CardContent>
                            </Card>
                        ) : null}

                        <div className="border-border flex flex-wrap gap-3 border-t pt-6">
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

                    <aside className="min-w-0 lg:sticky lg:top-6 lg:self-start">
                        <CalculationSummaryPanel
                            totals={summaryTotals}
                            loading={canEdit && !summaryTotals && !error}
                            positions={positions}
                            inventories={catalog.inventories}
                        />
                    </aside>
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
