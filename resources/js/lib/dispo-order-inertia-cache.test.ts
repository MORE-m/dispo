import { beforeEach, describe, expect, it, vi } from 'vitest';

const flushByCacheTags = vi.fn();
const flush = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        flushByCacheTags,
        flush,
    },
}));

describe('flushDispoOrderInertiaCache', () => {
    beforeEach(() => {
        flushByCacheTags.mockReset();
        flush.mockReset();
    });

    it('flushes list cache tag and index URL', async () => {
        const { flushDispoOrderInertiaCache, DISPO_ORDERS_CACHE_TAG } =
            await import('@/lib/dispo-order-inertia-cache');

        flushDispoOrderInertiaCache();

        expect(flushByCacheTags).toHaveBeenCalledWith(DISPO_ORDERS_CACHE_TAG);
        expect(flush).toHaveBeenCalledWith('/dispoauftraege');
        expect(flush).toHaveBeenCalledTimes(1);
    });

    it('also flushes the detail URL when an order id is given', async () => {
        const { flushDispoOrderInertiaCache } = await import(
            '@/lib/dispo-order-inertia-cache'
        );

        flushDispoOrderInertiaCache(42);

        expect(flush).toHaveBeenCalledWith('/dispoauftraege');
        expect(flush).toHaveBeenCalledWith('/dispoauftraege/42');
    });
});
