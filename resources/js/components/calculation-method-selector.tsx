import {
    HISTORICAL_CALCULATION_METHOD_HINT,
    hasHistoricalMethodBaseline,
    historicalMethodLabel,
    isHistoricalMethodActive,
    NO_CALCULATION_METHOD_MESSAGE,
    type CalculationMethodDraftState,
    type CalculationMethodOptions,
} from '@/lib/calculation-method-draft';
import { cn } from '@/lib/utils';

type Props = {
    positionIndex: number;
    options: CalculationMethodOptions | null | undefined;
    state: CalculationMethodDraftState;
    disabled?: boolean;
    error?: string;
    legendLabel?: string;
    onSelectLiveKey: (key: string) => void;
    onRestoreHistorical?: () => void;
};

/**
 * ADV-001c4b: sichtbare Berechnungsmethoden-Darstellung (0 / 1 / n / historisch).
 */
export function CalculationMethodSelector({
    positionIndex,
    options,
    state,
    disabled = false,
    error,
    legendLabel = 'Berechnungsmethode',
    onSelectLiveKey,
    onRestoreHistorical,
}: Props) {
    const methods = options?.methods ?? [];
    const historicalActive = isHistoricalMethodActive(state);
    const hasHistoricalBaseline = hasHistoricalMethodBaseline(state);
    const showLiveChooser =
        methods.length > 1 || (hasHistoricalBaseline && methods.length > 0);
    const showSingleReadonly =
        !historicalActive && !hasHistoricalBaseline && methods.length === 1;
    const fieldError =
        error ??
        (methods.length === 0 && !historicalActive
            ? NO_CALCULATION_METHOD_MESSAGE
            : undefined);
    const groupName = `calculation-method-${positionIndex}`;

    if (methods.length === 0 && !historicalActive) {
        return (
            <div
                className="space-y-1.5"
                data-test={`calculation-method-empty-${positionIndex}`}
            >
                <p className="text-sm font-medium">Berechnungsmethode</p>
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test={`calculation-method-unavailable-${positionIndex}`}
                >
                    {NO_CALCULATION_METHOD_MESSAGE}
                </p>
            </div>
        );
    }

    return (
        <div
            className="space-y-3"
            data-test={`calculation-method-selector-${positionIndex}`}
        >
            {historicalActive ? (
                <div
                    className="border-border bg-muted/30 rounded-xl border-2 border-dashed p-4"
                    data-test={`calculation-method-historical-${positionIndex}`}
                >
                    <p className="text-sm font-medium">
                        Historische Berechnungsmethode
                    </p>
                    <p
                        className="text-foreground mt-1 text-sm font-semibold"
                        data-test={`calculation-method-historical-label-${positionIndex}`}
                    >
                        {historicalMethodLabel(state)}
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">
                        {HISTORICAL_CALCULATION_METHOD_HINT}
                    </p>
                    <span className="sr-only" aria-live="polite">
                        Historische Berechnungsmethode aktiv:{' '}
                        {historicalMethodLabel(state)}
                    </span>
                </div>
            ) : null}

            {showSingleReadonly ? (
                <div
                    className="space-y-1.5"
                    data-test={`calculation-method-single-${positionIndex}`}
                >
                    <p className="text-sm font-medium">{legendLabel}</p>
                    <p
                        className="text-foreground text-sm font-semibold"
                        data-test={`calculation-method-single-label-${positionIndex}`}
                    >
                        {legendLabel}: {methods[0].name}
                    </p>
                    {methods[0].help_text ? (
                        <p className="text-muted-foreground text-sm">
                            {methods[0].help_text}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {showLiveChooser ? (
                <fieldset
                    className="space-y-3"
                    data-test={`calculation-method-fieldset-${positionIndex}`}
                >
                    <legend className="text-sm font-medium">
                        {historicalActive || hasHistoricalBaseline
                            ? 'Auf aktuelle Berechnungsmethode wechseln'
                            : legendLabel}
                    </legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {methods.map((method) => {
                            const selected =
                                !historicalActive &&
                                state.calculation_method_key === method.key;
                            const inputId = `${groupName}-${method.key}`;

                            return (
                                <label
                                    key={method.key}
                                    htmlFor={inputId}
                                    className={cn(
                                        'relative flex cursor-pointer flex-col gap-1 rounded-xl border-2 p-4 transition-all outline-none',
                                        'focus-within:ring-primary/25 focus-within:ring-[3px]',
                                        selected
                                            ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                                            : 'border-border/80 bg-card hover:border-primary/45 hover:bg-muted/25',
                                        disabled &&
                                            'cursor-not-allowed opacity-50',
                                    )}
                                    data-test={`calculation-method-option-${positionIndex}-${method.key}`}
                                >
                                    <div className="flex items-start gap-3">
                                        <input
                                            id={inputId}
                                            type="radio"
                                            name={groupName}
                                            value={method.key}
                                            checked={selected}
                                            disabled={disabled}
                                            onChange={() =>
                                                onSelectLiveKey(method.key)
                                            }
                                            className="border-primary text-primary focus-visible:ring-primary/40 mt-0.5 size-4 shrink-0 accent-[var(--primary)] focus-visible:ring-[3px] focus-visible:outline-none"
                                            data-test={`calculation-method-radio-${positionIndex}-${method.key}`}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="text-foreground block text-sm font-semibold">
                                                {method.name}
                                                {method.is_default ? (
                                                    <span className="text-muted-foreground ml-2 text-xs font-medium">
                                                        (Standard)
                                                    </span>
                                                ) : null}
                                            </span>
                                            {method.help_text ? (
                                                <span className="text-muted-foreground mt-1 block text-sm">
                                                    {method.help_text}
                                                </span>
                                            ) : null}
                                            {selected ? (
                                                <span className="text-primary mt-2 block text-xs font-medium">
                                                    Ausgewählt
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground mt-2 block text-xs">
                                                    Tippen zum Auswählen
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                </label>
                            );
                        })}
                    </div>
                    {!historicalActive &&
                    hasHistoricalBaseline &&
                    onRestoreHistorical ? (
                        <button
                            type="button"
                            className="text-primary text-sm font-medium underline-offset-2 hover:underline"
                            disabled={disabled}
                            onClick={onRestoreHistorical}
                            data-test={`calculation-method-restore-historical-${positionIndex}`}
                        >
                            Historische Methode beibehalten
                        </button>
                    ) : null}
                </fieldset>
            ) : null}

            {fieldError ? (
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test={`calculation-method-error-${positionIndex}`}
                >
                    {fieldError}
                </p>
            ) : null}
        </div>
    );
}
