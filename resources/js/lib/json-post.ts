import { mapValidationErrors } from './validation-errors';

export class JsonPostError extends Error {
    constructor(
        message: string,
        public fieldErrors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'JsonPostError';
    }
}

type ValidationPayload = {
    message?: string;
    errors?: Record<string, string[]>;
};

export async function jsonPost<T>(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    );

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
        signal,
    });

    const data = (await response.json()) as T & ValidationPayload;

    if (!response.ok) {
        throw new JsonPostError(
            data.message ?? 'Die Anfrage ist fehlgeschlagen.',
            mapValidationErrors(data.errors ?? {}),
        );
    }

    return data;
}
