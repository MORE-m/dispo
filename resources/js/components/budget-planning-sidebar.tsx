import { money } from '@/components/form-field';
import {
    formatBudgetElementSummary,
    type BudgetElementDraft,
} from '@/lib/budget-planning';
import type { DayGroupOption } from '@/lib/pricing-time';

export function BudgetPlanningSidebar({
    targetBudget,
    budgetElements,
    inventories,
    dayGroups,
}: {
    targetBudget: string;
    budgetElements: BudgetElementDraft[];
    inventories: Array<{ id: number; name: string }>;
    dayGroups: DayGroupOption[];
}) {
    const dayGroupLabel = (value: string) =>
        dayGroups.find((group) => group.value === value)?.label ?? value;

    return (
        <aside
            className="border-border/60 bg-muted/15 space-y-4 rounded-xl border p-4 text-sm"
            data-test="budget-planning-sidebar"
        >
            <p className="font-medium">Planungsrahmen</p>
            <dl className="space-y-2">
                <div>
                    <dt className="text-muted-foreground">Zielbudget N/N</dt>
                    <dd className="font-medium tabular-nums">
                        {targetBudget ? money(targetBudget) : '–'}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        Budget-Werbeelemente
                    </dt>
                    <dd className="space-y-1">
                        {budgetElements.map((element) => {
                            const inventory = inventories.find(
                                (item) => item.id === element.inventory_id,
                            );

                            return (
                                <p key={element.client_id}>
                                    {formatBudgetElementSummary(
                                        element,
                                        inventory?.name,
                                        dayGroupLabel,
                                    )}
                                </p>
                            );
                        })}
                    </dd>
                </div>
            </dl>
        </aside>
    );
}
