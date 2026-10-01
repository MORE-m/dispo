import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { LogoSlot } from '@/components/logo-slot';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type WizardInventoryOption = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    logo_path?: string | null;
};

type WizardInventorySelectionProps = {
    positionIndex: number;
    inventories: WizardInventoryOption[];
    selectedInventoryId: number;
    canEdit: boolean;
    /** Inventare, die für neue Positionen kein wählbares Medium haben. */
    unplannableInventoryIds: ReadonlySet<number>;
    catalogLabel: (name: string, isActive: boolean) => string;
    onSelectInventory: (inventoryId: number) => boolean;
};

/**
 * Sender-/Kombi-Auswahl im Kalkulationswizard.
 * Nach erfolgreicher Auswahl werden die übrigen Kacheln eingeklappt.
 */
export function WizardInventorySelection({
    positionIndex,
    inventories,
    selectedInventoryId,
    canEdit,
    unplannableInventoryIds,
    catalogLabel,
    onSelectInventory,
}: WizardInventorySelectionProps) {
    const listId = useId();
    const selected = inventories.find(
        (item) => item.id === selectedInventoryId,
    );
    const [expanded, setExpanded] = useState(() => selected === undefined);

    useEffect(() => {
        if (selected === undefined) {
            setExpanded(true);
        }
    }, [selected]);

    const selectedLabel = selected
        ? catalogLabel(selected.name, selected.is_active)
        : 'Kein Inventar gewählt';

    function selectInventory(inventoryId: number) {
        if (!canEdit) {
            return;
        }

        if (inventoryId === selectedInventoryId) {
            setExpanded(false);
            return;
        }

        if (unplannableInventoryIds.has(inventoryId)) {
            return;
        }

        const applied = onSelectInventory(inventoryId);
        if (applied) {
            setExpanded(false);
        }
    }

    return (
        <div
            className="space-y-3"
            data-test={`position-inventory-picker-${positionIndex}`}
            data-expanded={expanded ? 'true' : 'false'}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium" id={listId}>
                    Sender / Kombi
                </p>
                {selected && !expanded ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="gap-1.5"
                        disabled={!canEdit}
                        aria-expanded={false}
                        aria-controls={`inventory-options-${positionIndex}`}
                        data-test={`position-inventory-expand-${positionIndex}`}
                        onClick={() => setExpanded(true)}
                    >
                        <ChevronsUpDown className="size-4" aria-hidden="true" />
                        Anderes Inventar wählen
                    </Button>
                ) : null}
            </div>

            {!expanded && selected ? (
                <div
                    className="border-primary bg-accent/70 ring-primary/15 relative flex w-full max-w-md flex-col gap-3 rounded-xl border-2 p-4 text-left shadow-xs ring-1"
                    data-test={`position-inventory-collapsed-${positionIndex}`}
                    aria-live="polite"
                >
                    <span className="bg-primary text-primary-foreground pointer-events-none absolute top-3 right-3 flex size-5 items-center justify-center rounded-full">
                        <Check className="size-3" aria-hidden="true" />
                    </span>
                    <LogoSlot
                        name={selectedLabel}
                        logoPath={selected.logo_path}
                        variant="card"
                    />
                    <span className="pr-6 text-sm leading-snug font-semibold">
                        {selectedLabel}
                    </span>
                    <span className="sr-only">
                        Ausgewähltes Inventar: {selectedLabel}. Auswahl
                        eingeklappt.
                    </span>
                </div>
            ) : null}

            <div
                id={`inventory-options-${positionIndex}`}
                role="group"
                aria-labelledby={listId}
                aria-hidden={!expanded}
                className={cn(
                    'grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none',
                    expanded
                        ? 'grid-rows-[1fr] opacity-100'
                        : 'pointer-events-none grid-rows-[0fr] opacity-0',
                )}
            >
                <div className="overflow-hidden">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {inventories.map((item) => {
                            const isSelected = item.id === selectedInventoryId;
                            const unplannable = unplannableInventoryIds.has(
                                item.id,
                            );
                            const disabled =
                                !canEdit ||
                                (!item.is_active && !isSelected) ||
                                (unplannable && !isSelected);
                            const label = catalogLabel(
                                item.name,
                                item.is_active,
                            );

                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    disabled={disabled}
                                    aria-pressed={isSelected}
                                    aria-disabled={disabled || undefined}
                                    title={
                                        unplannable && !isSelected
                                            ? 'Für dieses Inventar ist derzeit kein kalkulierbares Werbemittel verfügbar.'
                                            : undefined
                                    }
                                    data-test={`position-inventory-tile-${positionIndex}-${item.code}`}
                                    onClick={() => selectInventory(item.id)}
                                    className={cn(
                                        'focus-visible:ring-primary/25 relative flex w-full flex-col gap-3 rounded-xl border-2 p-4 text-left transition-all outline-none focus-visible:ring-[3px] motion-reduce:transition-none',
                                        isSelected
                                            ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                                            : 'border-border/80 bg-card hover:border-primary/45 hover:bg-muted/20',
                                        disabled &&
                                            'hover:border-border/80 hover:bg-card cursor-not-allowed opacity-50',
                                    )}
                                >
                                    {isSelected ? (
                                        <span className="bg-primary text-primary-foreground pointer-events-none absolute top-3 right-3 flex size-5 items-center justify-center rounded-full">
                                            <Check
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    ) : null}
                                    <LogoSlot
                                        name={label}
                                        logoPath={item.logo_path}
                                        variant="card"
                                    />
                                    <span className="pr-6 text-sm leading-snug font-semibold">
                                        {label}
                                    </span>
                                    {unplannable && !isSelected ? (
                                        <span className="text-muted-foreground text-xs">
                                            Derzeit nicht kalkulierbar
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            <select
                className="sr-only"
                data-test={`position-inventory-${positionIndex}`}
                value={selectedInventoryId}
                disabled={!canEdit}
                tabIndex={-1}
                aria-hidden="true"
                onChange={(event) => {
                    selectInventory(Number(event.target.value));
                }}
            >
                {inventories.map((item) => (
                    <option
                        key={item.id}
                        value={item.id}
                        disabled={
                            (!item.is_active &&
                                item.id !== selectedInventoryId) ||
                            (unplannableInventoryIds.has(item.id) &&
                                item.id !== selectedInventoryId)
                        }
                    >
                        {catalogLabel(item.name, item.is_active)}
                    </option>
                ))}
            </select>
        </div>
    );
}
