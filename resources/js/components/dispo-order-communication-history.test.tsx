import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCommunicationHistory } from '@/components/dispo-order-communication-history';

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: vi.fn(),
        flushByCacheTags: vi.fn(),
        flush: vi.fn(),
    },
}));

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();

    return {
        ...actual,
        jsonPost: vi.fn(),
    };
});

describe('DispoOrderCommunicationHistory', () => {
    afterEach(() => {
        cleanup();
    });

    it('returns null when empty and comment form hidden', () => {
        const { container } = render(
            <DispoOrderCommunicationHistory entries={[]} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('applies scrollable layout classes without changing content', () => {
        render(
            <DispoOrderCommunicationHistory
                entries={[
                    {
                        id: 1,
                        type: 'general',
                        type_label: 'Kommentar',
                        body: 'Hallo',
                        created_by_name: 'Sales',
                        created_at: '2026-11-17T10:00:00+00:00',
                        parent_id: null,
                    },
                ]}
                orderId={3}
                canAddComment
                scrollable
                className="custom-aside"
            />,
        );

        const card = screen.getByTestId('dispo-order-communication-history');
        expect(card.className).toContain('max-h-[calc(100svh-3rem)]');
        expect(card.className).toContain('custom-aside');
        expect(
            screen.getByTestId('dispo-order-communication-entries'),
        ).toBeInTheDocument();
        expect(screen.getByTestId('dispo-order-comment-form')).toBeInTheDocument();
        expect(screen.getByTestId('communication-body')).toHaveTextContent(
            'Hallo',
        );
    });
});
