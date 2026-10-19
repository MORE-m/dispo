import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UserAdminCreate from './create';
import UserAdminEdit from './edit';

afterEach(() => {
    cleanup();
});

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    }),
    usePage: () => ({ props: { flash: {} } }),
}));

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'sales', label: 'Vertrieb' },
    { value: 'disposition', label: 'Disposition' },
    { value: 'management', label: 'Geschäftsführung' },
    { value: 'product_management', label: 'Produktmanagement' },
];

describe('PO-USER-ADMIN-1 forms', () => {
    it('create form exposes role discount special and password fields', () => {
        render(<UserAdminCreate roleOptions={roleOptions} />);

        expect(screen.getByTestId('user-create-form')).toBeTruthy();
        expect(screen.getByTestId('user-role-input')).toHaveValue('sales');
        expect(screen.getByTestId('user-discount-input')).toBeTruthy();
        expect(screen.getByTestId('user-special-approve-input')).toBeTruthy();
        expect(screen.getByTestId('user-password-input')).toBeTruthy();
    });

    it('edit form exposes active status role discount and special approve', () => {
        render(
            <UserAdminEdit
                roleOptions={roleOptions}
                user={{
                    id: 7,
                    name: 'Test',
                    email: 'test@example.com',
                    role: 'sales',
                    role_label: 'Vertrieb',
                    discount_limit_percent: '10.0000',
                    can_special_approve: true,
                    is_active: true,
                }}
            />,
        );

        const form = screen.getByTestId('user-edit-form');
        expect(form).toBeTruthy();
        expect(within(form).getByTestId('user-role-input')).toHaveValue('sales');
        expect(within(form).getByTestId('user-discount-input')).toHaveValue(10);
        expect(
            within(form).getByTestId('user-special-approve-input'),
        ).toBeChecked();
        expect(within(form).getByTestId('user-active-input')).toBeChecked();
    });
});
