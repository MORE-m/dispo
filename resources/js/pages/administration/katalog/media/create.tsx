import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type CategoryOption = {
    id: number;
    key: string;
    name: string;
};

export default function MediaCreate({
    formOptions,
    catalogNote,
}: {
    formOptions: { categories: CategoryOption[] };
    catalogNote: string;
}) {
    const form = useForm({
        code: '',
        name: '',
        category_id: (formOptions.categories[0]?.id ?? '') as number | '',
        default_length_seconds: 30,
        is_discountable: true,
        is_ae_eligible: true,
        sort: 0,
        is_active: true,
    });

    return (
        <>
            <Head title="Werbemittel anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Werbemittel anlegen"
                    description="Der technische Code ist nach dem Speichern unveränderlich."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/katalog/werbemittel">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="medium-catalog-note"
                >
                    {catalogNote}
                </p>

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="medium-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/katalog/werbemittel');
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
                            data-test="medium-name-input"
                        />
                    </FormField>
                    <FormField
                        label="Technischer Code"
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
                            data-test="medium-code-input"
                        />
                    </FormField>
                    <FormField
                        label="Oberkategorie"
                        htmlFor="category_id"
                        error={form.errors.category_id}
                    >
                        <select
                            id="category_id"
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={form.data.category_id}
                            onChange={(e) =>
                                form.setData(
                                    'category_id',
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                            data-test="medium-category-select"
                        >
                            <option value="">Bitte wählen</option>
                            {formOptions.categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} ({c.key})
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Standardlänge (Sekunden)"
                        htmlFor="default_length_seconds"
                        error={form.errors.default_length_seconds}
                    >
                        <Input
                            id="default_length_seconds"
                            type="number"
                            min={1}
                            max={3600}
                            value={form.data.default_length_seconds}
                            onChange={(e) =>
                                form.setData(
                                    'default_length_seconds',
                                    Number(e.target.value || 30),
                                )
                            }
                            data-test="medium-length-input"
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
                            min={0}
                            value={form.data.sort}
                            onChange={(e) =>
                                form.setData(
                                    'sort',
                                    Number(e.target.value || 0),
                                )
                            }
                            data-test="medium-sort-input"
                        />
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
                            data-test="medium-discountable-input"
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
                            data-test="medium-ae-input"
                        />
                        AE-fähig
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="medium-create-submit"
                    >
                        Speichern
                    </Button>
                </form>
            </div>
        </>
    );
}
