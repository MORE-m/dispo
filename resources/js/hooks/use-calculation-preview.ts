import { useEffect, useRef, useState } from 'react';
import { JsonPostError, jsonPost } from '@/lib/json-post';

type PreviewOptions = {
    url: string;
    payload: unknown;
    enabled: boolean;
    blocked: boolean;
    debounceMs?: number;
};

type PreviewState<TTotals> = {
    totals: TTotals | null;
    previewLoading: boolean;
    error: string | null;
    fieldErrors: Record<string, string[]>;
};

export function useCalculationPreview<TTotals>({
    url,
    payload,
    enabled,
    blocked,
    debounceMs = 280,
}: PreviewOptions): PreviewState<TTotals> {
    const [totals, setTotals] = useState<TTotals | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );

    const previewSeq = useRef(0);
    const pendingRef = useRef(false);
    const controllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        if (blocked) {
            pendingRef.current = true;
            return;
        }

        pendingRef.current = false;
        const seq = ++previewSeq.current;
        const controller = new AbortController();
        controllerRef.current = controller;

        // Sofort invalidieren: alter Preis darf nicht als aktuell sichtbar bleiben,
        // während Debounce/Request zur neuen Payload laufen.
        setTotals(null);
        setError(null);
        setFieldErrors({});
        setPreviewLoading(true);

        const handle = window.setTimeout(() => {
            void jsonPost<{ totals: TTotals }>(url, payload, controller.signal)
                .then((data) => {
                    if (seq !== previewSeq.current) {
                        return;
                    }

                    setTotals(data.totals);
                    setError(null);
                    setFieldErrors({});
                })
                .catch((caught: unknown) => {
                    if (
                        caught instanceof DOMException &&
                        caught.name === 'AbortError'
                    ) {
                        return;
                    }

                    if (seq !== previewSeq.current) {
                        return;
                    }

                    setTotals(null);

                    if (caught instanceof JsonPostError) {
                        setFieldErrors(caught.fieldErrors);
                        setError(caught.message);
                        return;
                    }

                    setError(
                        caught instanceof Error
                            ? caught.message
                            : 'Berechnung nicht möglich.',
                    );
                })
                .finally(() => {
                    if (seq !== previewSeq.current) {
                        return;
                    }

                    setPreviewLoading(false);
                });
        }, debounceMs);

        return () => {
            window.clearTimeout(handle);
            controller.abort();
            controllerRef.current = null;
        };
    }, [url, payload, enabled, blocked, debounceMs]);

    return { totals, previewLoading, error, fieldErrors };
}
