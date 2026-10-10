import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type AccountVersion = {
    id: number;
    version_number: number;
    name: string;
    billing_email: string | null;
    matching_domain: string | null;
    meridian_number: string | null;
    salesforce_record_type: string | null;
    source: string;
    created_at: string | null;
    created_by: string | null;
};

type CrmAccountDetail = {
    id: number;
    type: string;
    type_label: string;
    is_provisional: boolean;
    matching_domain: string | null;
    salesforce_account_id_raw: string | null;
    salesforce_account_id_canonical: string | null;
    merged_into_account_id: number | null;
    name: string | null;
    billing_email: string | null;
    meridian_number: string | null;
    salesforce_record_type: string | null;
    meridian_pending: boolean;
    version_number: number | null;
    versions?: AccountVersion[];
};

type ConflictRow = {
    id: number;
    type: string;
    status: string;
    details: Record<string, unknown> | null;
    created_at: string | null;
};

export default function CrmAccountShow({
    account,
    conflicts,
}: {
    account: CrmAccountDetail;
    conflicts: ConflictRow[];
    canManageMatches: boolean;
}) {
    return (
        <>
            <Head title={account.name ?? `Account ${account.id}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={account.name ?? `Account #${account.id}`}
                    description={`${account.type_label}${account.is_provisional ? ' · vorläufig' : ''}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/crm/accounts">Zur Liste</Link>
                        </Button>
                    }
                />

                <div className="grid max-w-3xl gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2">
                    <div>
                        <p className="text-muted-foreground">Interne ID</p>
                        <p className="font-mono">{account.id}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Salesforce-ID</p>
                        <p className="font-mono">
                            {account.salesforce_account_id_raw ?? '–'}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Meridian</p>
                        <p>
                            {account.meridian_pending
                                ? 'Meridian-Nummer folgt'
                                : (account.meridian_number ?? '–')}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">
                            Rechnungs-E-Mail
                        </p>
                        <p>{account.billing_email ?? '–'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Matching-Domain</p>
                        <p>{account.matching_domain ?? '–'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">
                            Salesforce-Datensatztyp
                        </p>
                        <p className="font-mono">
                            {account.salesforce_record_type ?? '–'}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Interner Typ</p>
                        <p>{account.type_label}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Version</p>
                        <p>{account.version_number ?? '–'}</p>
                    </div>
                    {account.merged_into_account_id ? (
                        <div className="sm:col-span-2">
                            <p className="text-muted-foreground">
                                Zusammengeführt in
                            </p>
                            <Link
                                href={`/crm/accounts/${account.merged_into_account_id}`}
                                className="text-primary underline"
                            >
                                Account #{account.merged_into_account_id}
                            </Link>
                        </div>
                    ) : null}
                </div>

                {conflicts.length > 0 ? (
                    <div className="space-y-2">
                        <h2 className="text-base font-semibold">Konflikte</h2>
                        <ul className="space-y-2 text-sm">
                            {conflicts.map((conflict) => (
                                <li
                                    key={conflict.id}
                                    className="rounded-xl border p-3"
                                >
                                    <p>
                                        <strong>{conflict.type}</strong> ·{' '}
                                        {conflict.status}
                                    </p>
                                    {conflict.details ? (
                                        <pre className="text-muted-foreground mt-1 overflow-x-auto text-xs">
                                            {JSON.stringify(
                                                conflict.details,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {account.versions && account.versions.length > 0 ? (
                    <div className="space-y-2">
                        <h2 className="text-base font-semibold">Versionen</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-2 py-2">v</th>
                                        <th className="px-2 py-2">Name</th>
                                        <th className="px-2 py-2">SF-Typ</th>
                                        <th className="px-2 py-2">Meridian</th>
                                        <th className="px-2 py-2">Quelle</th>
                                        <th className="px-2 py-2">Erstellt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {account.versions.map((version) => (
                                        <tr
                                            key={version.id}
                                            className="border-t"
                                        >
                                            <td className="px-2 py-1">
                                                {version.version_number}
                                            </td>
                                            <td className="px-2 py-1">
                                                {version.name}
                                            </td>
                                            <td className="px-2 py-1 font-mono text-xs">
                                                {version.salesforce_record_type ??
                                                    '–'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {version.meridian_number ??
                                                    'Meridian-Nummer folgt'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {version.source}
                                            </td>
                                            <td className="px-2 py-1">
                                                {version.created_by ?? '–'}
                                                {version.created_at
                                                    ? ` · ${version.created_at}`
                                                    : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : null}
            </div>
        </>
    );
}
