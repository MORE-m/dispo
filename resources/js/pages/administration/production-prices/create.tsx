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

type ProductionTypeOption = {
    value: string;
    label: string;
};

export default function ProductionPriceListCreate({
    inventories,
    currentYear,
    productionTypes,
}: {
    inventories: InventoryOption[];
    currentYear: number;
    productionTypes: ProductionTypeOption[];
}) {
    const form = useForm({
        inventory_id: '',
        production_type: productionTypes[0]?.value ?? 'spot_production',
        year: String(currentYear),
        name: '',
        unit_price: '',
        is_discountable: false,
        is_ae_eligible: false,
    });

    return (
        <>
            <Head title="Produktionspreis anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Produktionspreis-Entwurf anlegen"
                    description="Inventar, Produktionsart und Jahr bleiben nach dem Anlegen unveränderlich."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/produktionspreise">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="production-price-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/produktionspreise');
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
                            onChange={(event) =>
                                form.setData('inventory_id', event.target.value)
                            }
                            data-test="production-price-inventory-input"
                        >
                            <option value="">Bitte wählen</option>
                            {inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name} ({inventory.type_label})
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Produktionsart"
                        htmlFor="production_type"
                        error={form.errors.production_type}
                    >
                        <select
                            id="production_type"
                            className="w-full rounded-md border px-3 py-2"
                            value={form.data.production_type}
                            onChange={(event) =>
                                form.setData(
                                    'production_type',
                                    event.target.value,
                                )
                            }
                            data-test="production-price-type-input"
                        >
                            {productionTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
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
                            data-test="production-price-year-input"
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
                            data-test="production-price-name-input"
                        />
                    </FormField>
                    <FormField
                        label="Produktionspreis (€, netto je Einheit)"
                        htmlFor="unit_price"
                        error={form.errors.unit_price}
                        hint="Betrag mit höchstens zwei Nachkommastellen, z. B. 1250,00."
                    >
                        <Input
                            id="unit_price"
                            inputMode="decimal"
                            value={form.data.unit_price}
                            onChange={(event) =>
                                form.setData('unit_price', event.target.value)
                            }
                            data-test="production-price-unit-price-input"
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_discountable}
                            onChange={(event) =>
                                form.setData(
                                    'is_discountable',
                                    event.target.checked,
                                )
                            }
                            data-test="production-price-discountable-input"
                        />
                        Rabattfähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_ae_eligible}
                            onChange={(event) =>
                                form.setData(
                                    'is_ae_eligible',
                                    event.target.checked,
                                )
                            }
                            data-test="production-price-ae-input"
                        />
                        AE-fähig
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="production-price-create-submit"
                    >
                        Entwurf anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
