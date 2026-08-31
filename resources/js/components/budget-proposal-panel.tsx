import { useState } from 'react';
import { money } from '@/components/form-field';
import { LogoSlot } from '@/components/logo-slot';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type ProposalBucket = {
    day_group: string;
    hour: number;
    hour_label: string;
    spot_count: number;
    second_price: string;
    range_gross: string;
};

type ProposalPosition = {
    inventory_id: number;
    inventory_name: string;
    length_seconds?: number;
    total_spot_count: number;
    media_gross: string;
    position_discount_amount: string;
    nn_invest: string;
    buckets: ProposalBucket[];
    time_ranges?: Array<{
        start_hour: number;
        end_hour_exclusive: number;
        day_group: string;
        spot_count: number;
    }>;
};

export type BudgetSpotProposal = {
    id?: number;
    lock_version?: number;
    strategy: string;
    status?: string;
    target_budget_nn: string;
    used_nn: string;
    nn_invest: string;
    remainder: string;
    spots_per_sender?: number;
    total_spots?: number;
    budget_utilization_percent?: string;
    next_package_cost_nn?: string;
    next_package_exceeds_budget?: boolean;
    next_package_shortfall?: string;
    insufficient_budget?: boolean;
    minimum_budget_nn?: string | null;
    budget_shortfall?: string | null;
    input_fingerprint?: string;
    explanation: string;
    positions: ProposalPosition[];
};

const STATUS_LABELS: Record<string, string> = {
    current: 'Aktuell',
    stale: 'Neuoptimierung erforderlich',
    manual: 'Manuell angepasst',
};

export function BudgetProposalPanel({
    proposal,
    proposalStatus,
    busy,
    canApply,
    onApply,
    onDiscard,
    onRecalculate,
}: {
    proposal: BudgetSpotProposal;
    proposalStatus?: string | null;
    busy: boolean;
    canApply: boolean;
    onApply: () => void;
    onDiscard: () => void;
    onRecalculate: () => void;
}) {
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const status = proposalStatus ?? proposal.status ?? 'current';
    const statusLabel = STATUS_LABELS[status] ?? status;

    return (
        <div className="space-y-4" data-test="budget-proposal-result">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Metric label="Zielbudget N/N" value={money(proposal.target_budget_nn)} />
                <Metric label="Vorgeschlagener N/N" value={money(proposal.used_nn)} />
                <Metric label="Restbudget" value={money(proposal.remainder)} />
                <Metric
                    label="Spots je Sender"
                    value={String(proposal.spots_per_sender ?? 0)}
                />
                <Metric
                    label="Gesamtspots"
                    value={String(proposal.total_spots ?? 0)}
                />
                {proposal.next_package_cost_nn ? (
                    <Metric
                        label="Nächstes Spotpaket"
                        value={money(proposal.next_package_cost_nn)}
                    />
                ) : null}
            </div>

            {proposal.insufficient_budget ? (
                <p className="text-destructive text-sm" data-test="budget-insufficient">
                    Für mindestens einen Spot auf jedem Wunschsender werden{' '}
                    {money(proposal.minimum_budget_nn ?? '0')} N/N benötigt. Das
                    Zielbudget liegt{' '}
                    {money(proposal.budget_shortfall ?? '0')} darunter.
                </p>
            ) : null}

            <p className="text-muted-foreground text-sm">{proposal.explanation}</p>

            <p className="text-sm">
                Status:{' '}
                <span className="font-medium" data-test="budget-proposal-status">
                    {statusLabel}
                </span>
            </p>

            <div className="space-y-3">
                {proposal.positions.map((position, index) => (
                    <div
                        key={position.inventory_id}
                        className="border-border/60 rounded-lg border p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-3">
                                <LogoSlot
                                    name={position.inventory_name}
                                    variant="card"
                                />
                                <div>
                                    <p className="font-medium">
                                        {position.inventory_name}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {position.total_spot_count} Spots · N/N{' '}
                                        {money(position.nn_invest)}
                                    </p>
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    setExpanded((current) => ({
                                        ...current,
                                        [index]: !current[index],
                                    }))
                                }
                            >
                                {expanded[index]
                                    ? 'Stunden schließen'
                                    : 'Stundenverteilung'}
                            </Button>
                        </div>
                        {expanded[index] ? (
                            <ul className="mt-3 space-y-1 text-sm">
                                {position.buckets.map((bucket) => (
                                    <li
                                        key={`${bucket.day_group}-${bucket.hour}`}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            {bucket.hour_label} ·{' '}
                                            {bucket.day_group}
                                        </span>
                                        <span className="tabular-nums">
                                            {bucket.spot_count} ×{' '}
                                            {money(bucket.range_gross)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap gap-2">
                {status === 'stale' ? (
                    <Button
                        type="button"
                        data-test="budget-recalculate"
                        onClick={onRecalculate}
                        disabled={busy}
                    >
                        Vorschlag neu berechnen
                    </Button>
                ) : null}
                <Button
                    type="button"
                    data-test="budget-apply"
                    onClick={onApply}
                    disabled={busy || !canApply || status === 'stale'}
                >
                    Vorschlag übernehmen
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDiscard}
                    disabled={busy}
                >
                    Vorschlag verwerfen
                </Button>
            </div>
        </div>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-border/60 bg-muted/15 rounded-lg border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="text-base font-semibold tabular-nums">{value}</p>
        </div>
    );
}

export function BudgetWishSenders({
    inventories,
    selectedIds,
    canEdit,
    onChange,
}: {
    inventories: Array<{
        id: number;
        name: string;
        logo_path?: string | null;
        is_active: boolean;
    }>;
    selectedIds: number[];
    canEdit: boolean;
    onChange: (ids: number[]) => void;
}) {
    return (
        <div className="space-y-3" data-test="budget-wish-senders">
            <p className="text-sm font-medium">Wunschsender</p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {inventories.map((item) => {
                    const selected = selectedIds.includes(item.id);
                    const disabled = !canEdit || !item.is_active;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled}
                            aria-pressed={selected}
                            onClick={() => {
                                if (selected) {
                                    onChange(
                                        selectedIds.filter((id) => id !== item.id),
                                    );
                                } else {
                                    onChange([...selectedIds, item.id]);
                                }
                            }}
                            className={cn(
                                'focus-visible:ring-primary/25 relative flex w-full flex-col gap-2 rounded-xl border-2 p-4 text-left transition-all outline-none focus-visible:ring-[3px]',
                                selected
                                    ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                                    : 'border-border/80 bg-card hover:border-primary/45',
                                disabled && 'cursor-not-allowed opacity-50',
                            )}
                        >
                            <LogoSlot name={item.name} logoPath={item.logo_path} variant="card" />
                            <span className="text-sm font-semibold">{item.name}</span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
