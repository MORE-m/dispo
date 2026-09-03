import { router } from '@inertiajs/react';

/** Cache-Tag für veränderliche Dispoauftrags-Listen-/Detail-Prefetches. */
export const DISPO_ORDERS_CACHE_TAG = 'dispo-orders';

/**
 * Invalidiert vorab gecachte Inertia-Antworten für Dispoaufträge
 * (Sidebar-Prefetch), damit Status nach Mutationen aktuell bleibt.
 */
export function flushDispoOrderInertiaCache(orderId?: number): void {
    router.flushByCacheTags(DISPO_ORDERS_CACHE_TAG);
    router.flush('/dispoauftraege');

    if (orderId !== undefined) {
        router.flush(`/dispoauftraege/${orderId}`);
    }
}
