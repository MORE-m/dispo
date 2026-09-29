import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type FilterOptions = {
    inventories: Array<{ id: number; name: string; code: string }>;
    media: Array<{
        id: number;
        name: string;
        code: string;
        category_id: number | null;
    }>;
    categories: Array<{ id: number; name: string; key: string }>;
};

type PlanningOption = { key: string; label: string };
type StrategyOption = { value: string; label: string };

export default function CombinationCreate({
    filterOptions,
    planningOptions,
    strategyOptions,
}: {
    filterOptions: FilterOptions;
    planningOptions: PlanningOption[];
    strategyOptions: StrategyOption[];
}) {
    const form = useForm({
        inventory_id: filterOptions.inventories[0]?.id ?? '',
        advertising_medium_id: filterOptions.media[0]?.id ?? '',
        is_active: true,
        booking_code: '',
        planning_responsibility_key: 'disposition',
        hint_text: '',
        sort: 0,
        default_length_seconds: 30 as number | '',
        surcharge_percent: '0',
        is_discountable: true,
        is_ae_eligible: true,
        component_calculation_strategy:
            strategyOptions[0]?.value ?? 'shared_total_length',
    });

    return (
        <>
            <Head title="Kombination anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Kombination anlegen"
                    description="Inventar und Werbemittel sind nach dem Speichern unveränderlich. Aktive Zeilen brauchen Kennzeichen und Einplanung."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/kombinationen">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="combination-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/kombinationen');
                    }}
                >
                    <FormField
                        label="Inventar"
                        htmlFor="inventory_id"
                        error={form.errors.inventory_id}
                    >
                        <select
                            id="inventory_id"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.inventory_id}
                            onChange={(e) =>
                                form.setData(
                                    'inventory_id',
                                    e.target.value
                                        ? Number(e.target.value)
                                        : '',
                                )
                            }
                            data-test="combination-inventory-input"
                        >
                            {filterOptions.inventories.map((inventory) => (
                                <option key={inventory.id} value={inventory.id}>
                                    {inventory.name} ({inventory.code})
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Werbemittel"
                        htmlFor="advertising_medium_id"
                        error={form.errors.advertising_medium_id}
                    >
                        <select
                            id="advertising_medium_id"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.advertising_medium_id}
                            onChange={(e) =>
                                form.setData(
                                    'advertising_medium_id',
                                    e.target.value
                                        ? Number(e.target.value)
                                        : '',
                                )
                            }
                            data-test="combination-medium-input"
                        >
                            {filterOptions.media.map((medium) => (
                                <option key={medium.id} value={medium.id}>
                                    {medium.name}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Buchungskennzeichen"
                        htmlFor="booking_code"
                        error={form.errors.booking_code}
                    >
                        <Input
                            id="booking_code"
                            value={form.data.booking_code}
                            onChange={(e) =>
                                form.setData('booking_code', e.target.value)
                            }
                            className="font-mono"
                            data-test="combination-booking-input"
                        />
                    </FormField>
                    <FormField
                        label="Einplanung durch"
                        htmlFor="planning_responsibility_key"
                        error={form.errors.planning_responsibility_key}
                    >
                        <select
                            id="planning_responsibility_key"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.planning_responsibility_key}
                            onChange={(e) =>
                                form.setData(
                                    'planning_responsibility_key',
                                    e.target.value,
                                )
                            }
                            data-test="combination-planning-input"
                        >
                            {planningOptions.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Hinweis"
                        htmlFor="hint_text"
                        error={form.errors.hint_text}
                    >
                        <textarea
                            id="hint_text"
                            className="min-h-20 w-full rounded-md border px-2 py-2"
                            value={form.data.hint_text}
                            onChange={(e) =>
                                form.setData('hint_text', e.target.value)
                            }
                            data-test="combination-hint-input"
                        />
                    </FormField>
                    <FormField
                        label="Sortierung"
                        htmlFor="sort"
                        error={form.errors.sort}
                    >
                        <Input
                            id="sort"
                            type="number"
                            value={form.data.sort}
                            onChange={(e) =>
                                form.setData('sort', Number(e.target.value))
                            }
                        />
                    </FormField>
                    <FormField
                        label="Standardlänge (Sekunden)"
                        htmlFor="default_length_seconds"
                        error={form.errors.default_length_seconds}
                    >
                        <Input
                            id="default_length_seconds"
                            type="number"
                            value={form.data.default_length_seconds}
                            onChange={(e) =>
                                form.setData(
                                    'default_length_seconds',
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        label="Aufschlag %"
                        htmlFor="surcharge_percent"
                        error={form.errors.surcharge_percent}
                    >
                        <Input
                            id="surcharge_percent"
                            value={form.data.surcharge_percent}
                            onChange={(e) =>
                                form.setData(
                                    'surcharge_percent',
                                    e.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        label="Komponentenstrategie"
                        htmlFor="component_calculation_strategy"
                        error={form.errors.component_calculation_strategy}
                    >
                        <select
                            id="component_calculation_strategy"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.component_calculation_strategy}
                            onChange={(e) =>
                                form.setData(
                                    'component_calculation_strategy',
                                    e.target.value,
                                )
                            }
                        >
                            {strategyOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_discountable}
                            onChange={(e) =>
                                form.setData(
                                    'is_discountable',
                                    e.target.checked,
                                )
                            }
                        />
                        Rabattfähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_ae_eligible}
                            onChange={(e) =>
                                form.setData('is_ae_eligible', e.target.checked)
                            }
                        />
                        AE-fähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) =>
                                form.setData('is_active', e.target.checked)
                            }
                            data-test="combination-active-input"
                        />
                        Aktiv
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="combination-create-submit"
                    >
                        Anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
