import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type InventoryOption = {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
};

type CopySource = {
    id: number;
    name: string;
    year: number;
    version: string;
    inventory_id: number;
    inventory_name: string | null;
};

export default function PriceListCreate({
    inventories,
    currentYear,
    copySource,
}: {
    inventories: InventoryOption[];
    currentYear: number;
    copySource: CopySource | null;
}) {
    const form = useForm({
        inventory_id: copySource?.inventory_id
            ? String(copySource.inventory_id)
            : '',
        year: String(copySource?.year ?? currentYear),
        name: copySource ? `${copySource.name} (Kopie)` : '',
        copy_from_id: copySource ? String(copySource.id) : '',
    });

    return (
        <>
            <Head title="Preisliste anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={
                        copySource
                            ? 'Neue Version durch Kopieren'
                            : 'Entwurf anlegen'
                    }
                    description="Inventar und Jahr bleiben nach dem Anlegen unveränderlich. Unvollständige Entwürfe dürfen gespeichert werden."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/preislisten">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                {copySource ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="price-list-copy-source"
                    >
                        Kopie von {copySource.inventory_name} · Jahr{' '}
                        {copySource.year} · Version {copySource.version}
                    </p>
                ) : null}

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="price-list-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/preislisten');
                    }}
                >
                    <FormField
                        label="Inventar"
                        htmlFor="inventory_id"
                        error={form.errors.inventory_id}
                    >
                        <select
                            id="inventory_id"
                            className="w-full rounded-md border px-3 py-2"
                            value={form.data.inventory_id}
                            disabled={copySource !== null}
                            onChange={(event) =>
                                form.setData('inventory_id', event.target.value)
                            }
                            data-test="price-list-inventory-input"
                        >
                            <option value="">Bitte wählen</option>
                            {inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name} ({inventory.type_label})
                                </option>
                            ))}
                        </select>
                    </FormField>
                    {copySource ? (
                        <input
                            type="hidden"
                            name="inventory_id"
                            value={form.data.inventory_id}
                        />
                    ) : null}
                    <FormField
                        label="Kalenderjahr"
                        htmlFor="year"
                        error={form.errors.year}
                    >
                        <Input
                            id="year"
                            type="number"
                            value={form.data.year}
                            onChange={(event) =>
                                form.setData('year', event.target.value)
                            }
                            data-test="price-list-year-input"
                        />
                    </FormField>
                    <FormField
                        label="Name"
                        htmlFor="name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            data-test="price-list-name-input"
                        />
                    </FormField>
                    {copySource ? (
                        <input
                            type="hidden"
                            name="copy_from_id"
                            value={form.data.copy_from_id}
                        />
                    ) : null}
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="price-list-create-submit"
                    >
                        Entwurf anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
