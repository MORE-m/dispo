import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DispoOrderInvoiceEndMonths,
    INVOICE_END_MONTH_OPTIONS,
} from '@/components/dispo-order-invoice-end-months';

const mockVisit = vi.fn();
const mockJsonPut = vi.fn();

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
        jsonPut: (...args: unknown[]) => mockJsonPut(...args),
        JsonPostError: actual.JsonPostError,
    };
});

describe('DispoOrderInvoiceEndMonths', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPut.mockReset();
        mockJsonPut.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });
    });

    it('shows 12 german month options and saves selection', async () => {
        render(
            <DispoOrderInvoiceEndMonths
                orderId={1}
                positionId={9}
                lockVersion={3}
                months={null}
                periodState="concrete"
                canEdit
            />,
        );

        expect(INVOICE_END_MONTH_OPTIONS).toHaveLength(12);
        expect(screen.getByText('Januar')).toBeInTheDocument();
        expect(screen.getByText('Dezember')).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-invoice-end-hint-required'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('dispo-order-invoice-end-month-3'));
        fireEvent.click(screen.getByTestId('dispo-order-invoice-end-save'));

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalledWith(
                '/dispoauftraege/1/positionen/9/rechnung-per-ende',
                { lock_version: 3, months: [3] },
            );
        });
    });

    it('renders read-only labels when not editable', () => {
        render(
            <DispoOrderInvoiceEndMonths
                orderId={1}
                positionId={9}
                lockVersion={3}
                months={[1, 12]}
                monthLabels={['Januar', 'Dezember']}
                periodState="concrete"
                canEdit={false}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-invoice-end-readonly'),
        ).toHaveTextContent('Januar, Dezember');
        expect(
            screen.queryByTestId('dispo-order-invoice-end-save'),
        ).not.toBeInTheDocument();
    });

    it('shows open-period hint', () => {
        render(
            <DispoOrderInvoiceEndMonths
                orderId={1}
                positionId={9}
                lockVersion={3}
                months={null}
                periodState="open"
                canEdit
            />,
        );

        expect(
            screen.getByTestId('dispo-order-invoice-end-hint-open'),
        ).toBeInTheDocument();
    });
});
