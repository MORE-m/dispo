import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCompletedReopenSection } from '@/components/dispo-order-completed-reopen-section';

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

describe('DispoOrderCompletedReopenSection', () => {
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

    it('renders nothing when reopen is not allowed', () => {
        const { container } = render(
            <DispoOrderCompletedReopenSection
                orderId={1}
                lockVersion={3}
                canReopenCompleted={false}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows reopen dialog and submits with reason', async () => {
        render(
            <DispoOrderCompletedReopenSection
                orderId={1}
                lockVersion={5}
                canReopenCompleted
            />,
        );

        expect(
            screen.getByTestId('dispo-order-reopen-completed-action'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('dispo-order-reopen-completed-action'));
        expect(
            screen.getByTestId('dispo-order-reopen-completed-dialog'),
        ).toBeInTheDocument();

        const confirm = screen.getByTestId(
            'dispo-order-reopen-completed-confirm',
        );
        expect(confirm).toBeDisabled();

        fireEvent.change(
            screen.getByTestId('dispo-order-reopen-completed-reason'),
            { target: { value: '   ' } },
        );
        expect(confirm).toBeDisabled();

        fireEvent.change(
            screen.getByTestId('dispo-order-reopen-completed-reason'),
            {
                target: {
                    value: 'Auftrag muss operativ nachbearbeitet werden.',
                },
            },
        );
        expect(confirm).not.toBeDisabled();
        fireEvent.click(confirm);

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/wieder-oeffnen',
                {
                    lock_version: 5,
                    reason: 'Auftrag muss operativ nachbearbeitet werden.',
                },
            );
        });
    });
});
