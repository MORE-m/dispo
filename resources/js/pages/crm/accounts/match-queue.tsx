import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonPost } from '@/lib/json-post';

type AccountLabel = {
    title: string;
    meta: string[];
    identity: string[];
};

type CrmAccountRow = {
    id: number;
    type: string;
    type_label: string;
    name: string | null;
    matching_domain: string | null;
    salesforce_account_id_raw: string | null;
    meridian_number: string | null;
    meridian_pending?: boolean;
    is_provisional?: boolean;
    salesforce_record_type?: string | null;
    label: AccountLabel;
};

type QueueRow = {
    account: CrmAccountRow;
    candidates: CrmAccountRow[];
    shared_domain: boolean;
};

type OpenConflict = {
    id: number;
    type: string;
    type_label: string;
    details: Record<string, unknown> | null;
    summary_lines: string[];
    account: CrmAccountRow | null;
};

function AccountLabelBlock({
    account,
    role,
}: {
    account: CrmAccountRow;
    role?: string;
}) {
    return (
        <div data-test={role ? `crm-account-label-${role}` : undefined}>
            <p className="font-medium">{account.label.title}</p>
            {account.label.meta.length > 0 ? (
                <p className="text-muted-foreground text-sm">
                    {account.label.meta.join(' · ')}
                </p>
            ) : null}
            {account.label.identity.length > 0 ? (
                <p className="text-muted-foreground font-mono text-xs">
                    {account.label.identity.join(' · ')}
                </p>
            ) : null}
        </div>
    );
}

