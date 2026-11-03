import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    EmptyState,
    ErrorState,
    SuccessState,
} from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import {
    buildPlatformMediumSavePayload,
    firstApiError,
    isPlatformMediumMatrixDirty,
    oaCsrfHeaders,
    platformMediumAriaLabel,
    type OaPlatformMediumRow,
} from '@/lib/oa-tkp-admin';

type MediumColumn = {
    id: number;
    code: string;
    name: string;
};

type Notes = {
    effect?: string;
    inactive_platform?: string;
    no_hard_delete?: string;
    empty_platforms?: string;
};

type JsonMessage = {
    message?: string;
    errors?: string[] | Record<string, string[]>;
};

export default function OaPlatformMediumRulesIndex({
    revision_token,
    media,
    platforms,
    notes,
}: {
    revision_token: string;
    media: MediumColumn[];
    platforms: OaPlatformMediumRow[];
    notes: Notes;
}) {
    const [token, setToken] = useState(revision_token);
    const [rows, setRows] = useState(platforms);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const [conflict, setConflict] = useState(false);

    useEffect(() => {
        setToken(revision_token);
        setRows(platforms);
    }, [revision_token, platforms]);

    const dirty = isPlatformMediumMatrixDirty(platforms, rows);
    const mediumNameByCode = Object.fromEntries(
        media.map((medium) => [medium.code, medium.name]),
    );

    function toggle(platformId: number, mediumCode: string) {
        setRows((current) =>
            current.map((platform) =>
                platform.id !== platformId
                    ? platform
                    : {
                          ...platform,
                          cells: platform.cells.map((cell) =>
                              cell.medium_code !== mediumCode
                                  ? cell
                                  : { ...cell, is_active: !cell.is_active },
                          ),
                      },
            ),
        );
    }

    async function onSave() {
        if (busy || !dirty) {
            return;
        }
        setBusy(true);
        setError(null);
        setSuccess(null);
        setConflict(false);
        try {
            const response = await fetch(
                '/administration/online-audio/plattform-medien',
                {
                    method: 'PUT',
                    headers: oaCsrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify(
                        buildPlatformMediumSavePayload(token, rows),
                    ),
                },
            );
            const result = (await response.json()) as JsonMessage;
            if (response.status === 409) {
                setConflict(true);
                setError(
                    firstApiError(
                        result,
                        'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.',
                    ),
                );

                return;
            }
            if (!response.ok) {
                setError(
                    firstApiError(
                        result,
                        'Die Matrix konnte nicht gespeichert werden.',
                    ),
                );

                return;
            }
            setSuccess(result.message ?? 'Matrix gespeichert.');
            router.reload();
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Plattform × Medium" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-platform-medium-rules-index"
            >
                <PageHeader
                    title="Plattform × Medium"
                    description="Zulässige OA-Kernmedien je vorhandener Plattform."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/online-audio">
                                Online Audio
                            </Link>
                        </Button>
                    }
                />

                <p className="text-muted-foreground text-sm">
                    {notes.effect ??
                        'Zuordnungen werden nur als Stammdaten gespeichert. Bestehende Kalkulationen und Preise werden dadurch nicht verändert.'}
                </p>
                {notes.inactive_platform ? (
                    <p className="text-muted-foreground text-sm">
                        {notes.inactive_platform}
                    </p>
                ) : null}

                {success ? <SuccessState message={success} /> : null}
                {error ? <ErrorState message={error} /> : null}
                {conflict ? (
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => router.reload()}
                        >
                            Neu laden
                        </Button>
                    </div>
                ) : null}

                {platforms.length === 0 ? (
                    <div data-test="oa-platform-medium-empty">
                        <EmptyState
                            title="Noch keine Plattformen angelegt."
                            action={
                                <Button variant="outline" asChild>
                                    <Link href="/administration/online-audio/plattformen">
                                        Plattformen öffnen
                                    </Link>
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    <>
                        {dirty ? (
                            <p
                                className="text-sm"
                                data-test="oa-platform-medium-dirty"
                            >
                                Ungespeicherte Änderungen
                            </p>
                        ) : null}
                        <div className="overflow-x-auto rounded-xl border">
                            <table
                                className="w-full text-left text-sm"
                                data-test="oa-platform-medium-matrix"
                            >
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th
                                            className="px-3 py-2 font-medium"
                                            scope="col"
                                        >
                                            Plattform
                                        </th>
                                        <th
                                            className="px-3 py-2 font-medium"
                                            scope="col"
                                        >
                                            Status
                                        </th>
                                        {media.map((medium) => (
                                            <th
                                                key={medium.code}
                                                className="px-3 py-2 font-medium"
                                                scope="col"
                                            >
                                                {medium.name}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((platform) => (
                                        <tr
                                            key={platform.id}
                                            className="border-t"
                                        >
                                            <th
                                                className="px-3 py-2 text-left font-medium"
                                                scope="row"
                                            >
                                                <div>{platform.name}</div>
                                                <div className="text-muted-foreground font-mono text-xs font-normal">
                                                    {platform.code}
                                                </div>
                                            </th>
                                            <td className="px-3 py-2">
                                                {platform.is_active
                                                    ? 'Aktiv'
                                                    : 'Inaktiv'}
                                            </td>
                                            {platform.cells.map((cell) => (
                                                <td
                                                    key={cell.medium_code}
                                                    className="px-3 py-2"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={cell.is_active}
                                                        disabled={busy}
                                                        aria-label={platformMediumAriaLabel(
                                                            platform.name,
                                                            mediumNameByCode[
                                                                cell.medium_code
                                                            ] ??
                                                                cell.medium_code,
                                                        )}
                                                        onChange={() =>
                                                            toggle(
                                                                platform.id,
                                                                cell.medium_code,
                                                            )
                                                        }
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <Button
                                type="button"
                                data-test="oa-platform-medium-save"
                                disabled={busy || !dirty}
                                onClick={() => void onSave()}
                            >
                                Matrix speichern
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
