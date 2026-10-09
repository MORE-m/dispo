import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { jsonPost } from '@/lib/json-post';

type CrmAccountRow = {
    id: number;
    type_label: string;
    name: string | null;
    matching_domain: string | null;
    salesforce_account_id_raw: string | null;
};

type QueueRow = {
    account: CrmAccountRow;
    candidates: CrmAccountRow[];
    shared_domain: boolean;
};

type OpenConflict = {
    id: number;
    type: string;
    details: Record<string, unknown> | null;
    account: CrmAccountRow | null;
};

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

    return (
        <>
            <Head title="CRM-Prüfliste" />
            <div className="flex flex-1 flex-col gap-6 p-6">
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
                    <div className="space-y-2">
                        <h2 className="text-base font-semibold">
                            Offene Konflikte
                        </h2>
                        <ul className="space-y-2 text-sm">
                            {conflicts.map((conflict) => (
                                <li
                                    key={conflict.id}
                                    className="rounded-xl border p-3"
                                >
                                    <p>
                                        <strong>{conflict.type}</strong>
                                        {conflict.account ? (
                                            <>
                                                {' '}
                                                ·{' '}
                                                <Link
                                                    href={`/crm/accounts/${conflict.account.id}`}
                                                    className="underline"
                                                >
                                                    {conflict.account.name ??
                                                        conflict.account.id}
                                                </Link>
                                            </>
                                        ) : null}
                                    </p>
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
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <div>
                                        <p className="font-medium">
                                            {row.account.name ?? '–'} (
                                            {row.account.type_label})
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            ID {row.account.id}
                                            {row.account.matching_domain
                                                ? ` · ${row.account.matching_domain}`
                                                : ''}
                                        </p>
                                    </div>
                                </div>

                                {row.shared_domain ? (
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Gemeinsame Domain – automatische
                                        Kandidaten unterdrückt.
                                    </p>
                                ) : null}

                                {row.candidates.length === 0 ? (
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Keine Kandidaten.
                                    </p>
                                ) : (
                                    <ul className="mt-3 space-y-2 text-sm">
                                        {row.candidates.map((candidate) => (
                                            <li
                                                key={candidate.id}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                                            >
                                                <span>
                                                    {candidate.name ?? '–'} · SF{' '}
                                                    {candidate.salesforce_account_id_raw ??
                                                        '–'}{' '}
                                                    (ID {candidate.id})
                                                </span>
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
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
