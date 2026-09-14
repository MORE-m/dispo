import { BudgetDistributionRanges } from '@/components/budget-distribution-ranges';
import { FormField, formSelectClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';
import {
    emptyBudgetElement,
    type BudgetElementDraft,
} from '@/lib/budget-planning';
import type { DayGroupOption } from '@/lib/pricing-time';
import type { PriceYearOption } from '@/lib/price-list-year-selection';

export function BudgetElementsStep({
    elements,
    inventories,
    dayGroups,
    canEdit,
    fieldErrors,
    priceYear,
    priceYearOptions,
    onPriceYearChange,
    onChange,
}: {
    elements: BudgetElementDraft[];
    inventories: Array<{
        id: number;
        name: string;
        is_active: boolean;
    }>;
    dayGroups: DayGroupOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    priceYear: number;
    priceYearOptions: PriceYearOption[];
    onPriceYearChange: (year: number) => void;
    onChange: (elements: BudgetElementDraft[]) => void;
}) {
    const activeInventories = inventories.filter((item) => item.is_active);
    const selectedYearOption = priceYearOptions.find(
        (option) => option.year === priceYear,
    );

    function updateElement(index: number, patch: Partial<BudgetElementDraft>) {
        onChange(
            elements.map((element, current) =>
                current === index ? { ...element, ...patch } : element,
            ),
        );
    }

    function removeElement(index: number) {
        if (elements.length <= 1) {
            return;
        }
        onChange(elements.filter((_, current) => current !== index));
    }

    function addElement() {
        const defaultLength = elements[0]?.spot_length_seconds ?? 30;
        onChange([...elements, emptyBudgetElement(defaultLength)]);
    }

    return (
        <div className="space-y-4" data-test="budget-elements-step">
            <Card className={wizardCardClass} data-test="budget-price-year-card">
                <CardHeader className={wizardCardHeaderClass}>
                    <CardTitle className={wizardCardTitleClass}>
                        Preisjahr
                    </CardTitle>
                </CardHeader>
                <CardContent className={`${wizardCardContentClass} space-y-2`}>
                    <FormField
                        label="Preisjahr für alle Werbeelemente"
                        htmlFor="budget-price-year"
                        hint="Das Folgejahr erscheint nur, wenn jedes gewählte Inventar eine aktive Folgejahresliste besitzt."
                    >
                        <select
                            id="budget-price-year"
                            data-test="budget-price-year"
                            className={formSelectClass}
                            value={priceYear}
                            disabled={!canEdit}
                            onChange={(event) =>
                                onPriceYearChange(Number(event.target.value))
                            }
                        >
                            {priceYearOptions.map((option) => (
                                <option
                                    key={option.year}
                                    value={option.year}
                                    disabled={!option.available}
                                >
                                    {option.year}
                                    {option.available
                                        ? ''
                                        : ' · keine aktive Liste'}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    {selectedYearOption && !selectedYearOption.available ? (
                        <p
                            className="text-destructive text-xs"
                            data-test="budget-price-year-missing"
                        >
                            Für mindestens ein Inventar fehlt die aktive
                            Preisliste dieses Jahres. Vorschlag und Übernahme
                            sind blockiert.
                        </p>
                    ) : null}
                </CardContent>
            </Card>
            {elements.map((element, index) => {
                const inventory = inventories.find(
                    (item) => item.id === element.inventory_id,
                );

                return (
                    <Card
                        key={element.client_id}
                        className={wizardCardClass}
                        data-test={`budget-element-${index}`}
                    >
                        <CardHeader className={wizardCardHeaderClass}>
                            <CardTitle className={wizardCardTitleClass}>
                                Werbeelement {index + 1}
                                {inventory ? ` · ${inventory.name}` : ''}
                            </CardTitle>
                        </CardHeader>
                        <CardContent
                            className={`${wizardCardContentClass} space-y-4`}
                        >
                            <FormField
                                label="Sender"
                                htmlFor={`budget-element-inventory-${index}`}
                                error={
                                    fieldErrors[
                                        `budget_elements.${index}.inventory_id`
                                    ]?.[0]
                                }
                            >
                                <select
                                    id={`budget-element-inventory-${index}`}
                                    className={formSelectClass}
                                    value={element.inventory_id ?? ''}
                                    disabled={!canEdit}
                                    onChange={(event) =>
                                        updateElement(index, {
                                            inventory_id: Number(
                                                event.target.value,
                                            ),
                                        })
                                    }
                                >
                                    <option value="">Sender auswählen</option>
                                    {activeInventories.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField
                                label="Spotlänge"
                                htmlFor={`budget-element-length-${index}`}
                                error={
                                    fieldErrors[
                                        `budget_elements.${index}.spot_length_seconds`
                                    ]?.[0]
                                }
                            >
                                <Input
                                    id={`budget-element-length-${index}`}
                                    type="number"
                                    min={1}
                                    value={element.spot_length_seconds}
                                    disabled={!canEdit}
                                    onChange={(event) =>
                                        updateElement(index, {
                                            spot_length_seconds: Number(
                                                event.target.value,
                                            ),
                                        })
                                    }
                                />
                            </FormField>

                            <BudgetDistributionRanges
                                ranges={element.distribution_ranges}
                                dayGroups={dayGroups}
                                canEdit={canEdit}
                                fieldErrors={fieldErrors}
                                fieldPrefix={`budget_elements.${index}.distribution_ranges`}
                                onChange={(ranges) =>
                                    updateElement(index, {
                                        distribution_ranges: ranges,
                                    })
                                }
                            />

                            {elements.length > 1 && canEdit ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => removeElement(index)}
                                >
                                    Werbeelement entfernen
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                );
            })}

            {canEdit ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={addElement}
                    data-test="add-budget-element"
                >
                    Weiteres Werbeelement hinzufügen
                </Button>
            ) : null}
        </div>
    );
}
