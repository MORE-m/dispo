import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderUploadsSection } from '@/components/dispo-order-uploads-section';
import type { DispoOrderUpload } from '@/types/dispo-order';

const mockVisit = vi.fn();
const mockJsonPost = vi.fn();

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
        jsonPost: (...args: unknown[]) => mockJsonPost(...args),
        JsonPostError: actual.JsonPostError,
    };
});

const activeUpload: DispoOrderUpload = {
    id: 3,
    category: 'customer_confirmation',
    category_label: 'Kundenbestätigung',
    original_filename: 'freigabe.pdf',
    mime_type: 'application/pdf',
    size_bytes: 4096,
    sha256: 'sha',
    uploaded_by_name: 'Sales',
    uploaded_at: '2026-09-25T12:00:00+00:00',
    archived: false,
    archived_at: null,
    archived_by_name: null,
    is_active_customer_confirmation: true,
    download_url: '/dispoauftraege/1/uploads/3/download',
};

const archivedUpload: DispoOrderUpload = {
    ...activeUpload,
    id: 2,
    original_filename: 'alt.pdf',
    archived: true,
    archived_at: '2026-09-24T12:00:00+00:00',
    archived_by_name: 'Admin',
    is_active_customer_confirmation: false,
    download_url: '/dispoauftraege/1/uploads/2/download',
};

describe('DispoOrderUploadsSection', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPost.mockReset();
    });

    it('renders empty state', () => {
        render(
            <DispoOrderUploadsSection
                orderId={1}
                lockVersion={1}
                uploads={[]}
            />,
        );

        expect(screen.getByTestId('dispo-order-uploads')).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-uploads-empty'),
        ).toBeInTheDocument();
    });

    it('lists uploads with download and archive for admin', async () => {
        mockJsonPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderUploadsSection
                orderId={1}
                lockVersion={5}
                canArchive
                uploads={[activeUpload, archivedUpload]}
            />,
        );

        expect(screen.getByTestId('dispo-order-upload-3')).toHaveTextContent(
            'freigabe.pdf',
        );
        expect(
            screen.getByTestId('dispo-order-upload-download-3'),
        ).toHaveAttribute('href', activeUpload.download_url);
        expect(
            screen.getByTestId('dispo-order-upload-archive'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Löschen'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('dispo-order-upload-archive'));

        await waitFor(() => {
            expect(mockJsonPost).toHaveBeenCalledWith(
                '/dispoauftraege/1/uploads/3/archivieren',
                { lock_version: 5 },
            );
        });
    });

    it('hides archive button without canArchive', () => {
        render(
            <DispoOrderUploadsSection
                orderId={1}
                lockVersion={1}
                uploads={[activeUpload]}
            />,
        );

        expect(
            screen.queryByTestId('dispo-order-upload-archive'),
        ).not.toBeInTheDocument();
    });

    it('shows dynamic field label context for dynamic_field uploads', () => {
        const dynamicUpload: DispoOrderUpload = {
            ...activeUpload,
            id: 11,
            category: 'dynamic_field',
            category_label: 'Dynamisches Feld',
            original_filename: 'anhang.pdf',
            field_key: 'anhang',
            field_label: 'Anhang Dispo',
            position_id: 42,
            position_label: 'Position 1 · Radio',
            is_active_customer_confirmation: false,
            download_url: '/dispoauftraege/1/uploads/11/download',
        };

        render(
            <DispoOrderUploadsSection
                orderId={1}
                lockVersion={1}
                uploads={[dynamicUpload]}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-upload-field-context-11'),
        ).toHaveTextContent('Anhang Dispo · Position 1 · Radio');
    });

    it('renders audio player for audio_motif uploads', () => {
        const audioUpload: DispoOrderUpload = {
            ...activeUpload,
            id: 9,
            category: 'audio_motif',
            category_label: 'Audio-Motiv',
            original_filename: 'motif.mp3',
            mime_type: 'audio/mpeg',
            is_active_customer_confirmation: false,
            download_url: '/dispoauftraege/1/uploads/9/download',
            stream_url: '/dispoauftraege/1/uploads/9/stream',
        };

        render(
            <DispoOrderUploadsSection
                orderId={1}
                lockVersion={1}
                uploads={[audioUpload]}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-upload-audio-9'),
        ).toHaveAttribute('src', audioUpload.stream_url);
        expect(screen.getByText('Audio-Motiv')).toBeInTheDocument();
    });
});
