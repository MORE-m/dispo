import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCreateAction } from '@/components/dispo-order-create-action';

vi.mock('@/components/dispo-order-create-dialog', () => ({
    DispoOrderCreateDialog: () => null,
}));

describe('DispoOrderCreateAction', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders create button for authorized context', () => {
        render(<DispoOrderCreateAction calculationId={7} />);

        expect(screen.getByTestId('dispo-order-create-open')).toHaveTextContent(
            'Dispoauftrag anlegen',
        );
    });

    it('renders correction label in revision context', () => {
        render(
            <DispoOrderCreateAction
                calculationId={7}
                revision={{
                    predecessor_id: 3,
                    predecessor_number: 'DA-2026-000010-01',
                    rejection_reason: 'Ablehnung',
                    return_url: '/dispoauftraege/3',
                }}
            />,
        );

        expect(screen.getByTestId('dispo-order-create-open')).toHaveTextContent(
            'Korrigierten Dispoauftrag erstellen',
        );
    });
});
