import { formatPercent, money, moneyDeduction } from '@/components/form-field';
import { LogoSlot } from '@/components/logo-slot';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import { cn } from '@/lib/utils';

type SummaryRange = {
    start_hour: number;
    end_hour_exclusive: number;
    day_group?: string;
    spot_count: number;
    range_gross?: string | null;
};

type SummaryDiscount = {
    label?: string;
    percent?: string;
    amount?: string;
};

type SummaryPosition = {
    inventory_name?: string;
    spot_count?: number;
    total_spot_count?: number;
    length_seconds?: number;
    media_gross?: string;
    after_position_discount?: string;
    nn_invest?: string;
    time_ranges?: SummaryRange[];
    position_discounts?: SummaryDiscount[];
};

type SummaryTotals = {
    media_gross: string;
    position_discount_total: string;
    order_discount_total: string;
    ae_total: string;
    nn_invest: string;
    after_position_discount_total?: string;
    after_order_discount_total?: string;
    target_budget_nn?: string | null;
    budget_delta?: string | null;
    requires_special_approval?: boolean;
    order_discounts?: SummaryDiscount[];
    positions?: SummaryPosition[];
};

type InventoryRef = {
    id: number;
    name: string;
    logo_path?: string | null;
};

function hasAmount(value: string | undefined): boolean {
    return Number(value ?? 0) !== 0;
}

