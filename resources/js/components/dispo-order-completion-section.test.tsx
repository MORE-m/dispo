import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCompletionSection } from '@/components/dispo-order-completion-section';

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

const readyChecks = [
    {
        key: 'required_fields',
        label: 'Pflichtfelder',
        passed: true,
        violations: [],
    },
    {
        key: 'open_sales_inquiry',
        label: 'Rückfrage',
        passed: true,
        violations: [],
    },
    {
        key: 'invoice_end_months',
        label: 'Rechnung per Ende',
        passed: true,
        violations: [],
    },
    {
        key: 'customer_confirmation',
        label: 'Kundenbestätigung',
        passed: true,
        violations: [],
    },
    {
        key: 'approvals',
        label: 'Freigaben',
        passed: true,
        violations: [],
    },
];

describe('DispoOrderCompletionSection', () => {
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

    it('shows green readiness and complete button', async () => {
        render(
            <DispoOrderCompletionSection
                orderId={1}
                lockVersion={4}
                status="disposed"
                canComplete
                canForceComplete={false}
                readiness={{ ready: true, checks: readyChecks }}
                summary={null}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-complete-action'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByTestId('dispo-order-complete-action'));
        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/abschliessen',
                { lock_version: 4 },
            );
        });
    });

    it('shows blockers and admin override dialog', async () => {
        const blocked = readyChecks.map((check) =>
            check.key === 'invoice_end_months'
                ? {
                      ...check,
                      passed: false,
                      violations: [
                          { message: 'Position 1: Rechnung per Ende fehlt.' },
                      ],
                  }
                : check,
        );

        render(
            <DispoOrderCompletionSection
                orderId={1}
                lockVersion={4}
                status="disposed"
                canComplete
                canForceComplete
                readiness={{ ready: false, checks: blocked }}
                summary={null}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-force-complete-action'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-complete-action'),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByTestId('dispo-order-force-complete-action'),
        );
        expect(
            screen.getByTestId('dispo-order-force-complete-dialog'),
        ).toBeInTheDocument();

        const confirm = screen.getByTestId(
            'dispo-order-force-complete-confirm',
        );
        expect(confirm).toBeDisabled();

        fireEvent.change(
            screen.getByTestId('dispo-order-force-complete-reason'),
            { target: { value: 'Override mit Begründung' } },
        );
        expect(confirm).not.toBeDisabled();
        fireEvent.click(confirm);

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/abschliessen',
                {
                    lock_version: 4,
                    override_reason: 'Override mit Begründung',
                },
            );
        });
    });

    it('renders completed summary with override', () => {
        render(
            <DispoOrderCompletionSection
                orderId={1}
                lockVersion={5}
                status="completed"
                canComplete={false}
                canForceComplete={false}
                readiness={null}
                summary={{
                    completed_by_name: 'E2E Admin',
                    completed_at: '2026-09-24T10:00:00+00:00',
                    is_completion_override: true,
                    override_reason: 'Nachträglich dokumentiert',
                    override_violations: [
                        {
                            key: 'invoice_end_months',
                            label: 'Rechnung per Ende',
                            messages: [
                                'Position 1: Rechnung per Ende fehlt.',
                            ],
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-completion-summary'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-completion-override-info'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-completion-override-reason'),
        ).toHaveTextContent('Nachträglich dokumentiert');
    });
});
