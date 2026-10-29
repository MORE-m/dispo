import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstApiError, oaCsrfHeaders } from '@/lib/oa-tkp-admin';

type TargetingRow = {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label?: string;
    tkp_surcharge: string;
    minimum_tkp_surcharge: string;
    revision_number: number;
    is_active: boolean;
    valid_from?: string | null;
    valid_to?: string | null;
};

function categoryLabel(
    row: TargetingRow,
    categories: CategoryOption[],
): string {
    if (row.category_label) {
        return row.category_label;
    }

    return (
        categories.find((option) => option.value === row.category)?.label ??
        row.category
    );
}

type CategoryOption = {
    value: string;
    label: string;
};

type Notes = {
    geo_category?: string;
    revision_identity?: string;
};

export default function OaTargetingsIndex({
    targetings,
    categories,
    notes,
}: {
    targetings: TargetingRow[];
    categories: CategoryOption[];
    notes: Notes;
}) {
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [category, setCategory] = useState(categories[0]?.value ?? 'dmp');
    const [tkpSurcharge, setTkpSurcharge] = useState('');
    const [minimumSurcharge, setMinimumSurcharge] = useState('');
    const [revisionNumber, setRevisionNumber] = useState('1');
    const [isActive, setIsActive] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    async function postJson(
        url: string,
        body: Record<string, unknown>,
    ): Promise<boolean> {
        if (busy) {
            return false;
        }
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: oaCsrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const result = (await response.json()) as {
                message?: string;
                errors?: string[] | Record<string, string[]>;
            };
            if (!response.ok) {
                setError(
                    firstApiError(result, 'Die Aktion ist fehlgeschlagen.'),
                );

                return false;
            }
            setSuccess(result.message ?? 'Gespeichert.');
            router.reload();

            return true;
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');

            return false;
        } finally {
            setBusy(false);
        }
    }

    async function onCreate(event: React.FormEvent) {
        event.preventDefault();
        await postJson('/administration/online-audio/targetings', {
            code: code.trim(),
            name: name.trim(),
            category,
            tkp_surcharge: tkpSurcharge.trim(),
            minimum_tkp_surcharge: minimumSurcharge.trim() || null,
            revision_number: Number(revisionNumber) || 1,
            is_active: isActive,
        });
    }

    async function onNewRevision(row: TargetingRow) {
        await postJson(
            `/administration/online-audio/targetings/${encodeURIComponent(row.code)}/revisionen`,
            {
                tkp_surcharge: row.tkp_surcharge,
                minimum_tkp_surcharge: row.minimum_tkp_surcharge,
                deactivate_previous: true,
            },
        );
    }

    return (
        <>
            <Head title="OA Targetings" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-targetings-index"
            >
                <PageHeader
                    title="Targetings"
                    description="Listen- und Mindest-TKP-Aufschläge revisionssicher pflegen."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/online-audio">
                                Online Audio
                            </Link>
                        </Button>
                    }
                />

                {notes.geo_category ? (
                    <p className="text-muted-foreground text-sm">
                        {notes.geo_category}
                    </p>
                ) : null}
                {notes.revision_identity ? (
                    <p className="text-muted-foreground text-sm">
                        {notes.revision_identity}
                    </p>
                ) : null}

                <p className="bg-muted/20 rounded-xl border p-3 text-sm">
                    Abnahme-Hinweis: DMP typisch Listen/Mindest{' '}
                    <span className="font-mono">25,0000 / 5,0000</span>; Geo
                    unter Kategorie <span className="font-mono">technisch</span>{' '}
                    oft <span className="font-mono">15,0000 / 3,0000</span>.
                </p>

                {success ? <SuccessState message={success} /> : null}
                {error ? <ErrorState message={error} /> : null}

                <form
                    className="max-w-2xl space-y-4 rounded-xl border p-4"
                    data-test="oa-targeting-create-form"
                    onSubmit={onCreate}
                >
                    <h2 className="text-base font-semibold">Neues Targeting</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Code" htmlFor="targeting-code">
                            <Input
                                id="targeting-code"
                                value={code}
                                disabled={busy}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="Name" htmlFor="targeting-name">
                            <Input
                                id="targeting-name"
                                value={name}
                                disabled={busy}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Kategorie"
                            htmlFor="targeting-category"
                        >
                            <select
                                id="targeting-category"
                                className="w-full rounded-md border px-3 py-2"
                                value={category}
                                disabled={busy}
                                onChange={(event) =>
                                    setCategory(event.target.value)
                                }
                            >
                                {categories.map((option) => (
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
                            label="Revision"
                            htmlFor="targeting-revision"
                        >
                            <Input
                                id="targeting-revision"
                                type="number"
                                min={1}
                                value={revisionNumber}
                                disabled={busy}
                                onChange={(event) =>
                                    setRevisionNumber(event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Listen-Aufschlag (TKP)"
                            htmlFor="targeting-list"
                        >
                            <Input
                                id="targeting-list"
                                inputMode="decimal"
                                className="font-mono"
                                value={tkpSurcharge}
                                disabled={busy}
                                onChange={(event) =>
                                    setTkpSurcharge(event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Mindest-Aufschlag (TKP)"
                            htmlFor="targeting-min"
                        >
                            <Input
                                id="targeting-min"
                                inputMode="decimal"
                                className="font-mono"
                                value={minimumSurcharge}
                                disabled={busy}
                                onChange={(event) =>
                                    setMinimumSurcharge(event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={isActive}
                            disabled={busy}
                            onChange={(event) =>
                                setIsActive(event.target.checked)
                            }
                        />
                        Aktiv
                    </label>
                    <Button type="submit" disabled={busy}>
                        Anlegen
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-3 py-2 font-medium">Code</th>
                                <th className="px-3 py-2 font-medium">Rev.</th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">
                                    Kategorie
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Listen-Aufschlag
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Mindest-Aufschlag
                                </th>
                                <th className="px-3 py-2 font-medium">Aktiv</th>
                                <th className="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {targetings.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-3 py-2 font-mono">
                                        {row.code}
                                    </td>
                                    <td className="px-3 py-2 font-mono">
                                        {row.revision_number}
                                    </td>
                                    <td className="px-3 py-2">{row.name}</td>
                                    <td className="px-3 py-2">
                                        {categoryLabel(row, categories)}
                                    </td>
                                    <td className="px-3 py-2 font-mono">
                                        {row.tkp_surcharge} €
                                    </td>
                                    <td className="px-3 py-2 font-mono">
                                        {row.minimum_tkp_surcharge} €
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.is_active ? 'Ja' : 'Nein'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() =>
                                                void onNewRevision(row)
                                            }
                                        >
                                            Neue Revision
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