export function CalculationSummaryPanel({
    totals,
    loading,
    aeEnabled = false,
    positions,
    inventories,
    className,
}: {
    totals: SummaryTotals | null;
    loading?: boolean;
    aeEnabled?: boolean;
    positions: {
        inventory_id: number;
        inventory_name?: string | null;
        total_spot_count: number;
        length_seconds: number;
    }[];
    inventories: InventoryRef[];
    className?: string;
}) {
    const hasPositions = positions.some((p) => p.total_spot_count > 0);
    const showLoading = Boolean(loading);
    const showAe = aeEnabled || Number(totals?.ae_total ?? 0) > 0;
    const orderDiscounts = totals?.order_discounts ?? [];
    const hasOrderDiscounts =
        orderDiscounts.length > 0 || hasAmount(totals?.order_discount_total);

    return (
        <Card
            className={cn(
                'border-border/70 gap-0 overflow-hidden rounded-xl py-0 shadow-xs',
                className,
            )}
            data-test="calculation-summary"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold tracking-tight">
                    Kalkulation
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {positions.length > 0 ? (
                    <div className="space-y-2.5">
                        <p className="text-muted-foreground text-[11px] font-semibold tracking-wide uppercase">
                            Positionen
                        </p>
                        <ul className="space-y-2">
                            {positions.map((position, index) => {
                                const inventory = inventories.find(
                                    (item) => item.id === position.inventory_id,
                                );
                                const total =
                                    totals?.positions?.[index] ?? null;
                                const displayName =
                                    (position.inventory_name ?? '').trim() ||
                                    (total?.inventory_name ?? '').trim() ||
                                    inventory?.name ||
                                    'Sender';
                                const ranges = total?.time_ranges ?? [];
                                const discounts =
                                    total?.position_discounts ?? [];
                                const positionTotal =
                                    total?.after_position_discount ??
                                    total?.media_gross ??
                                    null;

                                return (
                                    <li
                                        key={`${position.inventory_id}-${index}`}
                                        className="border-border/60 bg-muted/15 rounded-lg border p-3"
                                        data-test={`summary-position-${index}`}
                                    >
                                        <div className="flex items-start gap-3">
                                            <LogoSlot
                                                name={displayName}
                                                logoPath={inventory?.logo_path}
                                                className="size-8 shrink-0"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {displayName}
                                                </p>
                                                {position.total_spot_count >
                                                0 ? (
                                                    <p className="text-muted-foreground text-xs">
                                                        {
                                                            position.total_spot_count
                                                        }{' '}
                                                        Spots ·{' '}
                                                        {
                                                            position.length_seconds
                                                        }
                                                        s
                                                    </p>
                                                ) : (
                                                    <p className="text-muted-foreground text-xs">
                                                        Noch keine Menge
                                                    </p>
                                                )}
                                            </div>
                                            {positionTotal ? (
                                                <span
                                                    className="text-primary shrink-0 text-sm font-semibold tabular-nums"
                                                    data-test={`summary-position-total-${index}`}
                                                >
                                                    {money(positionTotal)}
                                                </span>
                                            ) : null}
                                        </div>
                                        {total?.media_gross &&
                                        discounts.length > 0 ? (
                                            <div className="text-muted-foreground mt-2 space-y-0.5 text-xs">
                                                <p>
                                                    Brutto{' '}
                                                    <span className="text-foreground font-medium">
                                                        {money(
                                                            total.media_gross,
                                                        )}
                                                    </span>
                                                </p>
                                                {discounts.map(
                                                    (
                                                        discount,
                                                        discountIndex,
                                                    ) => (
                                                        <p
                                                            key={`${discount.label}-${discountIndex}`}
                                                        >
                                                            {discount.label}
                                                            {discount.percent
                                                                ? ` ${formatPercent(discount.percent)}`
                                                                : ''}
                                                            {discount.amount
                                                                ? `: ${moneyDeduction(discount.amount)}`
                                                                : ''}
                                                        </p>
                                                    ),
                                                )}
                                                {total.after_position_discount ? (
                                                    <p className="text-foreground font-medium">
                                                        Nach Positionsrabatten:{' '}
                                                        {money(
                                                            total.after_position_discount,
                                                        )}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ) : null}
                                        {ranges.length > 0 ? (
                                            <details className="mt-2">
                                                <summary className="text-muted-foreground cursor-pointer text-xs">
                                                    Zeiträume
                                                </summary>
                                                <ul className="text-muted-foreground mt-1 space-y-0.5 text-xs">
                                                    {ranges.map((range) => (
                                                        <li
                                                            key={`${range.start_hour}-${range.end_hour_exclusive}-${range.day_group}-${range.spot_count}`}
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
                                                    ))}
                                                </ul>
                                            </details>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Noch keine Positionen ausgewählt
                    </p>
                )}

                <div className="border-border/60 border-t pt-4">
                    {showLoading ? (
                        <p
                            className="text-muted-foreground animate-pulse text-sm"
                            data-test="preview-loading"
                        >
                            Berechnet …
                        </p>
                    ) : totals ? (
                        <dl className="space-y-2 text-sm">
                            <SummaryRow
                                label="Brutto gesamt"
                                value={money(totals.media_gross)}
                            />
                            {hasAmount(totals.position_discount_total) ? (
                                <SummaryRow
                                    label="Rabatte Werbeelemente"
                                    value={moneyDeduction(
                                        totals.position_discount_total,
                                    )}
                                    muted
                                />
                            ) : null}
                            {totals.after_position_discount_total ? (
                                <SummaryRow
                                    label="Zwischensumme nach Positionsrabatten"
                                    value={money(
                                        totals.after_position_discount_total,
                                    )}
                                    data-test="summary-after-position-total"
                                />
                            ) : null}
                            {orderDiscounts.map((discount, index) => (
                                <SummaryRow
                                    key={`order-discount-${index}`}
                                    label={
                                        discount.label
                                            ? `${discount.label} ${discount.percent ? formatPercent(discount.percent) : ''}`.trim()
                                            : 'Auftragsrabatt'
                                    }
                                    value={moneyDeduction(
                                        discount.amount ?? '0',
                                    )}
                                    muted
                                    data-test={`summary-order-discount-${index}`}
                                />
                            ))}
                            {hasOrderDiscounts &&
                            totals.after_order_discount_total ? (
                                <SummaryRow
                                    label="Zwischensumme nach Auftragsrabatten"
                                    value={money(
                                        totals.after_order_discount_total,
                                    )}
                                    data-test="summary-after-order-total"
                                />
                            ) : null}
                            {showAe ? (
                                <SummaryRow
                                    label="AE 15 %"
                                    value={moneyDeduction(totals.ae_total)}
                                    muted
                                    data-test="summary-ae-deduction"
                                />
                            ) : null}
                            <div className="border-border/60 border-t pt-3">
                                <SummaryRow
                                    label="Netto (N/N)"
                                    value={money(totals.nn_invest)}
                                    emphasis
                                    data-test="preview-net-total"
                                />
                            </div>
                            {totals.target_budget_nn ? (
                                <SummaryRow
                                    label="Zielbudget"
                                    value={money(totals.target_budget_nn)}
                                    muted
                                />
                            ) : null}
                            {totals.requires_special_approval ? (
                                <p className="text-primary pt-1 text-xs font-medium">
                                    Sonderfreigabe erforderlich
                                </p>
                            ) : null}
                        </dl>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {hasPositions
                                ? 'Summen erscheinen nach Eingabe der Werbeelemente.'
                                : 'Noch keine Summen verfügbar.'}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function SummaryRow({
    label,
    value,
    emphasis,
    muted,
    'data-test': dataTest,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
    muted?: boolean;
    'data-test'?: string;
}) {
    return (
        <div
            className="flex items-baseline justify-between gap-3"
            data-test={dataTest}
        >
            <dt
                className={cn(
                    muted && 'text-muted-foreground text-xs',
                    !muted && !emphasis && 'text-sm',
                    emphasis && 'text-sm font-medium',
                )}
            >
                {label}
            </dt>
            <dd
                className={cn(
                    'tabular-nums',
                    emphasis
                        ? 'text-primary text-lg font-bold'
                        : muted
                          ? 'text-muted-foreground text-xs font-medium'
                          : 'text-sm font-medium',
                )}
            >
                {value}
            </dd>
        </div>
    );
}
