import {
    choiceReadOnlyDisplay,
    type SchemaChoiceField,
} from '@/lib/choice-field-values';

type Props = {
    fields: SchemaChoiceField[];
    values: Record<string, unknown>;
    captured?: Record<string, boolean>;
    idPrefix?: string;
};

/**
 * DF-3-REST-C3: echte read-only Darstellung für Calc-Origin-Choice
 * (kein disabled Select/Multi).
 */
export function SchemaChoiceReadonlyFields({
    fields,
    values,
    captured = {},
    idPrefix = 'schema-choice-ro',
}: Props) {
    const sorted = [...fields].sort(
        (a, b) => (a.sort ?? 0) - (b.sort ?? 0) || a.key.localeCompare(b.key),
    );

    if (sorted.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            {sorted.map((field) => {
                const display = choiceReadOnlyDisplay(
                    field,
                    values[field.key],
                    captured[field.key] === true,
                );
                const testId = `${idPrefix}-${field.key}`;

                return (
                    <div
                        key={field.key}
                        className="space-y-1"
                        data-test={testId}
                    >
                        <p className="text-sm font-medium">{field.label}</p>
                        {field.help_text ? (
                            <p className="text-muted-foreground text-xs">
                                {field.help_text}
                            </p>
                        ) : null}
                        {display.kind === 'integrity' ? (
                            <div
                                className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
                                role="alert"
                                data-test={`${testId}-integrity`}
                            >
                                {display.message}
                            </div>
                        ) : null}
                        {display.kind === 'uncaptured' ||
                        display.kind === 'empty' ? (
                            <p
                                className="text-sm"
                                data-test={`${testId}-value`}
                            >
                                {display.text}
                            </p>
                        ) : null}
                        {display.kind === 'select' ? (
                            <p
                                className="text-sm"
                                data-test={`${testId}-value`}
                            >
                                {display.label}
                                {display.inactive ? (
                                    <span
                                        className="text-muted-foreground ml-2 text-xs"
                                        data-test={`${testId}-inactive`}
                                    >
                                        Nicht mehr auswählbar
                                    </span>
                                ) : null}
                            </p>
                        ) : null}
                        {display.kind === 'multi' ? (
                            <ul
                                className="space-y-1 text-sm"
                                data-test={`${testId}-value`}
                            >
                                {display.items.map((item) => (
                                    <li
                                        key={item.key}
                                        data-test={`${testId}-item-${item.key}`}
                                    >
                                        {item.label}
                                        {item.inactive ? (
                                            <span
                                                className="text-muted-foreground ml-2 text-xs"
                                                data-test={`${testId}-inactive-${item.key}`}
                                            >
                                                Nicht mehr auswählbar
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
