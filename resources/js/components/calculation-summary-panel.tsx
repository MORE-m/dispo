import { money } from '@/components/form-field';
import { LogoSlot } from '@/components/logo-slot';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type SummaryPosition = {
    inventory_name?: string;
    spot_count?: number;
    total_spot_count?: number;
    length_seconds?: number;
    media_gross?: string;
    nn_invest?: string;
};

type SummaryTotals = {
    media_gross: string;
    position_discount_total: string;
    order_discount_total: string;
    ae_total: string;
    nn_invest: string;
    target_budget_nn?: string | null;
    budget_delta?: string | null;
    requires_special_approval?: boolean;
    positions?: SummaryPosition[];
};

type InventoryRef = {
    id: number;
    name: string;
    logo_path?: string | null;
};

export function CalculationSummaryPanel({
    totals,
    loading,
    positions,
    inventories,
    className,
}: {
    totals: SummaryTotals | null;
    loading?: boolean;
    positions: {
        inventory_id: number;
        total_spot_count: number;
        length_seconds: number;
    }[];
    inventories: InventoryRef[];
    className?: string;
}) {
    const hasPositions = positions.some((p) => p.total_spot_count > 0);
    const showLoading = Boolean(loading && !totals && hasPositions);

    return (
        <Card
            className={cn(
                'border-border/70 gap-0 overflow-hidden rounded-xl py-0 shadow-xs',
                className,
            )}
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

                                return (
                                    <li
                                        key={`${position.inventory_id}-${index}`}
                                        className="border-border/60 bg-muted/15 flex items-start gap-3 rounded-lg border p-3"
                                    >
                                        <LogoSlot
                                            name={inventory?.name ?? 'Sender'}
                                            logoPath={inventory?.logo_path}
                                            className="size-8 shrink-0"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {inventory?.name ?? 'Sender'}
                                            </p>
                                            {position.total_spot_count > 0 ? (
                                                <p className="text-muted-foreground text-xs">
                                                    {position.total_spot_count}{' '}
                                                    Spots ·{' '}
                                                    {position.length_seconds}s
                                                </p>
                                            ) : (
                                                <p className="text-muted-foreground text-xs">
                                                    Noch keine Menge
                                                </p>
                                            )}
                                        </div>
                                        {total?.nn_invest ? (
                                            <span className="shrink-0 text-sm font-medium tabular-nums">
                                                {money(total.nn_invest)}
                                            </span>
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
                        <p className="text-muted-foreground animate-pulse text-sm">
                            Berechnet …
                        </p>
                    ) : totals ? (
                        <dl className="space-y-2 text-sm">
                            <SummaryRow
                                label="Brutto"
                                value={money(totals.media_gross)}
                            />
                            <SummaryRow
                                label="Positionsrabatt"
                                value={money(totals.position_discount_total)}
                                muted
                            />
                            <SummaryRow
                                label="Auftragsrabatt"
                                value={money(totals.order_discount_total)}
                                muted
                            />
                            <SummaryRow
                                label="AE"
                                value={money(totals.ae_total)}
                                muted
                            />
                            <div className="border-border/60 border-t pt-3">
                                <SummaryRow
                                    label="Netto (N/N)"
                                    value={money(totals.nn_invest)}
                                    emphasis
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
}: {
    label: string;
    value: string;
    emphasis?: boolean;
    muted?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
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
