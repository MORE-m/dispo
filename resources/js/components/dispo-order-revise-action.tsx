import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';

export function DispoOrderReviseAction({ orderId }: { orderId: number }) {
    const [submitting, setSubmitting] = useState(false);
    const inFlight = useRef(false);

    return (
        <div className="space-y-3" data-test="dispo-order-revise-panel">
            <p className="text-muted-foreground text-sm">
                Der abgelehnte Auftrag bleibt als Historie erhalten. Bearbeitet
                wird die zugrunde liegende Kalkulation. Danach wird ein neuer
                Dispoauftrag erstellt und erneut freigegeben.
            </p>
            <Button
                type="button"
                data-test="dispo-order-revise-open"
                disabled={submitting}
                onClick={() => {
                    if (inFlight.current) {
                        return;
                    }

                    inFlight.current = true;
                    setSubmitting(true);
                    flushDispoOrderInertiaCache(orderId);
                    router.post(
                        `/dispoauftraege/${orderId}/nachbessern`,
                        {},
                        {
                            invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
                            onFinish: () => {
                                inFlight.current = false;
                                setSubmitting(false);
                            },
                        },
                    );
                }}
            >
                {submitting ? 'Wird geöffnet…' : 'Nachbessern'}
            </Button>
        </div>
    );
}
