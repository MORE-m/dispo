import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SchemaFileFields,
    type SchemaFileField,
} from '@/components/dynamic-fields/schema-file-fields';

const mockVisit = vi.fn();
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
        formDataPost: (...args: unknown[]) => mockFormDataPost(...args),
        JsonPostError: actual.JsonPostError,
    };
});

const fileField: SchemaFileField = {
    key: 'anhang',
    label: 'Anhang',
    field_type: 'file',
    validation_json: { allowed_mime_types: ['application/pdf'] },
};

describe('SchemaFileFields', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockFormDataPost.mockReset();
    });

    it('uploads dynamic field file and redirects on success', async () => {
        mockFormDataPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/5',
        });

        render(
            <SchemaFileFields
                fields={[fileField]}
                orderId={5}
                lockVersion={2}
                positionId={9}
                canUpload
            />,
        );

        const file = new File(['pdf'], 'anhang.pdf', {
            type: 'application/pdf',
        });
        fireEvent.change(screen.getByTestId('schema-file-input-anhang'), {
            target: { files: [file] },
        });
        fireEvent.click(screen.getByTestId('schema-file-upload-anhang'));

        await waitFor(() => {
            expect(mockFormDataPost).toHaveBeenCalledWith(
                '/dispoauftraege/5/uploads/dynamisches-feld',
                expect.any(FormData),
            );
            expect(mockVisit).toHaveBeenCalledWith(
                '/dispoauftraege/5',
                expect.any(Object),
            );
        });

        const body = mockFormDataPost.mock.calls[0][1] as FormData;
        expect(body.get('lock_version')).toBe('2');
        expect(body.get('field_key')).toBe('anhang');
        expect(body.get('position_id')).toBe('9');
    });
});
