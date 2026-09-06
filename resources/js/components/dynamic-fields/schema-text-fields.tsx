import { FormField, formTextareaClass } from '@/components/form-field';
import { Input } from '@/components/ui/input';

export type SchemaTextField = {
    key: string;
    label: string;
    help_text?: string | null;
    field_type: string;
    max_length?: number | null;
    required?: boolean;
    sort?: number;
};

type Props = {
    fields: SchemaTextField[];
    values: Record<string, string>;
    onChange: (key: string, value: string) => void;
    errors?: Record<string, string | string[]>;
    disabled?: boolean;
    idPrefix?: string;
    readOnly?: boolean;
};

function firstError(
    errors: Record<string, string | string[]>,
    ...keys: string[]
): string | undefined {
    for (const key of keys) {
        const value = errors[key];
        if (typeof value === 'string' && value !== '') {
            return value;
        }
        if (Array.isArray(value) && value[0]) {
            return value[0];
        }
    }

    return undefined;
}

export function SchemaTextFields({
    fields,
    values,
    onChange,
    errors = {},
    disabled = false,
    idPrefix = 'schema-text',
    readOnly = false,
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
                const id = `${idPrefix}-${field.key}`;
                const error = firstError(
                    errors,
                    `dynamic_field_values.${field.key}`,
                    field.key,
                );
                const value = values[field.key] ?? '';
                const label = field.required ? `${field.label} *` : field.label;

                if (readOnly) {
                    return (
                        <div key={field.key} className="space-y-1">
                            <p className="text-sm font-medium">{field.label}</p>
                            {field.help_text ? (
                                <p className="text-muted-foreground text-xs">
                                    {field.help_text}
                                </p>
                            ) : null}
                            <p className="text-sm whitespace-pre-wrap">
                                {value.trim() === '' ? '–' : value}
                            </p>
                        </div>
                    );
                }

                return (
                    <FormField
                        key={field.key}
                        label={label}
                        htmlFor={id}
                        error={error}
                        hint={field.help_text ?? undefined}
                    >
                        {field.field_type === 'long_text' ? (
                            <textarea
                                id={id}
                                className={formTextareaClass}
                                value={value}
                                disabled={disabled}
                                maxLength={field.max_length ?? undefined}
                                data-test={`${idPrefix}-${field.key}`}
                                onChange={(event) =>
                                    onChange(field.key, event.target.value)
                                }
                            />
                        ) : (
                            <Input
                                id={id}
                                value={value}
                                disabled={disabled}
                                maxLength={field.max_length ?? undefined}
                                data-test={`${idPrefix}-${field.key}`}
                                onChange={(event) =>
                                    onChange(field.key, event.target.value)
                                }
                            />
                        )}
                    </FormField>
                );
            })}
        </div>
    );
}

export function customHeaderTextFieldsFromSchema(
    fields: Array<{
        key: string;
        label: string;
        help_text?: string | null;
        field_type: string;
        scope?: string;
        sort?: number;
        is_system?: boolean;
        max_length?: number | null;
        required?: boolean;
        visible?: boolean;
        validation_json?: { max_length?: number } | null;
    }>,
): SchemaTextField[] {
    return fields
        .filter(
            (field) =>
                field.is_system !== true &&
                field.visible !== false &&
                (field.scope === undefined || field.scope === 'header') &&
                (field.field_type === 'short_text' ||
                    field.field_type === 'long_text'),
        )
        .map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            required: field.required === true,
            max_length:
                field.max_length ??
                field.validation_json?.max_length ??
                (field.field_type === 'short_text' ? 255 : 20000),
        }));
}
