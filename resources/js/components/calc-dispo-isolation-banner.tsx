import { Link } from '@inertiajs/react';

export type LinkedDispoOrdersProp = {
    count: number;
    orders: { number: string; show_url: string }[];
};

/**
 * PO-CALC-DISPO-HINT-1 / DSP-003: erklärt Snapshot-Isolation Calc ↔ Dispo im Wizard.
 * Keine Sperre, kein Sync-Versprechen, keine DB-IDs.
 */
export function CalcDispoIsolationBanner({
    linked,
}: {
    linked: LinkedDispoOrdersProp;
}) {
    if (linked.count < 1) {
        return null;
    }

    const plural = linked.count > 1;

    return (
        <aside
            className="border-border/70 bg-muted/30 rounded-xl border px-5 py-4 text-sm"
            data-test="calc-dispo-isolation-banner"
        >
            <p data-test="calc-dispo-isolation-message">
                {plural
                    ? 'Aus dieser Kalkulation wurden bereits Dispoaufträge erstellt. Änderungen an der Kalkulation ändern die bestehenden Dispoaufträge nicht.'
                    : 'Aus dieser Kalkulation wurde bereits ein Dispoauftrag erstellt. Änderungen an der Kalkulation ändern den bestehenden Dispoauftrag nicht.'}
            </p>
            {linked.orders.length > 0 ? (
                <p className="mt-3" data-test="calc-dispo-isolation-links">
                    <span className="text-muted-foreground">
                        {plural ? 'Dispoaufträge: ' : 'Dispoauftrag: '}
                    </span>
                    {linked.orders.map((order, index) => (
                        <span key={order.number}>
                            {index > 0 ? (
                                <span className="text-muted-foreground">
                                    ,{' '}
                                </span>
                            ) : null}
                            <Link
                                href={order.show_url}
                                className="font-medium underline-offset-4 hover:underline"
                                data-test={`calc-dispo-isolation-link-${order.number}`}
                            >
                                {order.number}
                            </Link>
                        </span>
                    ))}
                </p>
            ) : null}
        </aside>
    );
}
