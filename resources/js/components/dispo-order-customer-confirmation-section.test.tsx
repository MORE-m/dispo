import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderCustomerConfirmationSection } from '@/components/dispo-order-customer-confirmation-section';
import { JsonPostError } from '@/lib/json-post';
import type { DispoOrderUpload } from '@/types/dispo-order';

const mockVisit = vi.fn();
const mockJsonPut = vi.fn();
const mockFormDataPost = vi.fn();

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
        formDataPost: (...args: unknown[]) => mockFormDataPost(...args),
        JsonPostError: actual.JsonPostError,
    };
});

const activeUpload: DispoOrderUpload = {
    id: 9,
    category: 'customer_confirmation',
    category_label: 'Kundenbestätigung',
    original_filename: 'freigabe.pdf',
    mime_type: 'application/pdf',
    size_bytes: 2048,
    sha256: 'abc',
    uploaded_by_name: 'Sales',
    uploaded_at: '2026-09-25T10:00:00+00:00',
    archived: false,
    archived_at: null,
    archived_by_name: null,
    is_active_customer_confirmation: true,
    download_url: '/dispoauftraege/1/uploads/9/download',
};

describe('DispoOrderCustomerConfirmationSection', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPut.mockReset();
        mockFormDataPost.mockReset();
    });

    it('shows section, checkbox and reason on activate in draft', () => {
        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={1}
                status="draft"
                canEdit
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-customer-confirmation'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('customer-confirmation-without-upload'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByTestId('customer-confirmation-without-upload'),
        );
        expect(
            screen.getByTestId('customer-confirmation-exception-reason'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('customer-confirmation-save'),
        ).toBeDisabled();
    });

    it('shows upload CTA when canUpload in draft', () => {
        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={1}
                status="draft"
                canEdit
                canUpload
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
            />,
        );

        expect(
            screen.getByTestId('customer-confirmation-upload-input'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('customer-confirmation-upload-button'),
        ).toBeDisabled();
    });

    it('uploads selected file via formDataPost', async () => {
        mockFormDataPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={4}
                status="draft"
                canEdit
                canUpload
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
            />,
        );

        const file = new File(['pdf'], 'freigabe.pdf', {
            type: 'application/pdf',
        });
        fireEvent.change(
            screen.getByTestId('customer-confirmation-upload-input'),
            { target: { files: [file] } },
        );
        fireEvent.click(
            screen.getByTestId('customer-confirmation-upload-button'),
        );

        await waitFor(() => {
            expect(mockFormDataPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/uploads/kundenbestaetigung',
                expect.any(FormData),
            );
        });

        const body = mockFormDataPost.mock.calls[0][1] as FormData;
        expect(body.get('file')).toBeInstanceOf(File);
        expect(body.get('lock_version')).toBe('4');
    });

    it('shows active file and precedence hint', () => {
        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={1}
                status="draft"
                canEdit
                canUpload
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
                activeUpload={activeUpload}
            />,
        );

        expect(
            screen.getByTestId('customer-confirmation-active-file'),
        ).toHaveTextContent('freigabe.pdf');
        expect(
            screen.getByTestId('customer-confirmation-upload-precedence-hint'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('customer-confirmation-status'),
        ).not.toBeInTheDocument();
    });

    it('blocks empty/whitespace reason and saves valid reason', async () => {
        mockJsonPut.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={1}
                status="draft"
                canEdit
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
            />,
        );

        fireEvent.click(
            screen.getByTestId('customer-confirmation-without-upload'),
        );
        const reason = screen.getByTestId(
            'customer-confirmation-exception-reason',
        );
        fireEvent.change(reason, { target: { value: '   ' } });
        expect(
            screen.getByTestId('customer-confirmation-save'),
        ).toBeDisabled();

        fireEvent.change(reason, {
            target: {
                value: 'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
            },
        });
        fireEvent.click(screen.getByTestId('customer-confirmation-save'));

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalledWith(
                '/dispoauftraege/1/kundenbestaetigung',
                expect.objectContaining({
                    confirmation_without_upload: true,
                    exception_reason:
                        'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
                }),
            );
        });
    });

    it('clears exception when unchecked and saved', async () => {
        mockJsonPut.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={2}
                status="draft"
                canEdit
                withoutUpload
                exceptionReason="Alter Grund"
                setByName="Sales"
                setAt="2026-09-23T10:00:00+00:00"
            />,
        );

        fireEvent.click(
            screen.getByTestId('customer-confirmation-without-upload'),
        );
        fireEvent.click(screen.getByTestId('customer-confirmation-save'));

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalledWith(
                '/dispoauftraege/1/kundenbestaetigung',
                expect.objectContaining({
                    confirmation_without_upload: false,
                    exception_reason: null,
                }),
            );
        });
    });

    it('is read-only outside draft', () => {
        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={3}
                status="awaiting_sales_approval"
                canEdit={false}
                withoutUpload
                exceptionReason="Kundenfreigabe liegt per E-Mail vor"
                setByName="Sales"
                setAt="2026-09-23T10:00:00+00:00"
            />,
        );

        expect(
            screen.getByTestId('customer-confirmation-readonly'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('customer-confirmation-without-upload'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByTestId(
                'customer-confirmation-exception-reason-readonly',
            ),
        ).toHaveTextContent('Kundenfreigabe liegt per E-Mail vor');
    });

    it('shows conflict error from API', async () => {
        mockJsonPut.mockRejectedValue(
            new JsonPostError('Konflikt', {}, 409),
        );

        render(
            <DispoOrderCustomerConfirmationSection
                orderId={1}
                lockVersion={1}
                status="draft"
                canEdit
                withoutUpload={false}
                exceptionReason={null}
                setByName={null}
                setAt={null}
            />,
        );

        fireEvent.click(
            screen.getByTestId('customer-confirmation-without-upload'),
        );
        fireEvent.change(
            screen.getByTestId('customer-confirmation-exception-reason'),
            { target: { value: 'Grund' } },
        );
        fireEvent.click(screen.getByTestId('customer-confirmation-save'));

        await waitFor(() => {
            expect(
                screen.getByTestId('customer-confirmation-error'),
            ).toHaveTextContent('Konflikt');
        });
    });
});
