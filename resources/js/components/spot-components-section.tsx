import { FormField, money } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    COMPONENT_STRATEGY_LABELS,
    derivedAirings,
    type ComponentCalculationStrategy,
    type SpotComponentDraft,
    type SpotComponentProfile,
    addAllonge,
    hasAllonge,
    isForcedProfile,
    removeAllonge,
    totalComponentLength,
    updateComponentLength,
    updateComponentLengthBySort,
} from '@/lib/spot-components';

type ComponentResult = {
    role: string;
    label: string;
    length_seconds: number;
    sort?: number;
    length_index?: number | null;
    media_gross?: string | null;
};

type ProfileMeta = {
    unit_label: string;
    unit_count: number;
    slots: Array<{
        role: string;
        label: string;
        display_label?: string;
        sort: number;
    }>;
};

type SpotComponentsSectionProps = {
    positionIndex: number;
    components: SpotComponentDraft[];
    strategy: ComponentCalculationStrategy;
    canEdit: boolean;
    profile?: SpotComponentProfile | null;
    profileLabel?: string;
    profileMeta?: ProfileMeta;
    unitCount?: number;
    positionMediaGross?: string | null;
    componentResults?: ComponentResult[];
    lengthIndex?: number | null;
    errors?: Record<string, string[]>;
    onChange: (components: SpotComponentDraft[]) => void;
    onDeactivate: () => void;
};

function lengthTestId(
    component: SpotComponentDraft,
    positionIndex: number,
    forced: boolean,
): string {
    if (forced) {
        return `spot-component-length-${component.role}-${component.sort}-${positionIndex}`;
    }

    return `spot-component-length-${component.role}-${positionIndex}`;
}

function componentTestId(
    component: SpotComponentDraft,
    positionIndex: number,
    forced: boolean,
): string {
    if (forced) {
        return `spot-component-${component.role}-${component.sort}-${positionIndex}`;
    }

    return `spot-component-${component.role}-${positionIndex}`;
}

export function SpotComponentsSection({
    positionIndex,
    components,
    strategy,
    canEdit,
    profile = null,
    profileLabel,
    profileMeta,
    unitCount = 0,
    positionMediaGross,
    componentResults = [],
    lengthIndex,
    errors = {},
    onChange,
    onDeactivate,
}: SpotComponentsSectionProps) {
    const forced = isForcedProfile(profile);
    const sortedComponents = forced
        ? [...components].sort((a, b) => a.sort - b.sort)
        : components;
    const total = totalComponentLength(components);
    const allongePresent = hasAllonge(components);
    const strategyLabel = COMPONENT_STRATEGY_LABELS[strategy];
    const isIndividual = strategy === 'individual';
    const sectionTitle = forced
        ? (profileLabel ?? profileMeta?.unit_label ?? 'Spot-Komponenten')
        : 'Spot-Komponenten';

    const resultByRole = Object.fromEntries(
        componentResults.map((row) => [row.role, row]),
    );

    const derived =
        forced && profileMeta
            ? derivedAirings(unitCount, profileMeta.unit_count)
            : null;

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
                        {sectionTitle}
                    </h3>
                    <p
                        className="text-muted-foreground mt-1 text-xs"
                        data-test={`spot-components-strategy-hint-${positionIndex}`}
                    >
                        Strategie (administrativ vorgegeben): {strategyLabel}
                    </p>
                    {forced && profileMeta ? (
                        <p
                            className="text-muted-foreground mt-1 text-xs"
                            data-test={`spot-components-units-${positionIndex}`}
                        >
                            {profileMeta.unit_label}: {unitCount}
                            {derived !== null ? (
                                <>
                                    {' '}
                                    · abgeleitete Sendungen:{' '}
                                    <span
                                        data-test={`spot-components-derived-airings-${positionIndex}`}
                                    >
                                        {derived}
                                    </span>
                                </>
                            ) : null}
                        </p>
                    ) : null}
                </div>
                {canEdit && !forced ? (
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
                    ? ` · Brutto ${money(positionMediaGross)}`
                    : ''}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                {sortedComponents.map((component) => {
                    const result = forced
                        ? componentResults.find(
                              (row) =>
                                  row.role === component.role &&
                                  (row.sort ?? component.sort) ===
                                      component.sort,
                          )
                        : resultByRole[component.role];
                    const slot = profileMeta?.slots.find(
                        (entry) => entry.sort === component.sort,
                    );
                    const displayLabel =
                        slot?.display_label ?? slot?.label ?? component.label;
                    const fieldId = forced
                        ? `component-${component.role}-${component.sort}-${positionIndex}`
                        : `component-${component.role}-${positionIndex}`;
                    const errorKey = `positions.${positionIndex}.components`;
                    const resultTestId = forced
                        ? `spot-component-result-${component.role}-${component.sort}-${positionIndex}`
                        : `spot-component-result-${component.role}-${positionIndex}`;

                    return (
                        <div
                            key={`${component.role}-${component.sort}-${positionIndex}`}
                            className="space-y-2 rounded-md border p-3"
                            data-test={componentTestId(
                                component,
                                positionIndex,
                                forced,
                            )}
                        >
                            <FormField
                                label={`${displayLabel} (Sekunden)`}
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
                                    data-test={lengthTestId(
                                        component,
                                        positionIndex,
                                        forced,
                                    )}
                                    aria-label={`${displayLabel} Länge in Sekunden`}
                                    onChange={(event) =>
                                        onChange(
                                            forced
                                                ? updateComponentLengthBySort(
                                                      components,
                                                      component.sort,
                                                      Number(
                                                          event.target.value,
                                                      ),
                                                  )
                                                : updateComponentLength(
                                                      components,
                                                      component.role,
                                                      Number(
                                                          event.target.value,
                                                      ),
                                                  ),
                                        )
                                    }
                                />
                            </FormField>
                            {isIndividual ? (
                                <p
                                    className="text-muted-foreground text-xs"
                                    data-test={resultTestId}
                                >
                                    Index: {result?.length_index ?? '–'}
                                    {result?.media_gross
                                        ? ` · Brutto ${money(result.media_gross)}`
                                        : ''}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            {canEdit && !forced ? (
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
                    Positionsbrutto: {money(positionMediaGross)}
                </p>
            ) : null}
        </section>
    );
}
