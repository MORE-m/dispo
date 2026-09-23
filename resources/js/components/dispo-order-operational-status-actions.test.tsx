import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderOperationalStatusActions } from '@/components/dispo-order-operational-status-actions';
import { JsonPostError } from '@/lib/json-post';

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

describe('DispoOrderOperationalStatusActions', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPost.mockReset();
    });

    it('renders no actions when canTransition is false', () => {
        const { container } = render(
            <DispoOrderOperationalStatusActions
                orderId={1}
                lockVersion={2}
                status="at_disposition"
                canTransition={false}
                targets={[
                    {
                        value: 'in_progress',
                        label: 'In Bearbeitung',
                        requires_reason: false,
                    },
                ]}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows start button for at_disposition', () => {
        render(
            <DispoOrderOperationalStatusActions
                orderId={1}
                lockVersion={2}
                status="at_disposition"
                canTransition
                targets={[
                    {
                        value: 'in_progress',
                        label: 'In Bearbeitung',
                        requires_reason: false,
                    },
                ]}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-status-action-in_progress'),
        ).toHaveTextContent('Bearbeitung starten');
    });

    it('posts transition and visits redirect', async () => {
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderOperationalStatusActions
                orderId={1}
                lockVersion={2}
                status="in_progress"
                canTransition
                targets={[
                    {
                        value: 'disposed',
                        label: 'Disponiert',
                        requires_reason: false,
                    },
                ]}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-status-action-disposed'));

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith('/dispoauftraege/1/status', {
                lock_version: 2,
                target_status: 'disposed',
            });
            expect(mockVisit).toHaveBeenCalled();
        });
    });

    it('requires reason for reopen dialog', async () => {
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderOperationalStatusActions
                orderId={5}
                lockVersion={4}
                status="disposed"
                canTransition
                targets={[
                    {
                        value: 'in_progress',
                        label: 'In Bearbeitung',
                        requires_reason: true,
                    },
                ]}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-status-action-in_progress'));
        expect(screen.getByTestId('dispo-order-reopen-dialog')).toBeInTheDocument();
        expect(screen.getByTestId('dispo-order-reopen-confirm')).toBeDisabled();

        fireEvent.change(screen.getByTestId('dispo-order-reopen-reason'), {
            target: { value: 'Korrektur nötig' },
        });
        fireEvent.click(screen.getByTestId('dispo-order-reopen-confirm'));

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith('/dispoauftraege/5/status', {
                lock_version: 4,
                target_status: 'in_progress',
                reason: 'Korrektur nötig',
            });
        });
    });

    it('shows conflict error message', async () => {
        mockJsonPost.mockRejectedValue(
            new JsonPostError('Konflikt', {}, 409),
        );

        render(
            <DispoOrderOperationalStatusActions
                orderId={1}
                lockVersion={2}
                status="at_disposition"
                canTransition
                targets={[
                    {
                        value: 'in_progress',
                        label: 'In Bearbeitung',
                        requires_reason: false,
                    },
                ]}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-status-action-in_progress'));

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-operational-status-error'),
            ).toHaveTextContent('Konflikt');
        });
    });
});
