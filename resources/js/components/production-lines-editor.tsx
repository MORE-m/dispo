import { Plus, Trash2 } from 'lucide-react';
import { FormField, money } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    newProductionLine,
    type ProductionLineDraft,
    type ProductionLineResult,
} from '@/lib/production-lines';

type Props = {
    positionIndex: number;
    lines: ProductionLineDraft[];
    /** Server-Preview je Zeile (gleiche Reihenfolge); Preis ist read-only. */
    results?: ProductionLineResult[] | null;
    canEdit: boolean;
    /** false: Medium/Methode/Preisabschluss lässt Produktion nicht zu (Zeilen nur noch entfernbar). */
    supported: boolean;
    previewLoading?: boolean;
    /** Fehler der Position (z. B. fehlender Produktionspreis, nicht unterstützte Methode). */
    error?: string;
    fieldErrors?: Record<string, string[]>;
    onChange: (lines: ProductionLineDraft[]) => void;
};

export function ProductionLinesEditor({
    positionIndex,
    lines,
    results,
    canEdit,
    supported,
    previewLoading = false,
    error,
    fieldErrors = {},
    onChange,
}: Props) {
    if (!supported && lines.length === 0) {
        return null;
    }

    const prefix = `positions.${positionIndex}.production_lines`;

    function update(index: number, patch: Partial<ProductionLineDraft>) {
        onChange(
            lines.map((line, lineIndex) =>
                lineIndex === index ? { ...line, ...patch } : line,
            ),
        );
    }

    return (
        <section
            className="space-y-3 border-t pt-4"
            data-test={`production-lines-${positionIndex}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h4 className="text-sm font-medium">Produktion</h4>
                    <p className="text-muted-foreground text-sm">
                        Spotproduktion je Einheit; der Preis kommt aus der
                        Produktionsliste des Senders und ist hier nicht
                        änderbar.
                    </p>
                </div>
                {supported && canEdit ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test={`production-add-${positionIndex}`}
                        onClick={() =>
                            onChange([...lines, newProductionLine()])
                        }
                    >
                        <Plus className="size-4" />
                        Spotproduktion hinzufügen
                    </Button>
                ) : null}
            </div>

            {!supported ? (
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test={`production-unsupported-${positionIndex}`}
                >
                    Produktion ist nur für Spot Classic mit der Kalkulationsart
                    Durchschnitt (ohne Festpreis) möglich. Entferne die
                    Produktionszeilen oder ändere die Auswahl.
                </p>
            ) : null}

            {error ? (
                <p
                    className="text-destructive text-sm"
                    role="alert"
                    data-test={`production-error-${positionIndex}`}
                >
                    {error}
                </p>
            ) : null}

            {lines.map((line, index) => {
                const result = results?.[index];
                const quantityError =
                    fieldErrors[`${prefix}.${index}.quantity`]?.[0];

                return (
                    <div
                        key={line.client_key}
                        className="grid gap-3 rounded-lg border p-3 sm:grid-cols-12"
                        data-test={`production-line-${positionIndex}-${index}`}
                    >
                        <div className="sm:col-span-4">
                            <FormField
                                label="Bezeichnung"
                                htmlFor={`production-label-${positionIndex}-${index}`}
                                error={
                                    fieldErrors[`${prefix}.${index}.label`]?.[0]
                                }
                            >
                                <Input
                                    id={`production-label-${positionIndex}-${index}`}
                                    value={line.label}
                                    maxLength={120}
                                    disabled={!canEdit}
                                    data-test={`production-label-${positionIndex}-${index}`}
                                    onChange={(event) =>
                                        update(index, {
                                            label: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField
                                label="Menge"
                                htmlFor={`production-quantity-${positionIndex}-${index}`}
                                error={quantityError}
                            >
                                <Input
                                    id={`production-quantity-${positionIndex}-${index}`}
                                    inputMode="decimal"
                                    value={line.quantity}
                                    disabled={!canEdit}
                                    data-test={`production-quantity-${positionIndex}-${index}`}
                                    onChange={(event) =>
                                        update(index, {
                                            quantity: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField
                                label="Einzelpreis"
                                htmlFor={`production-unit-price-${positionIndex}-${index}`}
                            >
                                <Input
                                    id={`production-unit-price-${positionIndex}-${index}`}
                                    readOnly
                                    tabIndex={-1}
                                    value={
                                        result?.unit_price
                                            ? money(result.unit_price)
                                            : previewLoading
                                              ? '…'
                                              : '–'
                                    }
                                    data-test={`production-unit-price-${positionIndex}-${index}`}
                                />
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField
                                label="Summe"
                                htmlFor={`production-total-${positionIndex}-${index}`}
                            >
                                <Input
                                    id={`production-total-${positionIndex}-${index}`}
                                    readOnly
                                    tabIndex={-1}
                                    value={
                                        result?.line_gross
                                            ? money(result.line_gross)
                                            : previewLoading
                                              ? '…'
                                              : '–'
                                    }
                                    data-test={`production-total-${positionIndex}-${index}`}
                                />
                            </FormField>
                        </div>
                        <div className="flex items-end sm:col-span-2">
                            {canEdit ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    data-test={`production-remove-${positionIndex}-${index}`}
                                    onClick={() =>
                                        onChange(
                                            lines.filter(
                                                (_, lineIndex) =>
                                                    lineIndex !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                    Entfernen
                                </Button>
                            ) : null}
                        </div>
                        <div className="sm:col-span-12">
                            <FormField
                                label="Bemerkung"
                                htmlFor={`production-remark-${positionIndex}-${index}`}
                            >
                                <Input
                                    id={`production-remark-${positionIndex}-${index}`}
                                    value={line.remark}
                                    maxLength={1000}
                                    disabled={!canEdit}
                                    data-test={`production-remark-${positionIndex}-${index}`}
                                    onChange={(event) =>
                                        update(index, {
                                            remark: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        </div>
                    </div>
                );
            })}
        </section>
    );
}
