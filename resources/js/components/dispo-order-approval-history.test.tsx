import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DispoOrderApprovalHistory } from '@/components/dispo-order-approval-history';

describe('DispoOrderApprovalHistory', () => {
    it('renders submission, special reasons and rejection', () => {
        render(
            <DispoOrderApprovalHistory
                entries={[
                    {
                        id: 11,
                        cycle_number: 1,
                        status: 'rejected',
                        status_label: 'Abgelehnt',
                        kind: 'special',
                        kind_label: 'Sonderfreigabe',
                        special_approval_reasons: [
                            {
                                code: 'position_discount_exceeds_personal_limit',
                                label: 'Positionsrabatt überschreitet persönliche Grenze',
                                position_label: 'Radio Hamburg',
                                actual_percent: '20.0000',
                                limit_percent: '10.0000',
                            },
                        ],
                        submitted_by_name: 'Vertrieb A',
                        submitted_at: '2026-09-03T08:00:00+00:00',
                        decided_by_name: 'Admin',
                        decided_at: '2026-09-03T09:00:00+00:00',
                        rejection_reason: 'Rabatt nicht tragbar',
                        decision_note: null,
                    },
                ]}
            />,
        );

        expect(screen.getByTestId('dispo-order-approval-history')).toBeInTheDocument();
        expect(screen.getByText(/Sonderfreigabe/)).toBeInTheDocument();
        expect(screen.getByTestId('special-approval-reasons')).toBeInTheDocument();
        expect(screen.getByTestId('approval-rejection-reason')).toHaveTextContent(
            'Rabatt nicht tragbar',
        );
        expect(screen.getByTestId('approval-submitted-at')).toHaveTextContent(
            '03.09.2026, 10:00',
        );
        expect(screen.getByTestId('approval-decided-at')).toHaveTextContent(
            '03.09.2026, 11:00',
        );
    });

    it('shows exception reason and acknowledgement only when approved', () => {
        render(
            <DispoOrderApprovalHistory
                entries={[
                    {
                        id: 21,
                        cycle_number: 1,
                        status: 'approved',
                        status_label: 'Genehmigt',
                        kind: 'regular',
                        kind_label: 'Reguläre Freigabe',
                        special_approval_reasons: [],
                        submitted_by_name: 'Vertrieb A',
                        submitted_at: '2026-09-23T08:00:00+00:00',
                        decided_by_name: 'Vertrieb B',
                        decided_at: '2026-09-23T09:00:00+00:00',
                        rejection_reason: null,
                        decision_note: null,
                        customer_confirmation_without_upload: true,
                        customer_confirmation_exception_reason:
                            'Kundenfreigabe liegt per E-Mail vor',
                        customer_confirmation_exception_acknowledged: true,
                        customer_confirmation_exception_acknowledged_by_name:
                            'Vertrieb B',
                        customer_confirmation_exception_acknowledged_at:
                            '2026-09-23T09:00:00+00:00',
                    },
                    {
                        id: 22,
                        cycle_number: 1,
                        status: 'rejected',
                        status_label: 'Abgelehnt',
                        kind: 'regular',
                        kind_label: 'Reguläre Freigabe',
                        special_approval_reasons: [],
                        submitted_by_name: 'Vertrieb A',
                        submitted_at: '2026-09-22T08:00:00+00:00',
                        decided_by_name: 'Vertrieb B',
                        decided_at: '2026-09-22T09:00:00+00:00',
                        rejection_reason: 'Unzureichend',
                        decision_note: null,
                        customer_confirmation_without_upload: true,
                        customer_confirmation_exception_reason:
                            'Alter Ausnahmegrund',
                        customer_confirmation_exception_acknowledged: false,
                        customer_confirmation_exception_acknowledged_by_name:
                            null,
                        customer_confirmation_exception_acknowledged_at: null,
                    },
                ]}
            />,
        );

        expect(
            screen.getAllByTestId('approval-history-exception-reason')[0],
        ).toHaveTextContent('Kundenfreigabe liegt per E-Mail vor');
        expect(
            screen.getByTestId('approval-history-exception-ack'),
        ).toHaveTextContent('Vertrieb B');
        expect(
            screen.getAllByTestId('approval-history-customer-confirmation'),
        ).toHaveLength(2);
        expect(
            screen.queryAllByTestId('approval-history-exception-ack'),
        ).toHaveLength(1);
    });
});
