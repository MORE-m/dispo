import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstApiError, oaCsrfHeaders } from '@/lib/oa-tkp-admin';

type PlatformRow = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    sort: number;
    is_adserver_relevant: boolean;
};

type Notes = {
    no_pricing?: string;
    no_auto_seed?: string;
};

type JsonMessage = {
    message?: string;
    errors?: string[] | Record<string, string[]>;
};

export default function OaPlatformsIndex({
    platforms,
    notes,
}: {
    platforms: PlatformRow[];
    notes: Notes;
}) {
    const [createCode, setCreateCode] = useState('');
    const [createName, setCreateName] = useState('');
    const [createSort, setCreateSort] = useState('0');
    const [createActive, setCreateActive] = useState(true);
    const [createAdserver, setCreateAdserver] = useState(false);
    const [edits, setEdits] = useState<
        Record<
            number,
            { name: string; sort: string; is_adserver_relevant: boolean }
        >
    >(() =>
        Object.fromEntries(
            platforms.map((p) => [
                p.id,
                {
                    name: p.name,
                    sort: String(p.sort),
                    is_adserver_relevant: p.is_adserver_relevant,
                },
            ]),
        ),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    useEffect(() => {
        setEdits(
            Object.fromEntries(
                platforms.map((p) => [
                    p.id,
                    {
                        name: p.name,
                        sort: String(p.sort),
                        is_adserver_relevant: p.is_adserver_relevant,
                    },
                ]),
            ),
        );
    }, [platforms]);

    async function request(
        method: 'POST' | 'PUT',
        url: string,
        body?: Record<string, unknown>,
    ): Promise<boolean> {
        if (busy) {
            return false;
        }
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(url, {
                method,
                headers: oaCsrfHeaders(),
                credentials: 'same-origin',
                body: body ? JSON.stringify(body) : undefined,
            });
            const result = (await response.json()) as JsonMessage;
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
        await request('POST', '/administration/online-audio/plattformen', {
            code: createCode.trim(),
            name: createName.trim(),
            sort: Number(createSort) || 0,
            is_active: createActive,
            is_adserver_relevant: createAdserver,
        });
    }

    async function onUpdate(platform: PlatformRow) {
        const edit = edits[platform.id];
        if (!edit) {
            return;
        }
        await request(
            'PUT',
            `/administration/online-audio/plattformen/${platform.id}`,
            {
                name: edit.name.trim(),
                sort: Number(edit.sort) || 0,
                is_adserver_relevant: edit.is_adserver_relevant,
            },
        );
    }

    async function onToggleActive(platform: PlatformRow, active: boolean) {
        const path = active ? 'aktivieren' : 'deaktivieren';
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(
                `/administration/online-audio/plattformen/${platform.id}/${path}`,
                {
                    method: 'POST',
                    headers: oaCsrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                },
            );
            const result = (await response.json()) as JsonMessage;
            if (!response.ok) {
                setError(
                    firstApiError(result, 'Die Aktion ist fehlgeschlagen.'),
                );

                return;
            }
            setSuccess(result.message ?? 'Aktualisiert.');
            router.reload();
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');
        } finally {
            setBusy(false);
        }
    }

    function patchEdit(
        id: number,
        patch: Partial<{
            name: string;
            sort: string;
            is_adserver_relevant: boolean;
        }>,
    ) {
        setEdits((prev) => ({
            ...prev,
            [id]: { ...prev[id], ...patch },
        }));
    }

    return (
        <>
            <Head title="OA Plattformen" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-platforms-index"
            >
                <PageHeader
                    title="Plattformen"
                    description="Plattform-Stammdaten ohne TKP-Preise."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/online-audio">
                                Online Audio
                            </Link>
                        </Button>
                    }
                />

                {notes.no_pricing ? (
                    <p className="text-muted-foreground text-sm">
                        {notes.no_pricing}
                    </p>
                ) : null}
                <p className="bg-muted/20 rounded-xl border p-3 text-sm">
                    {notes.no_auto_seed ??
                        'Spotify, YouTube und Deezer sind Midroll-Umfelder, werden hier aber nicht automatisch angelegt.'}
                </p>

                {success ? <SuccessState message={success} /> : null}
                {error ? <ErrorState message={error} /> : null}

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="oa-platform-create-form"
                    onSubmit={onCreate}
                >
                    <h2 className="text-base font-semibold">Neue Plattform</h2>
                    <FormField label="Code" htmlFor="platform-code">
                        <Input
                            id="platform-code"
                            value={createCode}
                            disabled={busy}
                            onChange={(event) =>
                                setCreateCode(event.target.value)
                            }
                        />
                    </FormField>
                    <FormField label="Name" htmlFor="platform-name">
                        <Input
                            id="platform-name"
                            value={createName}
                            disabled={busy}
                            onChange={(event) =>
                                setCreateName(event.target.value)
                            }
                        />
                    </FormField>
                    <FormField label="Sortierung" htmlFor="platform-sort">
                        <Input
                            id="platform-sort"
                            type="number"
                            min={0}
                            value={createSort}
                            disabled={busy}
                            onChange={(event) =>
                                setCreateSort(event.target.value)
                            }
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={createActive}
                            disabled={busy}
                            onChange={(event) =>
                                setCreateActive(event.target.checked)
                            }
                        />
                        Aktiv anlegen
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={createAdserver}
                            disabled={busy}
                            onChange={(event) =>
                                setCreateAdserver(event.target.checked)
                            }
                        />
                        Adserver-relevant
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
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Sort</th>
                                <th className="px-3 py-2 font-medium">
                                    Adserver
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {platforms.map((platform) => {
                                const edit = edits[platform.id] ?? {
                                    name: platform.name,
                                    sort: String(platform.sort),
                                    is_adserver_relevant:
                                        platform.is_adserver_relevant,
                                };

                                return (
                                    <tr key={platform.id} className="border-t">
                                        <td className="px-3 py-2 font-mono">
                                            {platform.code}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Input
                                                value={edit.name}
                                                disabled={busy}
                                                onChange={(event) =>
                                                    patchEdit(platform.id, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </td>
                                        <td className="w-24 px-3 py-2">
                                            <Input
                                                type="number"
                                                min={0}
                                                className="font-mono"
                                                value={edit.sort}
                                                disabled={busy}
                                                onChange={(event) =>
                                                    patchEdit(platform.id, {
                                                        sort: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    edit.is_adserver_relevant
                                                }
                                                disabled={busy}
                                                onChange={(event) =>
                                                    patchEdit(platform.id, {
                                                        is_adserver_relevant:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            {platform.is_active
                                                ? 'Aktiv'
                                                : 'Inaktiv'}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void onUpdate(platform)
                                                    }
                                                >
                                                    Speichern
                                                </Button>
                                                {platform.is_active ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void onToggleActive(
                                                                platform,
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        Deaktivieren
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void onToggleActive(
                                                                platform,
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        Aktivieren
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