export default function CrmMatchQueuePage({
    rows,
    conflicts,
    canManageMatches,
}: {
    rows: QueueRow[];
    conflicts: OpenConflict[];
    canManageMatches: boolean;
}) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [searchQ, setSearchQ] = useState<Record<number, string>>({});
    const [searchHits, setSearchHits] = useState<
        Record<number, CrmAccountRow[]>
    >({});
    const [searchLoading, setSearchLoading] = useState<Record<number, boolean>>(
        {},
    );
    const searchSeq = useRef<Record<number, number>>({});
    const [conflictNote, setConflictNote] = useState<Record<number, string>>(
        {},
    );

    async function linkAccount(
        provisionalId: number,
        salesforceAccountId: number,
    ) {
        if (!canManageMatches || busyId !== null) {
            return;
        }
        setBusyId(provisionalId);
        setError(null);
        try {
            await jsonPost(`/crm/accounts/${provisionalId}/verknuepfen`, {
                salesforce_account_id: salesforceAccountId,
            });
            router.reload();
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Verknüpfen fehlgeschlagen.',
            );
        } finally {
            setBusyId(null);
        }
    }

    async function searchTargets(row: QueueRow) {
        const accountId = row.account.id;
        const q = (searchQ[accountId] ?? '').trim();
        const seq = (searchSeq.current[accountId] ?? 0) + 1;
        searchSeq.current[accountId] = seq;
        setSearchLoading((prev) => ({ ...prev, [accountId]: true }));
        setError(null);

        try {
            const response = await fetch(
                `/crm/accounts/suche?salesforce_only=1&type=${encodeURIComponent(row.account.type)}&q=${encodeURIComponent(q)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );
            if (!response.ok) {
                throw new Error('Suche fehlgeschlagen.');
            }
            const data = (await response.json()) as {
                accounts?: CrmAccountRow[];
            };
            if (searchSeq.current[accountId] !== seq) {
                return;
            }
            setSearchHits((prev) => ({
                ...prev,
                [accountId]: data.accounts ?? [],
            }));
        } catch (err) {
            if (searchSeq.current[accountId] !== seq) {
                return;
            }
            setSearchHits((prev) => ({ ...prev, [accountId]: [] }));
            setError(
                err instanceof Error ? err.message : 'Suche fehlgeschlagen.',
            );
        } finally {
            if (searchSeq.current[accountId] === seq) {
                setSearchLoading((prev) => ({ ...prev, [accountId]: false }));
            }
        }
    }

    async function resolveConflict(conflictId: number) {
        if (!canManageMatches || busyId !== null) {
            return;
        }
        setBusyId(conflictId);
        setError(null);
        try {
            await jsonPost(`/crm/konflikte/${conflictId}/abschliessen`, {
                note: conflictNote[conflictId] ?? '',
            });
            router.reload();
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Konflikt abschließen fehlgeschlagen.',
            );
        } finally {
            setBusyId(null);
        }
    }

    return (
        <>
            <Head title="CRM-Prüfliste" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="crm-match-queue"
            >
                <PageHeader
                    title="Prüfliste · Vorläufige Accounts"
                    description="Manuelle Zuordnung zu Salesforce-Accounts und offene Konflikte."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/crm/accounts">Zur Liste</Link>
                        </Button>
                    }
                />

                {error ? <p className="text-sm text-red-700">{error}</p> : null}

                {conflicts.length > 0 ? (
                    <div className="space-y-2" data-test="crm-open-conflicts">
                        <h2 className="text-base font-semibold">
                            Offene Konflikte
                        </h2>
                        <ul className="space-y-2 text-sm">
                            {conflicts.map((conflict) => (
                                <li
                                    key={conflict.id}
                                    className="space-y-2 rounded-xl border p-3"
                                    data-test={`crm-conflict-${conflict.id}`}
                                >
                                    <p>
                                        <strong>{conflict.type_label}</strong>
                                    </p>
                                    {conflict.account ? (
                                        <AccountLabelBlock
                                            account={conflict.account}
                                            role={`conflict-${conflict.id}`}
                                        />
                                    ) : (
                                        <p className="text-muted-foreground">
                                            Account-Referenz nicht mehr
                                            verfügbar
                                        </p>
                                    )}
                                    {conflict.summary_lines.length > 0 ? (
                                        <ul
                                            className="text-muted-foreground list-disc space-y-1 pl-5"
                                            data-test={`crm-conflict-summary-${conflict.id}`}
                                        >
                                            {conflict.summary_lines.map(
                                                (line) => (
                                                    <li key={line}>{line}</li>
                                                ),
                                            )}
                                        </ul>
                                    ) : null}
                                    {canManageMatches ? (
                                        <div className="flex flex-wrap items-end gap-2">
                                            <FormField
                                                label="Notiz (ohne Überschreiben)"
                                                htmlFor={`conflict-note-${conflict.id}`}
                                            >
                                                <Input
                                                    id={`conflict-note-${conflict.id}`}
                                                    value={
                                                        conflictNote[
                                                            conflict.id
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setConflictNote(
                                                            (prev) => ({
                                                                ...prev,
                                                                [conflict.id]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </FormField>
                                            <Button
                                                type="button"
                                                size="sm"
                                                data-test={`crm-conflict-resolve-${conflict.id}`}
                                                disabled={
                                                    busyId === conflict.id
                                                }
                                                onClick={() =>
                                                    void resolveConflict(
                                                        conflict.id,
                                                    )
                                                }
                                            >
                                                Als geprüft abschließen
                                            </Button>
                                        </div>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {rows.length === 0 ? (
                    <EmptyState title="Keine vorläufigen Accounts in der Warteschlange." />
                ) : (
                    <div className="space-y-4">
                        {rows.map((row) => (
                            <div
                                key={row.account.id}
                                className="rounded-xl border p-4"
                                data-test={`crm-queue-row-${row.account.id}`}
                            >
                                <div className="space-y-1">
                                    <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                        Quelle (vorläufig)
                                    </p>
                                    <AccountLabelBlock
                                        account={row.account}
                                        role={`source-${row.account.id}`}
                                    />
                                </div>

                                {row.shared_domain ? (
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Gemeinsame Domain – automatische
                                        Kandidaten unterdrückt. Manuelle Suche
                                        bleibt möglich.
                                    </p>
                                ) : null}

                                {row.candidates.length > 0 ? (
                                    <ul className="mt-3 space-y-2 text-sm">
                                        {row.candidates.map((candidate) => (
                                            <li
                                                key={candidate.id}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                                            >
                                                <div>
                                                    <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                                        Ziel (Domain-Treffer)
                                                    </p>
                                                    <AccountLabelBlock
                                                        account={candidate}
                                                        role={`candidate-${candidate.id}`}
                                                    />
                                                </div>
                                                {canManageMatches ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        disabled={
                                                            busyId ===
                                                            row.account.id
                                                        }
                                                        onClick={() =>
                                                            void linkAccount(
                                                                row.account.id,
                                                                candidate.id,
                                                            )
                                                        }
                                                    >
                                                        Verknüpfen
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Keine automatischen Domain-Kandidaten.
                                    </p>
                                )}

                                {canManageMatches ? (
                                    <div className="mt-4 space-y-2 rounded-md border p-3">
                                        <p className="text-sm font-medium">
                                            Salesforce-Ziel manuell suchen
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            <Input
                                                data-test={`crm-manual-search-${row.account.id}`}
                                                value={
                                                    searchQ[row.account.id] ??
                                                    ''
                                                }
                                                onChange={(event) => {
                                                    const value =
                                                        event.target.value;
                                                    setSearchQ((prev) => ({
                                                        ...prev,
                                                        [row.account.id]: value,
                                                    }));
                                                    searchSeq.current[
                                                        row.account.id
                                                    ] =
                                                        (searchSeq.current[
                                                            row.account.id
                                                        ] ?? 0) + 1;
                                                    setSearchHits((prev) => ({
                                                        ...prev,
                                                        [row.account.id]: [],
                                                    }));
                                                    setSearchLoading(
                                                        (prev) => ({
                                                            ...prev,
                                                            [row.account.id]:
                                                                false,
                                                        }),
                                                    );
                                                }}
                                                placeholder="Name, SF-ID, Meridian…"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                data-test={`crm-manual-search-btn-${row.account.id}`}
                                                onClick={() =>
                                                    void searchTargets(row)
                                                }
                                            >
                                                Suchen
                                            </Button>
                                        </div>
                                        {searchLoading[row.account.id] ? (
                                            <p className="text-muted-foreground text-sm">
                                                Suche läuft…
                                            </p>
                                        ) : null}
                                        <ul className="space-y-2 text-sm">
                                            {(
                                                searchHits[row.account.id] ?? []
                                            ).map((hit) => (
                                                <li
                                                    key={hit.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                                                >
                                                    <div>
                                                        <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                                            Ziel (Suche)
                                                        </p>
                                                        <AccountLabelBlock
                                                            account={hit}
                                                            role={`hit-${hit.id}`}
                                                        />
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        data-test={`crm-manual-link-${row.account.id}-${hit.id}`}
                                                        disabled={
                                                            busyId ===
                                                            row.account.id
                                                        }
                                                        onClick={() =>
                                                            void linkAccount(
                                                                row.account.id,
                                                                hit.id,
                                                            )
                                                        }
                                                    >
                                                        Verknüpfen
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
