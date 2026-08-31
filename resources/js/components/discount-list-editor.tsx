import {
    FormField,
    formatPercent,
    formSelectClass,
    money,
    moneyDeduction,
} from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type DiscountTypeOption = {
    value: string;
    label: string;
    requires_custom_label: boolean;
};

export type DiscountDraft = {
    type: string;
    custom_label: string;
    percent: string;
};

export function emptyDiscount(): DiscountDraft {
    return {
        type: 'quantity',
        custom_label: '',
        percent: '',
    };
}

export function payloadDiscounts(discounts: DiscountDraft[]): Array<{
    type: string;
    custom_label: string | null;
    percent: string;
}> {
    return discounts
        .filter(
            (discount) =>
                discount.type !== '' &&
                discount.percent !== '' &&
                Number(discount.percent) > 0,
        )
        .map((discount) => ({
            type: discount.type,
            custom_label:
                discount.type === 'other' ? discount.custom_label : null,
            percent: discount.percent,
        }));
}

export function DiscountListEditor({
    title,
    description,
    discounts,
    types,
    canEdit,
    disabled,
    fieldPrefix,
    fieldErrors,
    breakdown,
    onChange,
}: {
    title: string;
    description?: string;
    discounts: DiscountDraft[];
    types: DiscountTypeOption[];
    canEdit: boolean;
    disabled?: boolean;
    fieldPrefix: string;
    fieldErrors: Record<string, string[]>;
    breakdown?: Array<{
        label?: string;
        percent?: string;
        amount?: string;
        remaining?: string;
    }>;
    onChange: (discounts: DiscountDraft[]) => void;
}) {
    function update(index: number, patch: Partial<DiscountDraft>) {
        onChange(
            discounts.map((discount, current) =>
                current === index ? { ...discount, ...patch } : discount,
            ),
        );
    }

    return (
        <div className="space-y-3">
            <div>
                <p className="text-sm font-medium">{title}</p>
                {description ? (
                    <p className="text-muted-foreground mt-1 text-xs">
                        {description}
                    </p>
                ) : null}
            </div>

            <div className="space-y-3">
                {discounts.map((discount, index) => {
                    const type = types.find(
                        (item) => item.value === discount.type,
                    );
                    const row = breakdown?.[index];

                    return (
                        <div
                            key={`${discount.type}-${index}`}
                            className="border-border/60 bg-muted/15 space-y-3 rounded-lg border p-3"
                            data-test={`${fieldPrefix}-${index}`}
                        >
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,8rem)_auto]">
                                <FormField
                                    label="Rabattart"
                                    htmlFor={`${fieldPrefix}-type-${index}`}
                                    error={
                                        fieldErrors[
                                            `${fieldPrefix}.${index}.type`
                                        ]?.[0]
                                    }
                                >
                                    <select
                                        id={`${fieldPrefix}-type-${index}`}
                                        data-test={`${fieldPrefix}-type-${index}`}
                                        className={formSelectClass}
                                        value={discount.type}
                                        disabled={!canEdit || disabled}
                                        onChange={(event) =>
                                            update(index, {
                                                type: event.target.value,
                                                custom_label:
                                                    event.target.value ===
                                                    'other'
                                                        ? discount.custom_label
                                                        : '',
                                            })
                                        }
                                    >
                                        {types.map((item) => (
                                            <option
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                <FormField
                                    label="Prozent"
                                    htmlFor={`${fieldPrefix}-percent-${index}`}
                                    error={
                                        fieldErrors[
                                            `${fieldPrefix}.${index}.percent`
                                        ]?.[0]
                                    }
                                >
                                    <Input
                                        id={`${fieldPrefix}-percent-${index}`}
                                        data-test={`${fieldPrefix}-percent-${index}`}
                                        type="number"
                                        min={0.0001}
                                        max={100}
                                        step="0.01"
                                        value={discount.percent}
                                        disabled={!canEdit || disabled}
                                        onChange={(event) =>
                                            update(index, {
                                                percent: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                {canEdit && !disabled ? (
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                onChange(
                                                    discounts.filter(
                                                        (_, current) =>
                                                            current !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Entfernen
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                            {type?.requires_custom_label ? (
                                <FormField
                                    label="Eigene Bezeichnung"
                                    htmlFor={`${fieldPrefix}-label-${index}`}
                                    error={
                                        fieldErrors[
                                            `${fieldPrefix}.${index}.custom_label`
                                        ]?.[0]
                                    }
                                >
                                    <Input
                                        id={`${fieldPrefix}-label-${index}`}
                                        data-test={`${fieldPrefix}-label-${index}`}
                                        value={discount.custom_label}
                                        disabled={!canEdit || disabled}
                                        onChange={(event) =>
                                            update(index, {
                                                custom_label:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            ) : null}
                            {row?.amount ? (
                                <p className="text-muted-foreground text-xs">
                                    Abzug {moneyDeduction(row.amount)}
                                    {row.percent
                                        ? ` (${formatPercent(row.percent)})`
                                        : ''}
                                    {row.remaining
                                        ? ` · verbleibend ${money(row.remaining)}`
                                        : ''}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            {canEdit && !disabled ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test={`${fieldPrefix}-add`}
                    onClick={() => onChange([...discounts, emptyDiscount()])}
                >
                    Rabatt hinzufügen
                </Button>
            ) : null}
        </div>
    );
}
