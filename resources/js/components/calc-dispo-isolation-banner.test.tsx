import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CalcDispoIsolationBanner } from '@/components/calc-dispo-isolation-banner';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('CalcDispoIsolationBanner', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders singular text and link', () => {
        render(
            <CalcDispoIsolationBanner
                linked={{
                    count: 1,
                    orders: [
                        {
                            number: 'DA-2026-000001-01',
                            show_url: '/dispoauftraege/9',
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByTestId('calc-dispo-isolation-message'),
        ).toHaveTextContent(
            'Aus dieser Kalkulation wurde bereits ein Dispoauftrag erstellt. Änderungen an der Kalkulation ändern den bestehenden Dispoauftrag nicht.',
        );
        expect(
            screen.getByTestId('calc-dispo-isolation-link-DA-2026-000001-01'),
        ).toHaveAttribute('href', '/dispoauftraege/9');
    });

    it('renders plural text for multiple orders', () => {
        render(
            <CalcDispoIsolationBanner
                linked={{
                    count: 2,
                    orders: [
                        {
                            number: 'DA-2026-000001-01',
                            show_url: '/dispoauftraege/1',
                        },
                        {
                            number: 'DA-2026-000001-02',
                            show_url: '/dispoauftraege/2',
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByTestId('calc-dispo-isolation-message'),
        ).toHaveTextContent(
            'Aus dieser Kalkulation wurden bereits Dispoaufträge erstellt. Änderungen an der Kalkulation ändern die bestehenden Dispoaufträge nicht.',
        );
        expect(
            screen.getByTestId('calc-dispo-isolation-links'),
        ).toHaveTextContent('DA-2026-000001-01');
        expect(
            screen.getByTestId('calc-dispo-isolation-links'),
        ).toHaveTextContent('DA-2026-000001-02');
    });

    it('shows message without links when orders list is empty', () => {
        render(
            <CalcDispoIsolationBanner
                linked={{
                    count: 1,
                    orders: [],
                }}
            />,
        );

        expect(
            screen.getByTestId('calc-dispo-isolation-banner'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('calc-dispo-isolation-links'),
        ).not.toBeInTheDocument();
    });
});
