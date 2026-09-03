import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderApprovalActions } from '@/components/dispo-order-approval-actions';
import { JsonPostError } from '@/lib/json-post';

const mockVisit = vi.fn();
const mockJsonPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (...args: unknown[]) => mockVisit(...args),
    },
}));

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();

    return {
        ...actual,
        jsonPost: (...args: unknown[]) => mockJsonPost(...args),
        JsonPostError: actual.JsonPostError,
    };
});

describe('DispoOrderApprovalActions', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPost.mockReset();
    });

    it('shows submit for draft when allowed', () => {
        render(
            <DispoOrderApprovalActions
                orderId={1}
                lockVersion={1}
                canSubmit
                canApprove={false}
                canReject={false}
                isCreator
                status="draft"
            />,
        );

        expect(screen.getByTestId('dispo-order-submit-open')).toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-approve-open'),
        ).not.toBeInTheDocument();
    });

    it('hides decision buttons for creator and shows four-eyes hint', () => {
        render(
            <DispoOrderApprovalActions
                orderId={1}
                lockVersion={2}
                canSubmit={false}
                canApprove={false}
                canReject={false}
                isCreator
                status="awaiting_sales_approval"
            />,
        );

        expect(screen.getByTestId('four-eyes-creator-hint')).toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-approve-open'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-reject-open'),
        ).not.toBeInTheDocument();
    });

    it('shows approve and reject for allowed decider', () => {
        render(
            <DispoOrderApprovalActions
                orderId={1}
                lockVersion={2}
                canSubmit={false}
                canApprove
                canReject
                isCreator={false}
                status="awaiting_sales_approval"
            />,
        );

        expect(screen.getByTestId('dispo-order-approve-open')).toBeInTheDocument();
        expect(screen.getByTestId('dispo-order-reject-open')).toBeInTheDocument();
    });

    it('opens submit dialog and posts with lock_version', async () => {
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderApprovalActions
                orderId={7}
                lockVersion={3}
                canSubmit
                canApprove={false}
                canReject={false}
                isCreator
                status="draft"
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-submit-open'));
        expect(screen.getByTestId('dispo-order-submit-dialog')).toBeInTheDocument();
        fireEvent.click(screen.getByTestId('dispo-order-submit-confirm'));

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/7/einreichen',
                { lock_version: 3 },
            );
        });
        expect(mockVisit).toHaveBeenCalledWith('/dispoauftraege/1');
    });

    it('shows conflict message on reject', async () => {
        mockJsonPost.mockRejectedValue(
            new JsonPostError(
                'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
                {},
                409,
            ),
        );

        render(
            <DispoOrderApprovalActions
                orderId={1}
                lockVersion={2}
                canSubmit={false}
                canApprove
                canReject
                isCreator={false}
                status="awaiting_sales_approval"
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-reject-open'));
        fireEvent.change(screen.getByTestId('dispo-order-reject-reason'), {
            target: { value: 'Preis zu niedrig' },
        });
        fireEvent.click(screen.getByTestId('dispo-order-reject-confirm'));

        await waitFor(() => {
            expect(screen.getByTestId('dispo-order-reject-error')).toHaveTextContent(
                'parallel geändert',
            );
        });
    });

    it('blocks double submit while request is pending', async () => {
        let resolvePost: (value: unknown) => void = () => undefined;
        mockJsonPost.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolvePost = resolve;
                }),
        );

        render(
            <DispoOrderApprovalActions
                orderId={1}
                lockVersion={1}
                canSubmit
                canApprove={false}
                canReject={false}
                isCreator
                status="draft"
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-submit-open'));
        const confirm = screen.getByTestId('dispo-order-submit-confirm');
        fireEvent.click(confirm);
        fireEvent.click(confirm);

        expect(mockJsonPost).toHaveBeenCalledTimes(1);
        resolvePost({ message: 'ok', redirect: '/dispoauftraege/1' });
        await waitFor(() => expect(mockVisit).toHaveBeenCalled());
    });
});
