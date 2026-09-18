import { FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    fixedPriceSettlementValidationMessage,
    type PricingSettlementMode,
} from '@/lib/pricing-settlement';

type Props = {
    positionIndex: number;
    mode: PricingSettlementMode;
    fixedPriceNnInput: string;
    disabled?: boolean;
    showFixedPriceValidation: boolean;
    fieldError?: string;
    onModeChange: (mode: PricingSettlementMode) => void;
    onFixedPriceInputChange: (value: string) => void;
};

export function PricingSettlementSection({
    positionIndex,
    mode,
    fixedPriceNnInput,
    disabled = false,
    showFixedPriceValidation,
    fieldError,
    onModeChange,
    onFixedPriceInputChange,
}: Props) {
    const groupName = `pricing-settlement-${positionIndex}`;
    const localMessage =
        showFixedPriceValidation && mode === 'fixed_price'
            ? fixedPriceSettlementValidationMessage({
                  pricing_settlement_mode: mode,
                  fixed_price_nn_input: fixedPriceNnInput,
              })
            : null;
    const error = fieldError ?? localMessage ?? undefined;

    return (
        <fieldset
            className="space-y-3"
            data-test={`pricing-settlement-mode-${positionIndex}`}
        >
            <legend className="text-sm font-medium">Preisabschluss</legend>
            <div className="grid gap-3 sm:grid-cols-2">
                {(
                    [
                        {
                            key: 'normal' as const,
                            title: 'Normal',
                            description:
                                'Positions- und Auftragsrabatte sowie AE wirken auf den N/N-Betrag.',
                        },
                        {
                            key: 'fixed_price' as const,
                            title: 'Festpreis (N/N)',
                            description:
                                'Vereinbarter N/N-Endbetrag; Mediabrutto bleibt referenzbasiert.',
                        },
                    ] as const
                ).map((option) => {
                    const selected = mode === option.key;
                    const inputId = `${groupName}-${option.key}`;

                    return (
                        <label
                            key={option.key}
                            htmlFor={inputId}
                            className={cn(
                                'relative flex cursor-pointer flex-col gap-1 rounded-xl border-2 p-4 transition-all outline-none',
                                'focus-within:ring-primary/25 focus-within:ring-[3px]',
                                selected
                                    ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                                    : 'border-border/80 bg-card hover:border-primary/45 hover:bg-muted/25',
                                disabled && 'cursor-not-allowed opacity-50',
                            )}
                            data-test={`pricing-settlement-option-${positionIndex}-${option.key}`}
                        >
                            <div className="flex items-start gap-3">
                                <input
                                    id={inputId}
                                    type="radio"
                                    name={groupName}
                                    value={option.key}
                                    checked={selected}
                                    disabled={disabled}
                                    onChange={() => onModeChange(option.key)}
                                    className="border-primary text-primary focus-visible:ring-primary/40 mt-0.5 size-4 shrink-0 accent-[var(--primary)] focus-visible:ring-[3px] focus-visible:outline-none"
                                    data-test={`pricing-settlement-radio-${positionIndex}-${option.key}`}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="text-foreground block text-sm font-semibold">
                                        {option.title}
                                    </span>
                                    <span className="text-muted-foreground mt-1 block text-sm">
                                        {option.description}
                                    </span>
                                </span>
                            </div>
                        </label>
                    );
                })}
            </div>

            {mode === 'fixed_price' ? (
                <FormField
                    label="Vereinbarter N/N-Festpreis"
                    htmlFor={`fixed-price-nn-${positionIndex}`}
                    hint="Endbetrag N/N in Euro (ohne weitere Rabattlogik auf Positionsrabatte)."
                >
                    <Input
                        id={`fixed-price-nn-${positionIndex}`}
                        inputMode="decimal"
                        value={fixedPriceNnInput}
                        disabled={disabled}
                        data-test={`fixed-price-nn-${positionIndex}`}
                        onChange={(event) =>
                            onFixedPriceInputChange(event.target.value)
                        }
                    />
                </FormField>
            ) : null}

            {error ? (
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test={`pricing-settlement-error-${positionIndex}`}
                >
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
