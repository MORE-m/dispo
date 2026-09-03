import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderRevisionBanner } from '@/components/dispo-order-revision-banner';
import { DispoOrderReviseAction } from '@/components/dispo-order-revise-action';

const mockPost = vi.fn();

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
    router: {
        post: (...args: unknown[]) => mockPost(...args),
        flushByCacheTags: vi.fn(),
        flush: vi.fn(),
    },
}));

describe('DispoOrderRevisionBanner', () => {
    afterEach(() => {
        cleanup();
    });

    it('shows rejection reason and return link', () => {
        render(
            <DispoOrderRevisionBanner
                revision={{
                    predecessor_id: 5,
                    predecessor_number: 'DA-2026-000005-01',
                    rejection_reason: 'Konditionen nicht freigabefähig',
                    return_url: '/dispoauftraege/5',
                }}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-revision-banner'),
        ).toHaveTextContent('DA-2026-000005-01');
        expect(
            screen.getByTestId('dispo-order-revision-rejection-reason'),
        ).toHaveTextContent('Konditionen nicht freigabefähig');
        expect(
            screen.getByTestId('dispo-order-revision-return-link'),
        ).toHaveAttribute('href', '/dispoauftraege/5');
    });
});

describe('DispoOrderReviseAction', () => {
    afterEach(() => {
        cleanup();
        mockPost.mockReset();
    });

    it('posts to start revision and blocks double click', () => {
        mockPost.mockImplementation(() => undefined);

        render(<DispoOrderReviseAction orderId={12} />);

        expect(screen.getByTestId('dispo-order-revise-open')).toHaveTextContent(
            'Nachbessern',
        );

        screen.getByTestId('dispo-order-revise-open').click();
        screen.getByTestId('dispo-order-revise-open').click();

        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockPost).toHaveBeenCalledWith(
            '/dispoauftraege/12/nachbessern',
            {},
            expect.objectContaining({
                invalidateCacheTags: 'dispo-orders',
            }),
        );
    });
});
