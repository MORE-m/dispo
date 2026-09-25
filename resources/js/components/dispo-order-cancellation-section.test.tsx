import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCancellationSection } from '@/components/dispo-order-cancellation-section';

const mockVisit = vi.fn();
const mockJsonPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (...args: unknown[]) => mockVisit(...args),
        flushByCacheTags: vi.fn(),
        flush: vi.fn(),
    },
}));

vi.mock('@/lib/dispo-order-inertia-cache', async (importOriginal) => {
    const actual =
        await importOriginal<typeof import('@/lib/dispo-order-inertia-cache')>();

    return {
        ...actual,
        flushDispoOrderInertiaCache: vi.fn(),
    };
});

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();

    return {
        ...actual,
        jsonPost: (...args: unknown[]) => mockJsonPost(...args),
        JsonPostError: actual.JsonPostError,
    };
});

describe('DispoOrderCancellationSection', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPost.mockReset();
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });
    });

    it('requires reason and submits cancel', async () => {
        render(
            <DispoOrderCancellationSection
                orderId={1}
                lockVersion={4}
                status="completed"
                canCancel
                summary={null}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-cancel-action'));
        expect(
            screen.getByTestId('dispo-order-cancel-dialog'),
        ).toBeInTheDocument();

        const confirm = screen.getByTestId('dispo-order-cancel-confirm');
        expect(confirm).toBeDisabled();

        fireEvent.change(screen.getByTestId('dispo-order-cancel-reason'), {
            target: { value: '   ' },
        });
        expect(confirm).toBeDisabled();

        const reason = 'Kampagne wurde nachträglich vom Kunden storniert.';
        fireEvent.change(screen.getByTestId('dispo-order-cancel-reason'), {
            target: { value: reason },
        });
        expect(confirm).not.toBeDisabled();
        fireEvent.click(confirm);

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/stornieren',
                { lock_version: 4, reason },
            );
        });
    });

    it('renders cancelled summary with actor and from status', () => {
        render(
            <DispoOrderCancellationSection
                orderId={1}
                lockVersion={6}
                status="cancelled"
                canCancel={false}
                summary={{
                    cancelled_by_name: 'E2E Disposition',
                    cancelled_at: '2026-09-24T10:00:00+00:00',
                    reason: 'Für Summary.',
                    from_status: 'completed',
                    from_status_label: 'Abgeschlossen',
                    available: true,
                    unavailable_message: null,
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-cancellation-summary'),
        ).toBeInTheDocument();
        expect(screen.getByTestId('dispo-order-cancelled-by')).toHaveTextContent(
            'E2E Disposition',
        );
        expect(
            screen.getByTestId('dispo-order-cancelled-from'),
        ).toHaveTextContent('Abgeschlossen');
        expect(
            screen.getByTestId('dispo-order-cancelled-reason'),
        ).toHaveTextContent('Für Summary.');
        expect(
            screen.queryByTestId('dispo-order-cancel-action'),
        ).not.toBeInTheDocument();
    });
});
