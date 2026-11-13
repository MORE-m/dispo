import { FormField, money } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import {
    validateFreePriceInput,
    type FreePositionDraftFields,
} from '@/lib/free-position-wizard';

type Props = {
    positionIndex: number;
    position: FreePositionDraftFields;
    /** Read-only Vorschau aus den Server-Totals (kein Client-Rechnen). */
    mediaGross?: string | null;
    fieldErrors: Record<string, string[]>;
    canEdit: boolean;
    onChange: (patch: Partial<FreePositionDraftFields>) => void;
};

/**
 * NATIVE-3: Freie Preisposition – einzige Preiseingabe „Preis“ (Payload: free_price).
 * Brutto, Rabatte, AE und N/N berechnet ausschließlich der Server.
 */
export function FreePositionEditor({
    positionIndex,
    position,
    mediaGross,
    fieldErrors,
    canEdit,
    onChange,
}: Props) {
    const serverError =
        fieldErrors[`positions.${positionIndex}.free_price`]?.[0];
    const touched = position.free_price_input.trim() !== '';
    const validation = validateFreePriceInput(position.free_price_input);
    const clientError =
        touched && !validation.valid ? validation.message : undefined;

    return (
        <div
            className="space-y-3"
            data-test={`free-position-editor-${positionIndex}`}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <FormField
                    label="Preis"
                    htmlFor={`free-price-${positionIndex}`}
                    error={serverError ?? clientError}
                    hint="Ausgangspreis dieser Position in Euro (größer 0, höchstens 2 Nachkommastellen)."
                >
                    <Input
                        id={`free-price-${positionIndex}`}
                        data-test={`position-free-price-${positionIndex}`}
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        placeholder="0,00 €"
                        value={position.free_price_input}
                        disabled={!canEdit}
                        aria-invalid={
                            serverError !== undefined ||
                            clientError !== undefined
                        }
                        onChange={(event) =>
                            onChange({ free_price_input: event.target.value })
                        }
                    />
                </FormField>
            </div>
            {mediaGross ? (
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`free-position-media-gross-${positionIndex}`}
                >
                    Bruttoausgangswert (Vorschau){' '}
                    <span className="text-foreground font-medium">
                        {money(mediaGross)}
                    </span>
                </p>
            ) : null}
        </div>
    );
}
