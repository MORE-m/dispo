import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { JsonPostError } from '@/lib/json-post';
import DefinitionShow from './show';

const mockReload = vi.fn();
const mockJsonPut = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({
        children,
        href,
    }: {
        children?: React.ReactNode;
        href: string;
    }) => <a href={href}>{children}</a>,
    router: {
        reload: (...args: unknown[]) => mockReload(...args),
        visit: vi.fn(),
    },
    usePage: () => ({
        props: {
            flash: {},
        },
    }),
}));

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();

    return {
        ...actual,
        jsonPut: (...args: unknown[]) => mockJsonPut(...args),
        jsonPost: vi.fn(),
        jsonDelete: vi.fn(),
        JsonPostError: actual.JsonPostError,
    };
});

vi.mock('@/components/administration/field-definition-options-editor', () => ({
    default: () => null,
}));

const baseRevision = {
    id: 11,
    revision: 1,
    label: 'Scope Demo',
    help_text: null,
    group_key: null,
    sort_default: 10,
    reportable: false,
    validation_json: { max_length: 255 },
    created_at: null,
};

function renderShow(
    overrides: Partial<{
        scope: string;
        is_used: boolean;
        lock_version: number;
    }> = {},
) {
    return render(
        <DefinitionShow
            definition={{
                id: 42,
                key: 'scope_demo',
                field_type: 'short_text',
                scope: overrides.scope ?? 'header',
                applies_to: 'both',
                is_system: false,
                is_key_protected: false,
                is_active: true,
                lock_version: overrides.lock_version ?? 3,
                is_used: overrides.is_used ?? false,
                max_length: 255,
                current_revision: baseRevision,
                revisions: [baseRevision],
                memberships: [],
                options: [],
                options_fingerprint: null,
                can_manage_options: false,
            }}
            routes={null}
            optionsBoundaryNote=""
        />,
    );
}

describe('DefinitionShow structural scope', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockReload.mockReset();
        mockJsonPut.mockReset();
        mockJsonPut.mockResolvedValue({ redirect: '/x' });
    });

    it('initialisiert strukturellen State mit aktuellem Scope', () => {
        renderShow({ scope: 'position' });

        expect(
            screen.getByTestId('structural-scope-select'),
        ).toHaveValue('position');
        expect(
            screen.getByLabelText('Bereich'),
        ).toHaveValue('position');
    });

    it('sendet gewählten Scope im strukturellen Payload', async () => {
        renderShow({ scope: 'header' });

        fireEvent.change(screen.getByTestId('structural-scope-select'), {
            target: { value: 'position' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalled();
        });

        expect(mockJsonPut.mock.calls[0]?.[1]).toMatchObject({
            lock_version: 3,
            scope: 'position',
            field_type: 'short_text',
            applies_to: 'both',
        });
        expect(mockReload).toHaveBeenCalled();
    });

    it('zeigt Scope-Feldfehler am Select', async () => {
        mockJsonPut.mockRejectedValue(
            new JsonPostError('Validierung fehlgeschlagen.', {
                scope: ['Scope muss header oder position sein.'],
            }, 422),
        );

        renderShow();
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => {
            expect(
                screen.getByText('Scope muss header oder position sein.'),
            ).toBeInTheDocument();
        });
        expect(screen.getByLabelText('Bereich')).toBeInTheDocument();
    });

    it('zeigt Sperrhinweis für verwendete Definitionen', () => {
        renderShow({ is_used: true });

        expect(
            screen.getByTestId('field-definition-structural-lock-hint'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Feldtyp, Bereich und Geltung sind nach der ersten/),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('structural-scope-select'),
        ).not.toBeInTheDocument();
    });
});
