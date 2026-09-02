import { BudgetDistributionRanges } from '@/components/budget-distribution-ranges';
import { BudgetWishSenders } from '@/components/budget-proposal-panel';
import { FormField } from '@/components/form-field';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    wizardCardClass,
    wizardCardContentClass,
    wizardCardHeaderClass,
    wizardCardTitleClass,
} from '@/components/wizard-section';
import type {
    DayGroupOption,
    DistributionRangeDraft,
} from '@/lib/pricing-time';

export function BudgetPlanningFrameStep({
    inventories,
    wishInventoryIds,
    budgetSpotLength,
    budgetDistributionRanges,
    dayGroups,
    canEdit,
    fieldErrors,
    onWishInventoryChange,
    onSpotLengthChange,
    onDistributionRangesChange,
}: {
    inventories: Array<{
        id: number;
        name: string;
        logo_path?: string | null;
        is_active: boolean;
    }>;
    wishInventoryIds: number[];
    budgetSpotLength: number;
    budgetDistributionRanges: DistributionRangeDraft[];
    dayGroups: DayGroupOption[];
    canEdit: boolean;
    fieldErrors: Record<string, string[]>;
    onWishInventoryChange: (ids: number[]) => void;
    onSpotLengthChange: (seconds: number) => void;
    onDistributionRangesChange: (ranges: DistributionRangeDraft[]) => void;
}) {
    return (
        <Card className={wizardCardClass} data-test="budget-planning-frame">
            <CardHeader className={wizardCardHeaderClass}>
                <CardTitle className={wizardCardTitleClass}>
                    Planungsrahmen
                </CardTitle>
            </CardHeader>
            <CardContent className={`${wizardCardContentClass} space-y-6`}>
                <p className="text-muted-foreground text-sm">
                    Wähle die gewünschten Sender, die Spotlänge und die Zeiten,
                    in denen die Spots verteilt werden dürfen.
                </p>
                <BudgetWishSenders
                    inventories={inventories}
                    selectedIds={wishInventoryIds}
                    canEdit={canEdit}
                    onChange={onWishInventoryChange}
                />
                <FormField
                    label="Spotlänge (Sekunden)"
                    htmlFor="budget-spot-length"
                >
                    <Input
                        id="budget-spot-length"
                        type="number"
                        min={1}
                        value={budgetSpotLength}
                        disabled={!canEdit}
                        onChange={(event) =>
                            onSpotLengthChange(Number(event.target.value))
                        }
                    />
                </FormField>
                <BudgetDistributionRanges
                    ranges={budgetDistributionRanges}
                    dayGroups={dayGroups}
                    canEdit={canEdit}
                    fieldErrors={fieldErrors}
                    onChange={onDistributionRangesChange}
                />
            </CardContent>
        </Card>
    );
}
