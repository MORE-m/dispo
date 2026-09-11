import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

function slugFromLabel(label: string): string {
    return label
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

export default function DefinitionCreate({
    defaults,
}: {
    defaults: {
        field_type: string;
        scope: string;
        applies_to: string;
        sort_default: number;
        reportable: boolean;
    };
}) {
    const form = useForm({
        label: '',
        key: '',
        field_type: defaults.field_type || 'short_text',
        scope: defaults.scope || 'header',
        applies_to: defaults.applies_to || 'both',
        help_text: '',
        group_key: '',
        sort_default: defaults.sort_default ?? 100,
        reportable: defaults.reportable ?? false,
        max_length: '' as string | number,
    });
    const [keyTouched, setKeyTouched] = useState(false);

    useEffect(() => {
        if (!keyTouched) {
            form.setData('key', slugFromLabel(form.data.label));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- preview only tracks label
    }, [form.data.label, keyTouched]);

    const isChoice =
        form.data.field_type === 'select' ||
        form.data.field_type === 'multi_select';
    const maxHint =
        form.data.field_type === 'short_text'
            ? 'Standard 255, max. 255'
            : 'Standard 20000, max. 20000 (MEDIUMTEXT)';

    return (
        <>
            <Head title="Eigenes Feld anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Eigenes Feld anlegen"
                    description="Eigenes Feld (Text oder Auswahl) für Header oder Position."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder/definitionen">
                                Zurück zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="custom-field-definition-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => {
                            const choice =
                                data.field_type === 'select' ||
                                data.field_type === 'multi_select';
                            const payload: Record<string, unknown> = {
                                ...data,
                                key:
                                    data.key.trim() === ''
                                        ? null
                                        : data.key.trim(),
                                help_text:
                                    data.help_text.trim() === ''
                                        ? null
                                        : data.help_text,
                                group_key:
                                    data.group_key.trim() === ''
                                        ? null
                                        : data.group_key,
                            };
                            if (choice) {
                                delete payload.max_length;
                            } else {
                                payload.max_length =
                                    data.max_length === '' ||
                                    data.max_length === null
                                        ? null
                                        : Number(data.max_length);
                            }
                            return payload;
                        });
                        form.post(
                            '/administration/dynamische-felder/definitionen',
                        );
                    }}
                >
                    <FormField
                        label="Anzeigename"
                        htmlFor="label"
                        error={form.errors.label}
                    >
                        <Input
                            id="label"
                            value={form.data.label}
                            onChange={(e) =>
                                form.setData('label', e.target.value)
                            }
                            required
                        />
                    </FormField>

                    <FormField
                        label="Feldschlüssel (Vorschau)"
                        htmlFor="key"
                        error={form.errors.key}
                        hint="Vor dem Speichern editierbar. Danach unveränderlich."
                    >
                        <Input
                            id="key"
                            value={form.data.key}
                            onChange={(e) => {
                                setKeyTouched(true);
                                form.setData('key', e.target.value);
                            }}
                            required
                        />
                    </FormField>

                    <FormField
                        label="Feldtyp"
                        htmlFor="field_type"
                        error={form.errors.field_type}
                    >
                        <select
                            id="field_type"
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={form.data.field_type}
                            onChange={(e) =>
                                form.setData('field_type', e.target.value)
                            }
                            data-test="custom-field-type-select"
                        >
                            <option value="short_text">short_text</option>
                            <option value="long_text">long_text</option>
                            <option value="select">select</option>
                            <option value="multi_select">multi_select</option>
                        </select>
                    </FormField>

                    <FormField
                        label="Gilt für"
                        htmlFor="applies_to"
                        error={form.errors.applies_to}
                    >
                        <select
                            id="applies_to"
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={form.data.applies_to}
                            onChange={(e) =>
                                form.setData('applies_to', e.target.value)
                            }
                        >
                            <option value="calculation">Kalkulation</option>
                            <option value="dispo_order">Dispoauftrag</option>
                            <option value="both">Beide</option>
                        </select>
                    </FormField>

                    <FormField
                        label="Scope"
                        htmlFor="scope"
                        error={form.errors.scope}
                    >
                        <select
                            id="scope"
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={form.data.scope}
                            onChange={(e) =>
                                form.setData('scope', e.target.value)
                            }
                            data-test="custom-field-scope-select"
                        >
                            <option value="header">header</option>
                            <option value="position">position</option>
                        </select>
                    </FormField>

                    {!isChoice ? (
                        <FormField
                            label="Maximallänge"
                            htmlFor="max_length"
                            error={form.errors.max_length}
                            hint={maxHint}
                        >
                            <Input
                                id="max_length"
                                type="number"
                                min={1}
                                max={
                                    form.data.field_type === 'short_text'
                                        ? 255
                                        : 20000
                                }
                                value={form.data.max_length}
                                onChange={(e) =>
                                    form.setData(
                                        'max_length',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </FormField>
                    ) : null}

                    <FormField
                        label="Hilfetext"
                        htmlFor="help_text"
                        error={form.errors.help_text}
                    >
                        <textarea
                            id="help_text"
                            rows={3}
                            value={form.data.help_text}
                            onChange={(e) =>
                                form.setData('help_text', e.target.value)
                            }
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                    </FormField>

                    <FormField
                        label="Gruppe"
                        htmlFor="group_key"
                        error={form.errors.group_key}
                    >
                        <Input
                            id="group_key"
                            value={form.data.group_key}
                            onChange={(e) =>
                                form.setData('group_key', e.target.value)
                            }
                        />
                    </FormField>

                    <FormField
                        label="Standardsortierung"
                        htmlFor="sort_default"
                        error={form.errors.sort_default}
                    >
                        <Input
                            id="sort_default"
                            type="number"
                            min={0}
                            value={form.data.sort_default}
                            onChange={(e) =>
                                form.setData(
                                    'sort_default',
                                    Number(e.target.value),
                                )
                            }
                            required
                        />
                    </FormField>

                    <div className="flex items-center gap-2">
                        <input
                            id="reportable"
                            type="checkbox"
                            checked={form.data.reportable}
                            onChange={(e) =>
                                form.setData('reportable', e.target.checked)
                            }
                            className="size-4 rounded border"
                        />
                        <Label htmlFor="reportable">Reportfähig</Label>
                    </div>

                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="custom-field-definition-submit"
                    >
                        Anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
