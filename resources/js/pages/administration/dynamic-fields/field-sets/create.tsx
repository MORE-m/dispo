import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

function slugFromName(name: string): string {
    return name
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

export default function FieldSetCreate({
    appliesToOptions,
}: {
    appliesToOptions: Array<{ value: string; label: string }>;
}) {
    const form = useForm({
        name: '',
        key: '',
        applies_to: 'both',
    });
    const [keyTouched, setKeyTouched] = useState(false);

    useEffect(() => {
        if (!keyTouched) {
            form.setData('key', slugFromName(form.data.name));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- preview only tracks name
    }, [form.data.name, keyTouched]);

    return (
        <>
            <Head title="Feldset anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Freies Feldset anlegen"
                    description="Nach dem Speichern ist der technische Schlüssel unveränderlich. Das Feldset ist erst nach Aktivierung einer Version später assignierbar – und wirkt noch nicht auf Kalkulation oder Dispo."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder/feldsets">
                                Zurück zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="fieldset-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            key:
                                data.key.trim() === '' ? null : data.key.trim(),
                        }));
                        form.post('/administration/dynamische-felder/feldsets');
                    }}
                >
                    <FormField
                        label="Name"
                        error={form.errors.name}
                        htmlFor="fieldset-name"
                    >
                        <Input
                            id="fieldset-name"
                            data-test="fieldset-name-input"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                        />
                    </FormField>

                    <FormField
                        label="Technischer Schlüssel"
                        error={form.errors.key}
                        htmlFor="fieldset-key"
                    >
                        <Input
                            id="fieldset-key"
                            data-test="fieldset-key-input"
                            value={form.data.key}
                            onChange={(event) => {
                                setKeyTouched(true);
                                form.setData('key', event.target.value);
                            }}
                            className="font-mono text-sm"
                        />
                        <p className="text-muted-foreground mt-1 text-xs">
                            Vorschlag aus dem Namen. Nach dem Speichern nicht
                            mehr änderbar. Präfix „system_“ ist reserviert.
                        </p>
                    </FormField>

                    <div className="space-y-2">
                        <Label htmlFor="fieldset-applies-to">Gültigkeit</Label>
                        <select
                            id="fieldset-applies-to"
                            data-test="fieldset-applies-to-select"
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={form.data.applies_to}
                            onChange={(event) =>
                                form.setData('applies_to', event.target.value)
                            }
                        >
                            {appliesToOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.applies_to ? (
                            <p className="text-destructive text-sm">
                                {form.errors.applies_to}
                            </p>
                        ) : null}
                    </div>

                    <p className="text-muted-foreground text-sm">
                        Es wird atomar Version 1 als leerer Entwurf angelegt.
                        Erst nach Prüfung und Aktivierung wird das Feldset für
                        spätere Assignments vorbereitet.
                    </p>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="fieldset-create-submit"
                        >
                            Anlegen
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder/feldsets">
                                Abbrechen
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
