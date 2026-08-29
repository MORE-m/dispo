import { render, screen } from '@testing-library/react';
import { expect, test } from 'vitest';
import { EmptyState } from '@/components/feedback/states';
import { LogoSlot } from '@/components/logo-slot';
import { money } from '@/components/form-field';

test('EmptyState zeigt Titel', () => {
    render(<EmptyState title="Keine Kalkulationen" />);
    expect(screen.getByRole('status')).toHaveTextContent('Keine Kalkulationen');
});

test('LogoSlot nutzt neutrale Initialen', () => {
    const { container } = render(<LogoSlot name="Radio Hamburg" />);
    expect(container).toHaveTextContent('RH');
});

test('LogoSlot rendert Senderlogo mit Alternativtext', () => {
    render(
        <LogoSlot
            name="Radio Hamburg"
            logoPath="/images/senders/radio-hamburg.png"
            variant="card"
        />,
    );
    expect(screen.getByRole('img', { name: 'Radio Hamburg' })).toHaveAttribute(
        'src',
        '/images/senders/radio-hamburg.png',
    );
});

test('money formatiert EUR', () => {
    expect(money('10.5')).toContain('10,50');
});
