import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderMaterialUploadSection } from '@/components/dispo-order-material-upload-section';
import { JsonPostError } from '@/lib/json-post';

const mockVisit = vi.fn();
const mockReload = vi.fn();
const mockFormDataPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (...args: unknown[]) => mockVisit(...args),
        reload: (...args: unknown[]) => mockReload(...args),
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

const categories = [
    { value: 'audio_motif', label: 'Audio-Motiv' },
    { value: 'briefing', label: 'Briefing' },
    { value: 'script_text', label: 'Skript/Text' },
    { value: 'layout_graphics', label: 'Layout/Grafik' },
    { value: 'event_documents', label: 'Event-Unterlagen' },
    { value: 'other', label: 'Sonstiges' },
];

describe('DispoOrderMaterialUploadSection', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockReload.mockReset();
        mockFormDataPost.mockReset();
    });

    it('renders category dropdown without customer_confirmation', () => {
        render(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={3}
                categories={categories}
            />,
        );

        const select = screen.getByTestId(
            'dispo-order-material-upload-category',
        ) as HTMLSelectElement;
        const values = Array.from(select.options).map((option) => option.value);

        expect(values).toEqual([
            'audio_motif',
            'briefing',
            'script_text',
            'layout_graphics',
            'event_documents',
            'other',
        ]);
        expect(values).not.toContain('customer_confirmation');
    });

    it('shows audio hint and multi accept for audio_motif', () => {
        render(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={3}
                categories={categories}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-material-upload-hint'),
        ).toHaveTextContent('MP3/WAV, maximal 50 MB pro Datei');

        const input = screen.getByTestId(
            'dispo-order-material-upload-file',
        ) as HTMLInputElement;
        expect(input.multiple).toBe(true);
        expect(input.accept).toContain('.mp3');
    });

    it('shows generic size hint for non-audio categories', () => {
        render(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={3}
                categories={categories}
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-material-upload-category'),
            { target: { value: 'briefing' } },
        );

        expect(
            screen.getByTestId('dispo-order-material-upload-hint'),
        ).toHaveTextContent('Maximal 50 MB pro Datei');

        const input = screen.getByTestId(
            'dispo-order-material-upload-file',
        ) as HTMLInputElement;
        expect(input.multiple).toBe(false);
    });

    it('uploads sequentially and reloads on success', async () => {
        mockFormDataPost.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });

        render(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={7}
                categories={categories}
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-material-upload-category'),
            { target: { value: 'briefing' } },
        );

        const file = new File(['briefing'], 'briefing.pdf', {
            type: 'application/pdf',
        });
        fireEvent.change(screen.getByTestId('dispo-order-material-upload-file'), {
            target: { files: [file] },
        });
        fireEvent.click(screen.getByTestId('dispo-order-material-upload-submit'));

        await waitFor(() => {
            expect(mockFormDataPost).toHaveBeenCalledTimes(1);
            expect(mockVisit).toHaveBeenCalledWith(
                '/dispoauftraege/1',
                expect.any(Object),
            );
        });

        const body = mockFormDataPost.mock.calls[0][1] as FormData;
        expect(body.get('category')).toBe('briefing');
        expect(body.get('lock_version')).toBe('7');
    });

    it('keeps mixed report visible and retries only failed files with new lock_version', async () => {
        mockFormDataPost
            .mockResolvedValueOnce({
                message: 'ok',
                redirect: '/dispoauftraege/1',
            })
            .mockRejectedValueOnce(
                new JsonPostError(
                    'Audio-Motive sind nur als MP3 oder WAV zulässig.',
                    {
                        file: [
                            'Audio-Motive sind nur als MP3 oder WAV zulässig.',
                        ],
                    },
                    422,
                ),
            )
            .mockResolvedValueOnce({
                message: 'ok',
                redirect: '/dispoauftraege/1',
            });

        const { rerender } = render(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={4}
                categories={categories}
            />,
        );

        const good = new File(['a'], 'valid.mp3', { type: 'audio/mpeg' });
        const bad = new File(['%PDF'], 'spoof.mp3', {
            type: 'application/pdf',
        });
        fireEvent.change(screen.getByTestId('dispo-order-material-upload-file'), {
            target: { files: [good, bad] },
        });
        fireEvent.click(screen.getByTestId('dispo-order-material-upload-submit'));

        await waitFor(() => {
            expect(mockFormDataPost).toHaveBeenCalledTimes(2);
            expect(mockReload).toHaveBeenCalledWith(
                expect.objectContaining({
                    only: expect.arrayContaining(['uploads', 'order']),
                }),
            );
            expect(mockVisit).not.toHaveBeenCalled();
        });

        expect(
            screen.getByTestId('dispo-order-material-upload-mixed-report'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('dispo-order-material-upload-successes'),
        ).toHaveTextContent('valid.mp3');
        expect(
            screen.getByTestId('dispo-order-material-upload-partial-failures'),
        ).toHaveTextContent('spoof.mp3');
        expect(
            screen.getByTestId('dispo-order-material-upload-partial-failures'),
        ).toHaveTextContent('Audio-Motive sind nur als MP3 oder WAV zulässig.');
        expect(
            screen.getByTestId('dispo-order-material-upload-pending-files'),
        ).toHaveTextContent('spoof.mp3');
        expect(
            screen.getByTestId('dispo-order-material-upload-pending-files'),
        ).not.toHaveTextContent('valid.mp3');

        const second = mockFormDataPost.mock.calls[1][1] as FormData;
        expect(second.get('lock_version')).toBe('5');

        // Nach Reload liefert die Seite die fortgeschriebene lock_version.
        rerender(
            <DispoOrderMaterialUploadSection
                orderId={1}
                lockVersion={5}
                categories={categories}
            />,
        );

        fireEvent.click(screen.getByTestId('dispo-order-material-upload-submit'));

        await waitFor(() => {
            expect(mockFormDataPost).toHaveBeenCalledTimes(3);
        });

        const retry = mockFormDataPost.mock.calls[2][1] as FormData;
        expect(retry.get('lock_version')).toBe('5');
        expect((retry.get('file') as File).name).toBe('spoof.mp3');

        // Erneuter Versuch nur für die fehlgeschlagene Datei – kein zweites valid.mp3.
        expect(
            mockFormDataPost.mock.calls.map(
                (call) => (call[1] as FormData).get('file') as File,
            ).map((file) => file.name),
        ).toEqual(['valid.mp3', 'spoof.mp3', 'spoof.mp3']);
    });
});
