import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type TypeOption = {
    value: string;
    label: string;
};

export default function InventoryCreate({
    typeOptions,
}: {
    typeOptions: TypeOption[];
}) {
    const form = useForm({
        name: '',
        code: '',
        type: 'sender',
        sort: 0,
        logo_path: '',
        is_active: true,
    });

    return (
        <>
            <Head title="Inventar anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Inventar anlegen"
                    description="Kurzcode und Typ bleiben nach dem Speichern unveränderlich."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/inventare">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="inventory-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/inventare');
                    }}
                >
                    <FormField
                        label="Name"
                        htmlFor="name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            data-test="inventory-name-input"
                        />
                    </FormField>
                    <FormField
                        label="Kurzcode"
                        htmlFor="code"
                        error={form.errors.code}
                    >
                        <Input
                            id="code"
                            value={form.data.code}
                            onChange={(e) =>
                                form.setData('code', e.target.value)
                            }
                            className="font-mono"
                            data-test="inventory-code-input"
                        />
                    </FormField>
                    <FormField
                        label="Typ"
                        htmlFor="type"
                        error={form.errors.type}
                    >
                        <select
                            id="type"
                            className="w-full rounded-md border px-3 py-2"
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                            data-test="inventory-type-input"
                        >
                            {typeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Sortierung"
                        htmlFor="sort"
                        error={form.errors.sort}
                    >
                        <Input
                            id="sort"
                            type="number"
                            min={0}
                            value={form.data.sort}
                            onChange={(e) =>
                                form.setData(
                                    'sort',
                                    Number(e.target.value || 0),
                                )
                            }
                            data-test="inventory-sort-input"
                        />
                    </FormField>
                    <FormField
                        label="Logo-Pfad"
                        htmlFor="logo_path"
                        error={form.errors.logo_path}
                    >
                        <Input
                            id="logo_path"
                            value={form.data.logo_path}
                            onChange={(e) =>
                                form.setData('logo_path', e.target.value)
                            }
                            className="font-mono"
                            data-test="inventory-logo-input"
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) =>
                                form.setData('is_active', e.target.checked)
                            }
                            data-test="inventory-active-input"
                        />
                        Aktiv anlegen
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="inventory-create-submit"
                    >
                        Speichern
                    </Button>
                </form>
            </div>
        </>
    );
}
