import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DispoOrderCreateAction } from '@/components/dispo-order-create-action';

vi.mock('@/components/dispo-order-create-dialog', () => ({
    DispoOrderCreateDialog: () => null,
}));

describe('DispoOrderCreateAction', () => {
    it('renders create button for authorized context', () => {
        render(<DispoOrderCreateAction calculationId={7} />);

        expect(screen.getByTestId('dispo-order-create-open')).toHaveTextContent(
            'Dispoauftrag anlegen',
        );
    });
});
