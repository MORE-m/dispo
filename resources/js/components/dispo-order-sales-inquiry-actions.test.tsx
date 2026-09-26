import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderSalesInquiryActions } from '@/components/dispo-order-sales-inquiry-actions';
import { DispoOrderCommunicationHistory } from '@/components/dispo-order-communication-history';
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

describe('DispoOrderSalesInquiryActions', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPost.mockReset();
    });

    it('shows ask button only when canAsk', () => {
        const { rerender } = render(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={2}
                canAsk={false}
                canAnswer={false}
                openInquiry={null}
            />,
        );
        expect(
            screen.queryByTestId('dispo-order-ask-sales-inquiry'),
        ).not.toBeInTheDocument();

        rerender(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={2}
                canAsk
                canAnswer={false}
                openInquiry={null}
            />,
        );
        expect(
            screen.getByTestId('dispo-order-ask-sales-inquiry'),
        ).toBeInTheDocument();
    });

    it('blocks empty and whitespace question', () => {
        render(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={2}
                canAsk
                canAnswer={false}
                openInquiry={null}
            />,
        );
        fireEvent.click(screen.getByTestId('dispo-order-ask-sales-inquiry'));
        const confirm = screen.getByTestId('dispo-order-ask-inquiry-confirm');
        expect(confirm).toBeDisabled();
        fireEvent.change(
            screen.getByTestId('dispo-order-ask-inquiry-question'),
            { target: { value: '   ' } },
        );
        expect(confirm).toBeDisabled();
    });

    it('sales sees answer button, disposition does not without canAnswer', () => {
        const { rerender } = render(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={3}
                canAsk={false}
                canAnswer
                openInquiry={{
                    id: 9,
                    type: 'sales_inquiry',
                    type_label: 'Rückfrage',
                    body: 'Bitte Spotzeiten bestätigen.',
                    created_by_name: 'Dispo',
                    created_at: '2026-09-23T10:00:00+00:00',
                    parent_id: null,
                }}
            />,
        );
        expect(
            screen.getByTestId('dispo-order-answer-sales-inquiry'),
        ).toBeInTheDocument();

        rerender(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={3}
                canAsk={false}
                canAnswer={false}
                openInquiry={{
                    id: 9,
                    type: 'sales_inquiry',
                    type_label: 'Rückfrage',
                    body: 'Bitte Spotzeiten bestätigen.',
                    created_by_name: 'Dispo',
                    created_at: '2026-09-23T10:00:00+00:00',
                    parent_id: null,
                }}
            />,
        );
        expect(
            screen.queryByTestId('dispo-order-answer-sales-inquiry'),
        ).not.toBeInTheDocument();
    });

    it('answer dialog shows open question and posts answer', async () => {
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={4}
                canAsk={false}
                canAnswer
                openInquiry={{
                    id: 11,
                    type: 'sales_inquiry',
                    type_label: 'Rückfrage',
                    body: 'Bitte Spotzeiten bestätigen.',
                    created_by_name: 'Dispo',
                    created_at: '2026-09-23T10:00:00+00:00',
                    parent_id: null,
                }}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-answer-sales-inquiry'));
        expect(
            screen.getByTestId('dispo-order-answer-inquiry-question'),
        ).toHaveTextContent('Bitte Spotzeiten bestätigen.');

        const confirm = screen.getByTestId('dispo-order-answer-inquiry-confirm');
        expect(confirm).toBeDisabled();
        fireEvent.change(
            screen.getByTestId('dispo-order-answer-inquiry-answer'),
            { target: { value: 'Finale Spotzeiten bestätigt.' } },
        );
        fireEvent.click(confirm);

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/rueckfragen/11/antwort',
                {
                    lock_version: 4,
                    answer: 'Finale Spotzeiten bestätigt.',
                },
            );
        });
        expect(mockVisit).toHaveBeenCalled();
    });

    it('shows conflict error on ask', async () => {
        mockJsonPost.mockRejectedValue(
            new JsonPostError('Konflikt', {}, 409),
        );

        render(
            <DispoOrderSalesInquiryActions
                orderId={1}
                lockVersion={2}
                canAsk
                canAnswer={false}
                openInquiry={null}
            />,
        );
        fireEvent.click(screen.getByTestId('dispo-order-ask-sales-inquiry'));
        fireEvent.change(
            screen.getByTestId('dispo-order-ask-inquiry-question'),
            { target: { value: 'Frage?' } },
        );
        fireEvent.click(screen.getByTestId('dispo-order-ask-inquiry-confirm'));

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-ask-inquiry-error'),
            ).toHaveTextContent('Konflikt');
        });
    });
});

describe('sales_inquiry hides operational actions', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders nothing when no operational targets (sales_inquiry)', () => {
        const { container } = render(
            <DispoOrderOperationalStatusActions
                orderId={1}
                lockVersion={2}
                status="sales_inquiry"
                canTransition
                targets={[]}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});

describe('DispoOrderCommunicationHistory', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders question and indented answer without layout break on long text', () => {
        const long = 'A'.repeat(400);
        render(
            <DispoOrderCommunicationHistory
                entries={[
                    {
                        id: 1,
                        type: 'sales_inquiry',
                        type_label: 'Rückfrage',
                        body: long,
                        created_by_name: 'Dispo',
                        created_at: '2026-09-23T10:00:00+00:00',
                        parent_id: null,
                    },
                    {
                        id: 2,
                        type: 'sales_inquiry_response',
                        type_label: 'Antwort',
                        body: 'Antworttext',
                        created_by_name: 'Vertrieb',
                        created_at: '2026-09-23T11:00:00+00:00',
                        parent_id: 1,
                    },
                ]}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-communication-history'),
        ).toBeInTheDocument();
        expect(screen.getByTestId('communication-entry-1')).toHaveAttribute(
            'data-type',
            'sales_inquiry',
        );
        expect(screen.getByTestId('communication-entry-2')).toHaveAttribute(
            'data-parent-id',
            '1',
        );
        expect(
            screen.getByTestId('communication-entry-1').querySelector(
                '[data-test="communication-body"]',
            ),
        ).toHaveClass('break-words');
    });

    it('renders general comments and comment form when allowed', () => {
        render(
            <DispoOrderCommunicationHistory
                orderId={42}
                canAddComment
                entries={[
                    {
                        id: 9,
                        type: 'general',
                        type_label: 'Kommentar',
                        body: 'Allgemeiner Hinweis',
                        created_by_name: 'Vertrieb',
                        created_at: '2026-09-26T10:00:00+00:00',
                        parent_id: null,
                    },
                ]}
            />,
        );

        expect(screen.getByTestId('communication-entry-9')).toHaveAttribute(
            'data-type',
            'general',
        );
        expect(screen.getByTestId('dispo-order-comment-form')).toBeInTheDocument();
        expect(screen.getByTestId('dispo-order-comment-submit')).toBeDisabled();
    });

    it('hides form without canAddComment and shows empty history as null', () => {
        const { container } = render(
            <DispoOrderCommunicationHistory entries={[]} canAddComment={false} />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});
