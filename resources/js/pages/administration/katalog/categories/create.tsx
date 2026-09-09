import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export default function CategoryCreate() {
    const form = useForm({
        key: '',
        name: '',
        sort: 100,
        is_active: true,
    });

    return (
        <>
            <Head title="Oberkategorie anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Oberkategorie anlegen"
                    description="Der technische Key bleibt nach dem Speichern unveränderlich."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/katalog/oberkategorien">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="category-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/katalog/oberkategorien');
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
                            data-test="category-name-input"
                        />
                    </FormField>
                    <FormField
                        label="Technischer Key"
                        htmlFor="key"
                        error={form.errors.key}
                    >
                        <Input
                            id="key"
                            value={form.data.key}
                            onChange={(e) =>
                                form.setData('key', e.target.value)
                            }
                            className="font-mono"
                            data-test="category-key-input"
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
                            data-test="category-sort-input"
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) =>
                                form.setData('is_active', e.target.checked)
                            }
                            data-test="category-active-input"
                        />
                        Aktiv anlegen
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="category-create-submit"
                    >
                        Speichern
                    </Button>
                </form>
            </div>
        </>
    );
}
