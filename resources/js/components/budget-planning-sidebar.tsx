import { money } from '@/components/form-field';
import {
    formatHour,
    type DayGroupOption,
    type DistributionRangeDraft,
} from '@/lib/pricing-time';

export function BudgetPlanningSidebar({
    targetBudget,
    wishInventoryCount,
    spotLengthSeconds,
    dayGroups,
    distributionRanges,
}: {
    targetBudget: string;
    wishInventoryCount: number;
    spotLengthSeconds: number;
    dayGroups: DayGroupOption[];
    distributionRanges: DistributionRangeDraft[];
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
                    <dt className="text-muted-foreground">Wunschsender</dt>
                    <dd className="font-medium">{wishInventoryCount}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Spotlänge</dt>
                    <dd className="font-medium">{spotLengthSeconds} Sek.</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        Erlaubte Zeiträume
                    </dt>
                    <dd className="space-y-1">
                        {distributionRanges.map((range, index) => (
                            <p key={`range-${index}`}>
                                {dayGroupLabel(range.day_group)} ·{' '}
                                {typeof range.start_hour === 'number'
                                    ? formatHour(range.start_hour)
                                    : '–'}
                                –
                                {typeof range.end_hour_exclusive === 'number'
                                    ? formatHour(range.end_hour_exclusive)
                                    : '–'}
                            </p>
                        ))}
                    </dd>
                </div>
            </dl>
        </aside>
    );
}
