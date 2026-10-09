import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '@/components/feedback/states';
import { FormField, formSelectClass } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonPost } from '@/lib/json-post';

type CrmAccountRow = {
    id: number;
    type: string;
    type_label: string;
    name: string | null;
    is_provisional: boolean;
    meridian_number: string | null;
    meridian_pending: boolean;
    salesforce_account_id_raw: string | null;
};

type Filters = {
    type: string | null;
    provisional: boolean;
};

function filterHref(filters: Filters, patch: Partial<Filters>): string {
    const params = new URLSearchParams();
    const next = { ...filters, ...patch };
    if (next.type === 'customer' || next.type === 'agency') {
        params.set('type', next.type);
    }
    if (next.provisional) {
        params.set('provisional', '1');
    }
    const query = params.toString();
    return query === '' ? '/crm/accounts' : `/crm/accounts?${query}`;
}

export default function CrmAccountsIndex({
    accounts,
    filters,
    canImport,
    canCreateProvisional,
}: {
    accounts: CrmAccountRow[];
    filters: Filters;
    canImport: boolean;
    canCreateProvisional: boolean;
    canManageMatches: boolean;
}) {
    const [showProvisionalForm, setShowProvisionalForm] = useState(false);
    const [provName, setProvName] = useState('');
    const [provType, setProvType] = useState<'customer' | 'agency'>('customer');
    const [provEmail, setProvEmail] = useState('');
    const [provDomain, setProvDomain] = useState('');
    const [provBusy, setProvBusy] = useState(false);
    const [provError, setProvError] = useState<string | null>(null);

    async function submitProvisional() {
        if (provBusy || provName.trim() === '') {
            return;
        }
        setProvBusy(true);
        setProvError(null);
        try {
            await jsonPost('/crm/accounts/vorlaeufig', {
                name: provName.trim(),
                type: provType,
                billing_email: provEmail.trim() || null,
                matching_domain: provDomain.trim() || null,
            });
            setProvName('');
            setProvEmail('');
            setProvDomain('');
            setShowProvisionalForm(false);
            router.reload({ only: ['accounts'] });
        } catch (err) {
            setProvError(
                err instanceof Error ? err.message : 'Anlegen fehlgeschlagen.',
            );
        } finally {
            setProvBusy(false);
        }
    }

    return (
        <>
            <Head title="CRM-Accounts" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="crm-accounts-index"
            >
                <PageHeader
                    title="Stammdaten · CRM-Accounts"
                    description="Salesforce-Kunden und -Agenturen, vorläufige Accounts und Zuordnung."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canImport ? (
                                <Button variant="outline" asChild>
                                    <Link href="/administration/crm/import">
                                        CSV-Import
                                    </Link>
                                </Button>
                            ) : null}
                            <Button variant="outline" asChild>
                                <Link href="/crm/accounts/pruefliste">
                                    Prüfliste
                                </Link>
                            </Button>
                            {canCreateProvisional ? (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        setShowProvisionalForm((v) => !v)
                                    }
                                >
                                    Vorläufig anlegen
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="flex flex-wrap gap-4 text-sm">
                    <label className="flex items-center gap-2">
                        Typ
                        <select
                            className={formSelectClass}
                            value={filters.type ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        type:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    }),
                                )
                            }
                        >
                            <option value="">Alle</option>
                            <option value="customer">Kunde</option>
                            <option value="agency">Agentur</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={filters.provisional}
                            onChange={(event) =>
                                router.get(
                                    filterHref(filters, {
                                        provisional: event.target.checked,
                                    }),
                                )
                            }
                        />
                        Nur vorläufig
                    </label>
                </div>

                {showProvisionalForm && canCreateProvisional ? (
                    <div className="max-w-lg space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Vorläufiger Account</h2>
                        <FormField label="Name" htmlFor="prov-name">
                            <Input
                                id="prov-name"
                                value={provName}
                                onChange={(e) => setProvName(e.target.value)}
                                disabled={provBusy}
                            />
                        </FormField>
                        <FormField label="Typ" htmlFor="prov-type">
                            <select
                                id="prov-type"
                                className={formSelectClass}
                                value={provType}
                                onChange={(e) =>
                                    setProvType(
                                        e.target.value as 'customer' | 'agency',
                                    )
                                }
                                disabled={provBusy}
                            >
                                <option value="customer">Kunde</option>
                                <option value="agency">Agentur</option>
                            </select>
                        </FormField>
                        <FormField
                            label="Rechnungs-E-Mail (optional)"
                            htmlFor="prov-email"
                        >
                            <Input
                                id="prov-email"
                                value={provEmail}
                                onChange={(e) => setProvEmail(e.target.value)}
                                disabled={provBusy}
                            />
                        </FormField>
                        <FormField
                            label="Matching-Domain (optional)"
                            htmlFor="prov-domain"
                        >
                            <Input
                                id="prov-domain"
                                value={provDomain}
                                onChange={(e) => setProvDomain(e.target.value)}
                                disabled={provBusy}
                            />
                        </FormField>
                        {provError ? (
                            <p className="text-sm text-red-700">{provError}</p>
                        ) : null}
                        <Button
                            type="button"
                            disabled={provBusy || provName.trim() === ''}
                            onClick={() => void submitProvisional()}
                        >
                            {provBusy ? 'Speichere…' : 'Anlegen'}
                        </Button>
                    </div>
                ) : null}

                {accounts.length === 0 ? (
                    <EmptyState title="Keine Accounts gefunden." />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2">ID</th>
                                    <th className="px-3 py-2">Typ</th>
                                    <th className="px-3 py-2">Name</th>
                                    <th className="px-3 py-2">Salesforce</th>
                                    <th className="px-3 py-2">Meridian</th>
                                    <th className="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((account) => (
                                    <tr key={account.id} className="border-t">
                                        <td className="px-3 py-2 font-mono">
                                            {account.id}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.type_label}
                                            {account.is_provisional
                                                ? ' · vorläufig'
                                                : ''}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.name ?? '–'}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {account.salesforce_account_id_raw ??
                                                '–'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.meridian_pending
                                                ? 'Meridian-Nummer folgt'
                                                : (account.meridian_number ??
                                                  '–')}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={`/crm/accounts/${account.id}`}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                Details
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
