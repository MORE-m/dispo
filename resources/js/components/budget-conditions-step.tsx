import {
    DiscountListEditor,
    type DiscountDraft,
    type DiscountTypeOption,
} from '@/components/discount-list-editor';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';
import type {
    BudgetElementDraft,
    BudgetPositionDiscountsByClientId,
} from '@/lib/budget-planning';

export function BudgetConditionsStep({
    budgetElements,
    inventories,
    catalogRules,
    budgetPositionDiscounts,
    orderDiscounts,
    aeEnabled,
    discountTypes,
    canEdit,
    fieldErrors,
    onBudgetPositionDiscountsChange,
    onOrderDiscountsChange,
    onAeEnabledChange,
}: {
    budgetElements: BudgetElementDraft[];
    inventories: Array<{
        id: number;
        name: string;
        is_active: boolean;
    }>;
    catalogRules: Array<{
        inventory_id: number;
        is_discountable: boolean;
    }>;
    budgetPositionDiscounts: BudgetPositionDiscountsByClientId;
    orderDiscounts: DiscountDraft[];
    aeEnabled: boolean;
    discountTypes: DiscountTypeOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    onBudgetPositionDiscountsChange: (
        discounts: BudgetPositionDiscountsByClientId,
    ) => void;
    onOrderDiscountsChange: (discounts: DiscountDraft[]) => void;
    onAeEnabledChange: (enabled: boolean) => void;
}) {
    return (
        <div className="space-y-6" data-test="budget-conditions-step">
            {budgetElements.map((element, index) => {
                const inventory = inventories.find(
                    (item) => item.id === element.inventory_id,
                );
                const rule = catalogRules.find(
                    (item) => item.inventory_id === element.inventory_id,
                );

                return (
                    <Card key={element.client_id} className={wizardCardClass}>
                        <CardHeader className={wizardCardHeaderClass}>
                            <CardTitle className={wizardCardTitleClass}>
                                Rabatte für {inventory?.name ?? 'Werbeelement'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className={wizardCardContentClass}>
                            <DiscountListEditor
                                title={`Rabatte für ${inventory?.name ?? 'Werbeelement'}`}
                                description="Optional. Diese Rabatte werden bei der Budgetberechnung berücksichtigt."
                                discounts={
                                    budgetPositionDiscounts[
                                        element.client_id
                                    ] ?? []
                                }
                                types={discountTypes}
                                canEdit={canEdit}
                                disabled={rule?.is_discountable === false}
                                fieldPrefix={`budget_elements.${index}.position_discounts`}
                                fieldErrors={fieldErrors}
                                onChange={(discounts) =>
                                    onBudgetPositionDiscountsChange({
                                        ...budgetPositionDiscounts,
                                        [element.client_id]: discounts,
                                    })
                                }
                            />
                        </CardContent>
                    </Card>
                );
            })}
            <Card className={wizardCardClass}>
                <CardHeader className={wizardCardHeaderClass}>
                    <CardTitle className={wizardCardTitleClass}>
                        Auftragskonditionen
                    </CardTitle>
                </CardHeader>
                <CardContent className={wizardCardContentClass}>
                    <div className="space-y-4">
                        <DiscountListEditor
                            title="Rabatte auf den Gesamtauftrag"
                            description="Optional. Diese Rabatte werden nach den Positionsrabatten angewendet."
                            discounts={orderDiscounts}
                            types={discountTypes}
                            canEdit={canEdit}
                            fieldPrefix="order_discounts"
                            fieldErrors={fieldErrors}
                            onChange={onOrderDiscountsChange}
                        />
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                id="budget-ae-enabled"
                                data-test="ae-enabled"
                                className="mt-0.5"
                                checked={aeEnabled}
                                disabled={!canEdit}
                                onCheckedChange={(checked) =>
                                    onAeEnabledChange(checked === true)
                                }
                            />
                            <span>
                                <span className="font-medium">
                                    15 % AE berücksichtigen
                                </span>
                                <span className="text-muted-foreground mt-1 block text-xs">
                                    AE wird nach allen Positions- und
                                    Auftragsrabatten nur auf den AE-fähigen
                                    Anteil angewendet.
                                </span>
                            </span>
                        </label>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
