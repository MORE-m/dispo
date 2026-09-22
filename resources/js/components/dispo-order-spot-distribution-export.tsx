import { useState } from 'react';
import { Button } from '@/components/ui/button';

export type SpotDistributionExportProps = {
    can_export: boolean;
    enabled: boolean;
    has_calendar_positions?: boolean;
    has_average_positions?: boolean;
    mixed_order: boolean;
    hint_kind?: 'calendar_only' | 'average_only' | 'mixed' | null;
    disabled_reason: string | null;
    url: string;
};

type Props = {
    exportConfig: SpotDistributionExportProps | null | undefined;
};

function filenameFromDisposition(header: string | null): string | null {
    if (!header) {
        return null;
    }

    const utfMatch = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (utfMatch?.[1]) {
        try {
            return decodeURIComponent(utfMatch[1]);
        } catch {
            return utfMatch[1];
        }
    }

    const plainMatch = /filename="?([^";]+)"?/i.exec(header);
    return plainMatch?.[1] ?? null;
}

function messageFromErrorBody(text: string, status: number): string {
    if (text !== '') {
        try {
            const data = JSON.parse(text) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const exportErrors = data.errors?.export;
            if (Array.isArray(exportErrors) && exportErrors[0]) {
                return exportErrors[0];
            }
            if (data.message) {
                return data.message;
            }
        } catch {
            // fallback below
        }
    }

    if (status === 403) {
        return 'Keine Berechtigung für den Spotplanungs-Export.';
    }

    return 'Der Spotplanungs-Export ist fehlgeschlagen.';
}

function resolveHintKind(
    config: SpotDistributionExportProps,
): 'calendar_only' | 'average_only' | 'mixed' | null {
    if (config.hint_kind) {
        return config.hint_kind;
    }
    if (!config.enabled) {
        return null;
    }
    if (config.mixed_order) {
        return 'mixed';
    }
    if (config.has_average_positions && !config.has_calendar_positions) {
        return 'average_only';
    }
    if (config.has_calendar_positions) {
        return 'calendar_only';
    }

    return null;
}

function hintText(
    kind: 'calendar_only' | 'average_only' | 'mixed' | null,
): string | null {
    switch (kind) {
        case 'calendar_only':
            return 'Der Export enthält die konkrete Spotverteilung.';
        case 'average_only':
            return 'Der Export enthält einen unverbindlichen Planungsvorschlag. Die konkrete Einplanung erfolgt durch die Disposition.';
        case 'mixed':
            return 'Der Export enthält die konkrete Spotverteilung und einen separaten Planungsvorschlag für Average-Positionen.';
        default:
            return null;
    }
}

export function DispoOrderSpotDistributionExport({ exportConfig }: Props) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!exportConfig?.can_export) {
        return null;
    }

    const config = exportConfig;
    const disabled = !config.enabled || loading;
    const enabledHint = hintText(resolveHintKind(config));

    async function handleExport() {
        if (disabled) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const response = await fetch(config.url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const text = await response.text();
                throw new Error(messageFromErrorBody(text, response.status));
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download =
                filenameFromDisposition(
                    response.headers.get('Content-Disposition'),
                ) ?? 'Spotplanung.xlsx';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(objectUrl);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Der Spotplanungs-Export ist fehlgeschlagen.',
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <div
            className="flex flex-col gap-2"
            data-test="dispo-spot-distribution-export"
        >
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-disabled={disabled}
                    aria-busy={loading}
                    onClick={() => {
                        void handleExport();
                    }}
                    data-test="dispo-spot-distribution-export-button"
                >
                    {loading
                        ? 'Spotplanung wird erstellt…'
                        : 'Spotplanung exportieren (XLSX)'}
                </Button>
            </div>
            {enabledHint ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test="dispo-spot-distribution-export-hint"
                >
                    {enabledHint}
                </p>
            ) : null}
            {!config.enabled && config.disabled_reason ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test="dispo-spot-distribution-export-disabled-hint"
                >
                    {config.disabled_reason}
                </p>
            ) : null}
            {error ? (
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test="dispo-spot-distribution-export-error"
                >
                    {error}
                </p>
            ) : null}
        </div>
    );
}
