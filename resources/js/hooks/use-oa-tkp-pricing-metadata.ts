import { useEffect, useRef, useState } from 'react';
import { JsonPostError } from '@/lib/json-post';
import { mapValidationErrors } from '@/lib/validation-errors';
import type { OaTkpPricingMetadata } from '@/lib/oa-tkp-wizard';

type Triple = {
    clientKey: string;
    inventoryId: number;
    advertisingMediumId: number;
    priceYear: number;
};

type Options = {
    enabled: boolean;
    /** Wenn false: kein Fetch – Historical Pin bleibt unberührt. */
    shouldFetch: boolean;
    triple: Triple | null;
    url?: string;
};

type State = {
    loading: boolean;
    error: string | null;
    fieldErrors: Record<string, string[]>;
    metadata: OaTkpPricingMetadata | null;
};

function xsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    );
}

async function fetchMetadata(
    url: string,
    triple: Triple,
    signal: AbortSignal,
): Promise<OaTkpPricingMetadata> {
    const params = new URLSearchParams({
        advertising_medium_id: String(triple.advertisingMediumId),
        inventory_id: String(triple.inventoryId),
        price_year: String(triple.priceYear),
    });

    const response = await fetch(`${url}?${params.toString()}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        signal,
    });

    const text = await response.text();
    let data: {
        metadata?: OaTkpPricingMetadata;
        message?: string;
        errors?: Record<string, string[]>;
    } = {};

    if (text !== '') {
        try {
            data = JSON.parse(text) as typeof data;
        } catch {
            data = { message: 'Pricing Metadata konnte nicht geladen werden.' };
        }
    }

    if (!response.ok) {
        throw new JsonPostError(
            data.message ?? 'Pricing Metadata konnte nicht geladen werden.',
            mapValidationErrors(data.errors ?? {}),
            response.status,
        );
    }

    if (!data.metadata) {
        throw new JsonPostError(
            'Pricing Metadata Antwort unvollständig.',
            {},
            response.status,
        );
    }

    return data.metadata;
}

/**
 * PO-CALC-TKP-1D2b: Metadata pro Position-Triple mit Abort/Sequence.
 * Discover: Aufrufer kann Current und Next getrennt enablen.
 */
export function useOaTkpPricingMetadata({
    enabled,
    shouldFetch,
    triple,
    url = '/kalkulationen/oa-tkp/pricing-metadata',
}: Options): State {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [metadata, setMetadata] = useState<OaTkpPricingMetadata | null>(null);
    const seqRef = useRef(0);

    const tripleKey = triple
        ? `${triple.clientKey}|${triple.inventoryId}|${triple.advertisingMediumId}|${triple.priceYear}`
        : '';

    useEffect(() => {
        if (!enabled || !shouldFetch || triple === null) {
            return;
        }

        if (
            triple.inventoryId < 1 ||
            triple.advertisingMediumId < 1 ||
            triple.priceYear < 2000
        ) {
            return;
        }

        const seq = ++seqRef.current;
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        setFieldErrors({});

        void fetchMetadata(url, triple, controller.signal)
            .then((result) => {
                if (seq !== seqRef.current) {
                    return;
                }
                setMetadata(result);
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
                if (seq !== seqRef.current) {
                    return;
                }
                setMetadata(null);
                if (caught instanceof JsonPostError) {
                    setFieldErrors(caught.fieldErrors);
                    setError(caught.message);
                    return;
                }
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'Pricing Metadata nicht verfügbar.',
                );
            })
            .finally(() => {
                if (seq !== seqRef.current) {
                    return;
                }
                setLoading(false);
            });

        return () => {
            controller.abort();
        };
        // tripleKey stabilisiert das Triple; triple-Objekt selbst nicht nötig.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, shouldFetch, tripleKey, url]);

    return { loading, error, fieldErrors, metadata };
}

/**
 * Current + Next Year Discovery: lädt beide Jahre und liefert verfügbare Options.
 */
export function useOaTkpAvailablePriceYears({
    enabled,
    inventoryId,
    advertisingMediumId,
    currentYear,
    nextYear,
    clientKey,
}: {
    enabled: boolean;
    inventoryId: number;
    advertisingMediumId: number;
    currentYear: number;
    nextYear: number;
    clientKey: string;
}): {
    loading: boolean;
    years: number[];
    error: string | null;
} {
    const [loading, setLoading] = useState(false);
    const [years, setYears] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);
    const seqRef = useRef(0);

    useEffect(() => {
        if (!enabled || inventoryId < 1 || advertisingMediumId < 1) {
            return;
        }

        const seq = ++seqRef.current;
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        const candidates = [currentYear, nextYear].filter(
            (year, index, all) => all.indexOf(year) === index,
        );

        void Promise.all(
            candidates.map(async (year) => {
                try {
                    const meta = await fetchMetadata(
                        '/kalkulationen/oa-tkp/pricing-metadata',
                        {
                            clientKey,
                            inventoryId,
                            advertisingMediumId,
                            priceYear: year,
                        },
                        controller.signal,
                    );

                    return meta.price_year;
                } catch (caught) {
                    if (
                        caught instanceof DOMException &&
                        caught.name === 'AbortError'
                    ) {
                        throw caught;
                    }

                    return null;
                }
            }),
        )
            .then((resolved) => {
                if (seq !== seqRef.current) {
                    return;
                }
                setYears(
                    resolved.filter((year): year is number => year != null),
                );
            })
            .catch((caught: unknown) => {
                if (
                    caught instanceof DOMException &&
                    caught.name === 'AbortError'
                ) {
                    return;
                }
                if (seq !== seqRef.current) {
                    return;
                }
                setYears([]);
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'Preisjahre nicht verfügbar.',
                );
            })
            .finally(() => {
                if (seq !== seqRef.current) {
                    return;
                }
                setLoading(false);
            });

        return () => {
            controller.abort();
        };
    }, [
        enabled,
        inventoryId,
        advertisingMediumId,
        currentYear,
        nextYear,
        clientKey,
    ]);

    return { loading, years, error };
}
