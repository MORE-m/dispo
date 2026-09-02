import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { DispoOrderStatusBadge } from '@/components/dispo-order-status-badge';
import { SuccessState } from '@/components/feedback/states';
import { formatPercent, money } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { formatHour, formatInclusiveEnd } from '@/lib/pricing-time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
};

type OrderDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
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
    order_discounts: {
        type?: string;
        custom_label?: string | null;
        percent?: string;
    }[];
    creator_name: string | null;
    created_at: string | null;
    positions: OrderPosition[];
};

function formatDate(iso: string | null): string {
    if (!iso) {
        return '–';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(iso));
}

export default function DispoOrderShow({
    order,
    canViewCalculation,
}: {
    order: OrderDetail;
    canViewCalculation: boolean;
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title={`Dispoauftrag ${order.number}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={order.number}
                    description="Dispoauftrag (Snapshot, schreibgeschützt)"
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
                            value={formatDate(order.created_at)}
                        />
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
                            <p className="text-primary pt-2 text-xs font-medium">
                                Sonderfreigabe erforderlich
                            </p>
                        ) : null}
                    </CardContent>
                </Card>

                <Card className="border-border/70 rounded-xl shadow-xs">
                    <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                        <CardTitle className="text-sm font-semibold">
                            Positionen ({order.positions.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 px-5 py-4">
                        {order.positions.map((position, index) => (
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
                                {position.time_ranges.length > 0 ? (
                                    <ul className="text-muted-foreground mt-2 space-y-0.5 text-xs">
                                        {position.time_ranges.map((range) => (
                                            <li
                                                key={`${range.start_hour}-${range.end_hour_exclusive}-${range.day_group}`}
                                            >
                                                {formatHour(range.start_hour)}–
                                                {formatInclusiveEnd(
                                                    range.end_hour_exclusive,
                                                )}{' '}
                                                · {range.spot_count} Spots
                                                {range.range_gross
                                                    ? ` · ${money(range.range_gross)}`
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
                                <p className="text-primary mt-2 text-sm font-semibold tabular-nums">
                                    {money(position.nn_invest)} N/N
                                </p>
                            </section>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
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
