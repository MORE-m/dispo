import {
    DiscountListEditor,
    type DiscountDraft,
    type DiscountTypeOption,
} from '@/components/discount-list-editor';
import { LogoSlot } from '@/components/logo-slot';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';
import type { BudgetPositionDiscountsByInventory } from '@/lib/budget-planning';

export function BudgetConditionsStep({
    wishInventoryIds,
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
    wishInventoryIds: number[];
    inventories: Array<{
        id: number;
        name: string;
        logo_path?: string | null;
        is_active: boolean;
    }>;
    catalogRules: Array<{
        inventory_id: number;
        is_discountable: boolean;
    }>;
    budgetPositionDiscounts: BudgetPositionDiscountsByInventory;
    orderDiscounts: DiscountDraft[];
    aeEnabled: boolean;
    discountTypes: DiscountTypeOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    onBudgetPositionDiscountsChange: (
        discounts: BudgetPositionDiscountsByInventory,
    ) => void;
    onOrderDiscountsChange: (discounts: DiscountDraft[]) => void;
    onAeEnabledChange: (enabled: boolean) => void;
}) {
    return (
        <div className="space-y-6" data-test="budget-conditions-step">
            {wishInventoryIds.map((inventoryId, index) => {
                const inventory = inventories.find(
                    (item) => item.id === inventoryId,
                );
                const rule = catalogRules.find(
                    (item) => item.inventory_id === inventoryId,
                );

                return (
                    <Card key={inventoryId} className={wizardCardClass}>
                        <CardHeader className={wizardCardHeaderClass}>
                            <CardTitle
                                className={`${wizardCardTitleClass} flex items-center gap-2`}
                            >
                                <LogoSlot
                                    name={inventory?.name ?? 'Sender'}
                                    logoPath={inventory?.logo_path}
                                />
                                Rabatte für {inventory?.name ?? 'Sender'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className={wizardCardContentClass}>
                            <DiscountListEditor
                                title={`Rabatte für ${inventory?.name ?? 'Sender'}`}
                                description="Optional. Diese Rabatte werden bei der Budgetberechnung berücksichtigt."
                                discounts={
                                    budgetPositionDiscounts[inventoryId] ?? []
                                }
                                types={discountTypes}
                                canEdit={canEdit}
                                disabled={rule?.is_discountable === false}
                                fieldPrefix={`budget_position_discounts_by_inventory.${index}.discounts`}
                                fieldErrors={fieldErrors}
                                onChange={(discounts) =>
                                    onBudgetPositionDiscountsChange({
                                        ...budgetPositionDiscounts,
                                        [inventoryId]: discounts,
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
