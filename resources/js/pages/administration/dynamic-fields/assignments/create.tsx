import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    filterAssignmentProcessOptions,
    resolveAssignmentProcess,
} from '@/lib/field-set-assignment-process';
import { JsonPostError, jsonPost } from '@/lib/json-post';

type Option = { value: string; label: string };

type FieldSetOption = {
    id: number;
    key: string;
    name: string;
    applies_to: string;
    applies_to_label: string;
};

type CategoryOption = {
    id: number;
    key: string;
    name: string;
    is_active: boolean;
    label: string;
};

type MediumOption = {
    id: number;
    code: string;
    name: string;
    category_id: number;
    category_name: string | null;
    is_active: boolean;
    label: string;
};

type FormOptions = {
    fieldSets: FieldSetOption[];
    categories: CategoryOption[];
    media: MediumOption[];
    targetLayerOptions: Option[];
    processOptions: Option[];
};

function firstFieldError(
    fieldErrors: Record<string, string[]>,
    key: string,
): string | undefined {
    return fieldErrors[key]?.[0];
}

export default function AssignmentCreate({
    formOptions,
    catalogNote,
}: {
    formOptions: FormOptions;
    catalogNote: string;
}) {
    const initialFieldSet = formOptions.fieldSets[0];
    const [fieldSetId, setFieldSetId] = useState(
        initialFieldSet?.id?.toString() ?? '',
    );
    const [targetLayer, setTargetLayer] = useState('global');
    const [categoryId, setCategoryId] = useState(
        formOptions.categories.find((c) => c.is_active)?.id?.toString() ?? '',
    );
    const [mediumId, setMediumId] = useState(
        formOptions.media.find((m) => m.is_active)?.id?.toString() ?? '',
    );
    const [process, setProcess] = useState(() =>
        resolveAssignmentProcess(initialFieldSet?.applies_to),
    );
    const [sort, setSort] = useState('0');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );

    const selectedFieldSet = useMemo(
        () =>
            formOptions.fieldSets.find(
                (set) => set.id.toString() === fieldSetId,
            ),
        [fieldSetId, formOptions.fieldSets],
    );

    const processChoices = useMemo(
        () =>
            filterAssignmentProcessOptions(
                formOptions.processOptions,
                selectedFieldSet?.applies_to,
            ),
        [formOptions.processOptions, selectedFieldSet],
    );

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        if (busy || fieldSetId === '' || process === '') {
            return;
        }

        setBusy(true);
        setError(null);
        setFieldErrors({});

        const payload: Record<string, unknown> = {
            field_set_id: Number(fieldSetId),
            target_layer: targetLayer,
            applies_to_process: process,
            sort: Number(sort || 0),
            advertising_category_id: null,
            advertising_medium_id: null,
        };

        if (targetLayer === 'advertising_category') {
            payload.advertising_category_id = Number(categoryId);
        }
        if (targetLayer === 'advertising_medium') {
            payload.advertising_medium_id = Number(mediumId);
        }

        try {
            const result = await jsonPost<{ redirect: string }>(
                '/administration/dynamische-felder/assignments',
                payload,
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setFieldErrors(caught.fieldErrors);
                setError(
                    caught.message ||
                        'Das Assignment konnte nicht angelegt werden.',
                );
            } else {
                setError('Das Assignment konnte nicht angelegt werden.');
            }
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Assignment anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Assignment anlegen"
                    description="Neue Zuordnungen entstehen zuerst inaktiv. Aktivierung erst nach Konflikt- und Herkunftsprüfung."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/dynamische-felder/assignments">
                                Zurück zur Liste
                            </Link>
                        </Button>
                    }
                />

                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="assignment-catalog-note"
                >
                    {catalogNote}
                </p>

                {error ? (
                    <ErrorState
                        message={error}
                        data-test="assignment-create-error"
                    />
                ) : null}

                {formOptions.fieldSets.length === 0 ? (
                    <ErrorState
                        message="Es gibt derzeit kein zuweisbares freies Feldset mit aktiver Version. Legen Sie zuerst ein Feldset an und aktivieren Sie eine Version."
                        data-test="assignment-create-no-sets"
                    />
                ) : (
                    <form
                        className="grid max-w-2xl gap-4"
                        onSubmit={submit}
                        data-test="assignment-create-form"
                    >
                        <FormField
                            label="Freies Feldset"
                            htmlFor="field_set_id"
                            error={firstFieldError(fieldErrors, 'field_set_id')}
                        >
                            <select
                                id="field_set_id"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={fieldSetId}
                                onChange={(event) => {
                                    const nextId = event.target.value;
                                    const next = formOptions.fieldSets.find(
                                        (set) => set.id.toString() === nextId,
                                    );
                                    setFieldSetId(nextId);
                                    setProcess((current) =>
                                        resolveAssignmentProcess(
                                            next?.applies_to,
                                            current,
                                        ),
                                    );
                                }}
                                data-test="assignment-fieldset-select"
                                required
                            >
                                {formOptions.fieldSets.map((set) => (
                                    <option key={set.id} value={set.id}>
                                        {set.name} ({set.key}) ·{' '}
                                        {set.applies_to_label}
                                    </option>
                                ))}
                            </select>
                        </FormField>

                        <FormField
                            label="Zielebene"
                            htmlFor="target_layer"
                            error={firstFieldError(fieldErrors, 'target_layer')}
                        >
                            <select
                                id="target_layer"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={targetLayer}
                                onChange={(event) =>
                                    setTargetLayer(event.target.value)
                                }
                                data-test="assignment-target-layer-select"
                                required
                            >
                                {formOptions.targetLayerOptions.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </FormField>

                        {targetLayer === 'advertising_category' ? (
                            <FormField
                                label="Oberkategorie"
                                htmlFor="advertising_category_id"
                                error={firstFieldError(
                                    fieldErrors,
                                    'advertising_category_id',
                                )}
                                hint="Nur aktive Oberkategorien aus dem bestehenden Katalog."
                            >
                                <select
                                    id="advertising_category_id"
                                    className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                    value={categoryId}
                                    onChange={(event) =>
                                        setCategoryId(event.target.value)
                                    }
                                    data-test="assignment-category-select"
                                    required
                                >
                                    {formOptions.categories
                                        .filter(
                                            (category) => category.is_active,
                                        )
                                        .map((category) => (
                                            <option
                                                key={category.id}
                                                value={category.id}
                                            >
                                                {category.label}
                                            </option>
                                        ))}
                                </select>
                            </FormField>
                        ) : null}

                        {targetLayer === 'advertising_medium' ? (
                            <FormField
                                label="Werbemittel"
                                htmlFor="advertising_medium_id"
                                error={firstFieldError(
                                    fieldErrors,
                                    'advertising_medium_id',
                                )}
                                hint="Nur aktive Werbemittel; die Oberkategorie wird aus dem Medium abgeleitet."
                            >
                                <select
                                    id="advertising_medium_id"
                                    className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                    value={mediumId}
                                    onChange={(event) =>
                                        setMediumId(event.target.value)
                                    }
                                    data-test="assignment-medium-select"
                                    required
                                >
                                    {formOptions.media
                                        .filter((medium) => medium.is_active)
                                        .map((medium) => (
                                            <option
                                                key={medium.id}
                                                value={medium.id}
                                            >
                                                {medium.label}
                                            </option>
                                        ))}
                                </select>
                            </FormField>
                        ) : null}

                        <FormField
                            label="Prozessbezug"
                            htmlFor="applies_to_process"
                            error={firstFieldError(
                                fieldErrors,
                                'applies_to_process',
                            )}
                            hint={
                                selectedFieldSet
                                    ? `Muss zur Feldset-Gültigkeit (${selectedFieldSet.applies_to_label}) passen.`
                                    : undefined
                            }
                        >
                            <select
                                id="applies_to_process"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={process}
                                onChange={(event) =>
                                    setProcess(event.target.value)
                                }
                                data-test="assignment-process-select"
                                required
                            >
                                {processChoices.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </FormField>

                        <FormField
                            label="Sortierung"
                            htmlFor="sort"
                            error={firstFieldError(fieldErrors, 'sort')}
                            hint="Niedrigere Werte haben innerhalb derselben Zielebene Vorrang."
                        >
                            <Input
                                id="sort"
                                type="number"
                                min={0}
                                value={sort}
                                onChange={(event) =>
                                    setSort(event.target.value)
                                }
                                data-test="assignment-sort-input"
                            />
                        </FormField>

                        {firstFieldError(fieldErrors, 'assignment') ? (
                            <ErrorState
                                message={firstFieldError(
                                    fieldErrors,
                                    'assignment',
                                )!}
                                data-test="assignment-create-conflict"
                            />
                        ) : null}

                        <div className="flex gap-2">
                            <Button
                                type="submit"
                                disabled={busy || process === ''}
                                data-test="assignment-create-submit"
                            >
                                {busy ? 'Wird angelegt…' : 'Inaktiv anlegen'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/administration/dynamische-felder/assignments">
                                    Abbrechen
                                </Link>
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}
