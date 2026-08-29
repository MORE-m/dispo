import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

type WizardStepperProps = {
    steps: readonly string[];
    currentStep: number;
    onStepChange: (index: number) => void;
};

export function WizardStepper({
    steps,
    currentStep,
    onStepChange,
}: WizardStepperProps) {
    return (
        <nav aria-label="Fortschritt">
            <ol className="flex w-full items-center">
                {steps.map((label, index) => {
                    const isComplete = index < currentStep;
                    const isActive = index === currentStep;
                    const isLast = index === steps.length - 1;

                    return (
                        <li
                            key={label}
                            className={cn(
                                'flex items-center',
                                isLast ? 'shrink-0' : 'min-w-0 flex-1',
                            )}
                        >
                            <button
                                type="button"
                                onClick={() => onStepChange(index)}
                                aria-label={`${index + 1}. ${label}`}
                                aria-current={isActive ? 'step' : undefined}
                                className="group focus-visible:ring-ring/50 flex min-w-0 items-center gap-2 rounded-md outline-none focus-visible:ring-[3px]"
                            >
                                <span
                                    className={cn(
                                        'flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors',
                                        isComplete || isActive
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-card text-muted-foreground',
                                    )}
                                >
                                    {isComplete ? (
                                        <Check
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'hidden truncate text-sm font-medium sm:inline',
                                        isActive
                                            ? 'text-foreground'
                                            : isComplete
                                              ? 'text-primary'
                                              : 'text-muted-foreground',
                                    )}
                                >
                                    {index + 1}. {label}
                                </span>
                            </button>
                            {!isLast ? (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'mx-2 h-0.5 min-w-4 flex-1 rounded-full',
                                        isComplete ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
