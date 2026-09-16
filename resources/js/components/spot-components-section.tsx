import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    COMPONENT_STRATEGY_LABELS,
    type ComponentCalculationStrategy,
    type SpotComponentDraft,
    addAllonge,
    hasAllonge,
    removeAllonge,
    totalComponentLength,
    updateComponentLength,
} from '@/lib/spot-components';

type ComponentResult = {
    role: string;
    label: string;
    length_seconds: number;
    length_index?: number | null;
    media_gross?: string | null;
};

type SpotComponentsSectionProps = {
    positionIndex: number;
    components: SpotComponentDraft[];
    strategy: ComponentCalculationStrategy;
    canEdit: boolean;
    positionMediaGross?: string | null;
    componentResults?: ComponentResult[];
    lengthIndex?: number | null;
    errors?: Record<string, string[]>;
    onChange: (components: SpotComponentDraft[]) => void;
    onDeactivate: () => void;
};

export function SpotComponentsSection({
    positionIndex,
    components,
    strategy,
    canEdit,
    positionMediaGross,
    componentResults = [],
    lengthIndex,
    errors = {},
    onChange,
    onDeactivate,
}: SpotComponentsSectionProps) {
    const total = totalComponentLength(components);
    const allongePresent = hasAllonge(components);
    const strategyLabel = COMPONENT_STRATEGY_LABELS[strategy];
    const isIndividual = strategy === 'individual';

    const resultByRole = Object.fromEntries(
        componentResults.map((row) => [row.role, row]),
    );

    return (
        <section
            className="space-y-3 rounded-lg border p-3"
            data-test={`spot-components-section-${positionIndex}`}
            aria-labelledby={`spot-components-heading-${positionIndex}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3
                        id={`spot-components-heading-${positionIndex}`}
                        className="text-sm font-semibold"
                    >
                        Spot-Komponenten
                    </h3>
                    <p
                        className="text-muted-foreground mt-1 text-xs"
                        data-test={`spot-components-strategy-hint-${positionIndex}`}
                    >
                        Strategie (administrativ vorgegeben): {strategyLabel}
                    </p>
                </div>
                {canEdit ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`spot-components-deactivate-${positionIndex}`}
                        onClick={onDeactivate}
                    >
                        Komponenten deaktivieren
                    </Button>
                ) : null}
            </div>

            <p
                className="text-sm"
                data-test={`spot-components-total-length-${positionIndex}`}
            >
                Gesamtlänge: {total}s
                {!isIndividual && lengthIndex != null
                    ? ` · Index ${lengthIndex}`
                    : ''}
                {!isIndividual && positionMediaGross
                    ? ` · Brutto ${positionMediaGross}`
                    : ''}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                {components.map((component) => {
                    const result = resultByRole[component.role];
                    const fieldId = `component-${component.role}-${positionIndex}`;
                    const errorKey = `positions.${positionIndex}.components`;

                    return (
                        <div
                            key={component.role}
                            className="space-y-2 rounded-md border p-3"
                            data-test={`spot-component-${component.role}-${positionIndex}`}
                        >
                            <FormField
                                label={`${component.label} (Sekunden)`}
                                htmlFor={fieldId}
                                error={
                                    errors[`${errorKey}.${component.role}`]?.[0]
                                }
                            >
                                <Input
                                    id={fieldId}
                                    type="number"
                                    min={1}
                                    inputMode="numeric"
                                    disabled={!canEdit}
                                    value={component.length_seconds}
                                    data-test={`spot-component-length-${component.role}-${positionIndex}`}
                                    aria-label={`${component.label} Länge in Sekunden`}
                                    onChange={(event) =>
                                        onChange(
                                            updateComponentLength(
                                                components,
                                                component.role,
                                                Number(event.target.value),
                                            ),
                                        )
                                    }
                                />
                            </FormField>
                            {isIndividual ? (
                                <p className="text-muted-foreground text-xs">
                                    Index: {result?.length_index ?? '–'}
                                    {result?.media_gross
                                        ? ` · Brutto ${result.media_gross}`
                                        : ''}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            {canEdit ? (
                <div className="flex flex-wrap gap-2">
                    {!allongePresent ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test={`spot-components-add-allonge-${positionIndex}`}
                            onClick={() => onChange(addAllonge(components))}
                        >
                            Allonge hinzufügen
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test={`spot-components-remove-allonge-${positionIndex}`}
                            onClick={() => onChange(removeAllonge(components))}
                        >
                            Allonge entfernen
                        </Button>
                    )}
                </div>
            ) : null}

            {isIndividual && positionMediaGross ? (
                <p
                    className="text-sm font-medium"
                    data-test={`spot-components-position-gross-${positionIndex}`}
                >
                    Positionsbrutto: {positionMediaGross}
                </p>
            ) : null}
        </section>
    );
}
