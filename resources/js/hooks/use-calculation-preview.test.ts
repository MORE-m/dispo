import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { JsonPostError } from '@/lib/json-post';
import { useCalculationPreview } from '@/hooks/use-calculation-preview';

const jsonPost = vi.hoisted(() => vi.fn());

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();
    return {
        ...actual,
        jsonPost,
    };
});

const payload = { positions: [{ spot_count: 10 }] };
const totals = { media_gross: '300.00', nn_invest: '300.00', positions: [] };

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

async function flushPreview(debounceMs = 280) {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(debounceMs);
    });
    await act(async () => {
        await Promise.resolve();
    });
}

describe('useCalculationPreview', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        jsonPost.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('beendet den Loading-Zustand nach erfolgreicher Vorschau', async () => {
        jsonPost.mockResolvedValue({ totals });

        const { result } = renderHook(() =>
            useCalculationPreview({
                url: '/kalkulationen/vorschau',
                payload,
                enabled: true,
                blocked: false,
            }),
        );

        await flushPreview();

        expect(result.current.previewLoading).toBe(false);
        expect(result.current.totals).toEqual(totals);
        expect(result.current.error).toBeNull();
    });

    it('berechnet nach schnellen Änderungen den neuesten Zustand', async () => {
        const first = deferred<{ totals: typeof totals }>();
        const second = deferred<{ totals: typeof totals }>();

        jsonPost
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);

        const { result, rerender } = renderHook(
            ({ currentPayload }) =>
                useCalculationPreview({
                    url: '/kalkulationen/vorschau',
                    payload: currentPayload,
                    enabled: true,
                    blocked: false,
                }),
            { initialProps: { currentPayload: { version: 1, ...payload } } },
        );

        await flushPreview();

        rerender({ currentPayload: { version: 2, ...payload } });
        await flushPreview();

        await act(async () => {
            first.resolve({
                totals: {
                    media_gross: '100.00',
                    nn_invest: '100.00',
                    positions: [],
                },
            });
            await Promise.resolve();
        });

        await act(async () => {
            second.resolve({ totals });
            await Promise.resolve();
        });

        expect(result.current.totals).toEqual(totals);
    });

    it('verwirft veraltete Antworten zugunsten einer neueren Berechnung', async () => {
        const first = deferred<{ totals: typeof totals }>();
        const second = deferred<{ totals: typeof totals }>();

        jsonPost
            .mockImplementationOnce(() => first.promise)
            .mockImplementationOnce(() => second.promise);

        const { result, rerender } = renderHook(
            ({ currentPayload }) =>
                useCalculationPreview({
                    url: '/kalkulationen/vorschau',
                    payload: currentPayload,
                    enabled: true,
                    blocked: false,
                }),
            { initialProps: { currentPayload: { version: 1, ...payload } } },
        );

        await flushPreview();
        rerender({ currentPayload: { version: 2, ...payload } });
        await flushPreview();

        await act(async () => {
            second.resolve({ totals });
            await Promise.resolve();
        });

        await act(async () => {
            first.resolve({
                totals: {
                    media_gross: '999.00',
                    nn_invest: '999.00',
                    positions: [],
                },
            });
            await Promise.resolve();
        });

        expect(result.current.totals).toEqual(totals);
    });

    it('berechnet nach blockiertem Zustand die letzte Eingabe erneut', async () => {
        jsonPost.mockResolvedValue({ totals });

        const { result, rerender } = renderHook(
            ({ blocked, currentPayload }) =>
                useCalculationPreview({
                    url: '/kalkulationen/vorschau',
                    payload: currentPayload,
                    enabled: true,
                    blocked,
                }),
            {
                initialProps: {
                    blocked: true,
                    currentPayload: { version: 1, ...payload },
                },
            },
        );

        rerender({
            blocked: true,
            currentPayload: { version: 2, ...payload },
        });

        rerender({
            blocked: false,
            currentPayload: { version: 2, ...payload },
        });

        await flushPreview();

        expect(result.current.totals).toEqual(totals);
        expect(jsonPost).toHaveBeenCalledTimes(1);
        expect(jsonPost.mock.calls[0]?.[1]).toEqual({
            version: 2,
            ...payload,
        });
    });

    it('beendet den Spinner bei Backendfehlern und zeigt die Meldung', async () => {
        jsonPost.mockRejectedValue(
            new JsonPostError('Fehlerhafte Eingabe', {
                positions: ['Ungültig'],
            }),
        );

        const { result } = renderHook(() =>
            useCalculationPreview({
                url: '/kalkulationen/vorschau',
                payload,
                enabled: true,
                blocked: false,
            }),
        );

        await flushPreview();

        expect(result.current.previewLoading).toBe(false);
        expect(result.current.totals).toBeNull();
        expect(result.current.error).toBe('Fehlerhafte Eingabe');
        expect(result.current.fieldErrors.positions).toEqual(['Ungültig']);
    });

    it('zeigt fehlende Preise als konkrete Fehlermeldung', async () => {
        jsonPost.mockRejectedValue(
            new JsonPostError(
                'Für Radio Hamburg fehlen Preise für Mo–Fr in den Stunden 09:00.',
                {
                    positions: [
                        'Für Radio Hamburg fehlen Preise für Mo–Fr in den Stunden 09:00.',
                    ],
                },
            ),
        );

        const { result } = renderHook(() =>
            useCalculationPreview({
                url: '/kalkulationen/vorschau',
                payload,
                enabled: true,
                blocked: false,
            }),
        );

        await flushPreview();

        expect(result.current.previewLoading).toBe(false);
        expect(result.current.error).toContain('Radio Hamburg');
        expect(result.current.error).toContain('09:00');
    });
});
