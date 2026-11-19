import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderHeaderEditor } from '@/components/dispo-order-header-editor';

const mockPatch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (...args: unknown[]) => mockPatch(...args),
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

vi.mock('@/components/crm-account-picker', async (importOriginal) => {
    const actual =
        await importOriginal<typeof import('@/components/crm-account-picker')>();

    return {
        ...actual,
        CrmAccountPicker: ({
            value,
            onChange,
            testIdPrefix,
        }: {
            value: { mode: string; name?: string; accountId?: number | null };
            onChange: (next: {
                mode: 'empty' | 'account' | 'freitext' | 'legacy_freitext';
                name?: string;
                accountId?: number | null;
                billingEmail?: string;
                matchingDomain?: string;
            }) => void;
            testIdPrefix: string;
        }) => (
            <div data-test={`${testIdPrefix}-mock`}>
                <button
                    type="button"
                    data-test={`${testIdPrefix}-clear`}
                    onClick={() =>
                        onChange({
                            mode: 'empty',
                            billingEmail: '',
                            matchingDomain: '',
                        })
                    }
                >
                    clear
                </button>
                <span data-test={`${testIdPrefix}-mode`}>{value.mode}</span>
            </div>
        ),
    };
});

const baseOrder = {
    id: 42,
    lock_version: 3,
    status: 'draft',
    customer_name: 'Kunde AG',
    agency_name: 'Agentur GmbH',
    agency_account_id: 9,
    agency_meridian_number: 'M-1',
    invoice_recipient: 'agency' as const,
    briefing: 'Hallo',
};

describe('DispoOrderHeaderEditor', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockPatch.mockReset();
    });

    it('renders read-only when canEdit is false', () => {
        render(<DispoOrderHeaderEditor order={baseOrder} canEdit={false} />);

        expect(
            screen.getByTestId('dispo-order-header-readonly'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-invoice-recipient-readonly'),
        ).toHaveTextContent('Agentur');
        expect(
            screen.getByTestId('dispo-order-briefing-readonly'),
        ).toHaveTextContent('Hallo');
        expect(
            screen.queryByTestId('dispo-order-header-save'),
        ).not.toBeInTheDocument();
    });

    it('clears invoice recipient when agency is cleared', () => {
        render(<DispoOrderHeaderEditor order={baseOrder} canEdit />);

        const select = screen.getByTestId(
            'dispo-invoice-recipient',
        ) as HTMLSelectElement;
        expect(select.value).toBe('agency');

        fireEvent.click(screen.getByTestId('dispo-crm-agency-clear'));
        expect(select.value).toBe('');
    });

    it('saves header payload via patch', () => {
        render(
            <DispoOrderHeaderEditor
                order={{ ...baseOrder, invoice_recipient: 'customer' }}
                canEdit
            />,
        );

        fireEvent.change(screen.getByTestId('dispo-briefing'), {
            target: { value: 'Neu' },
        });
        fireEvent.click(screen.getByTestId('dispo-order-header-save'));

        expect(mockPatch).toHaveBeenCalled();
        const [url, payload] = mockPatch.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/dispoauftraege/42/kopfdaten');
        expect(payload.lock_version).toBe(3);
        expect(payload.briefing).toBe('Neu');
        expect(payload.invoice_recipient).toBe('customer');
    });

    it('disables agency invoice option without agency and keeps customer read-only', () => {
        render(
            <DispoOrderHeaderEditor
                order={{
                    ...baseOrder,
                    agency_name: null,
                    agency_account_id: null,
                    invoice_recipient: null,
                    briefing: null,
                }}
                canEdit
            />,
        );

        expect(screen.getByText('Kunde AG')).toBeInTheDocument();
        expect(
            screen.queryByRole('textbox', { name: /kunde/i }),
        ).not.toBeInTheDocument();

        const select = screen.getByTestId(
            'dispo-invoice-recipient',
        ) as HTMLSelectElement;
        const agencyOption = Array.from(select.options).find(
            (option) => option.value === 'agency',
        );
        expect(agencyOption?.disabled).toBe(true);
    });

    it('sends null briefing when cleared', () => {
        render(<DispoOrderHeaderEditor order={baseOrder} canEdit />);

        fireEvent.change(screen.getByTestId('dispo-briefing'), {
            target: { value: '   ' },
        });
        fireEvent.click(screen.getByTestId('dispo-order-header-save'));

        const payload = mockPatch.mock.calls[0][1] as Record<string, unknown>;
        expect(payload.briefing).toBeNull();
        expect(payload.invoice_recipient).toBe('agency');
    });
});
