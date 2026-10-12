import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { EmptyState } from '@/components/feedback/states';
import { FormField, formSelectClass } from '@/components/form-field';
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
    is_provisional: boolean;
    matching_domain: string | null;
    meridian_number: string | null;
    meridian_pending: boolean;
    salesforce_account_id_raw: string | null;
    salesforce_record_type: string | null;
    label: AccountLabel;
};

type Filters = {
    type: string | null;
    provisional: boolean;
    q: string | null;
};

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

function filterHref(
    filters: Filters,
    patch: Partial<Filters>,
    page?: number,
): string {
    const params = new URLSearchParams();
    const next = { ...filters, ...patch };
    if (next.type === 'customer' || next.type === 'agency') {
        params.set('type', next.type);
    }
    if (next.provisional) {
        params.set('provisional', '1');
    }
    if (next.q && next.q.trim() !== '') {
        params.set('q', next.q.trim());
    }
    if (page != null && page > 1) {
        params.set('page', String(page));
    }
    const query = params.toString();
    return query === '' ? '/crm/accounts' : `/crm/accounts?${query}`;
}

export default function CrmAccountsIndex({
    accounts,
    filters,
    pagination,
    canImport,
    canCreateProvisional,
}: {
    accounts: CrmAccountRow[];
    filters: Filters;
    pagination: Pagination;
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
    const [searchDraft, setSearchDraft] = useState(filters.q ?? '');

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
            router.reload({ only: ['accounts', 'pagination'] });
        } catch (err) {
            setProvError(
                err instanceof Error ? err.message : 'Anlegen fehlgeschlagen.',
            );
        } finally {
            setProvBusy(false);
        }
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        router.get(
            filterHref(filters, { q: searchDraft.trim() || null }, 1),
            {},
            { preserveState: true },
        );
    }

    function resetSearch() {
        setSearchDraft('');
        router.get(
            filterHref(filters, { q: null }, 1),
            {},
            { preserveState: true },
        );
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

                <form
                    className="flex flex-wrap items-end gap-3"
                    data-test="crm-accounts-search-form"
                    onSubmit={submitSearch}
                >
                    <FormField label="Suche" htmlFor="crm-accounts-q">
                        <Input
                            id="crm-accounts-q"
                            data-test="crm-accounts-search"
                            value={searchDraft}
                            onChange={(e) => setSearchDraft(e.target.value)}
                            placeholder="Firmierung, Meridian, Salesforce-ID, Domain…"
                            className="min-w-[16rem] sm:min-w-[22rem]"
                        />
                    </FormField>
                    <Button
                        type="submit"
                        data-test="crm-accounts-search-submit"
                    >
                        Suchen
                    </Button>
                    {filters.q ? (
                        <Button
                            type="button"
                            variant="outline"
                            data-test="crm-accounts-search-reset"
                            onClick={resetSearch}
                        >
                            Suche zurücksetzen
                        </Button>
                    ) : null}
                </form>

                <div className="flex flex-wrap gap-4 text-sm">
                    <label className="flex items-center gap-2">
                        Typ
                        <select
                            className={formSelectClass}
                            data-test="crm-accounts-type-filter"
                            value={filters.type ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterHref(
                                        filters,
                                        {
                                            type:
                                                event.target.value === ''
                                                    ? null
                                                    : event.target.value,
                                        },
                                        1,
                                    ),
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
                            data-test="crm-accounts-provisional-filter"
                            checked={filters.provisional}
                            onChange={(event) =>
                                router.get(
                                    filterHref(
                                        filters,
                                        {
                                            provisional: event.target.checked,
                                        },
                                        1,
                                    ),
                                )
                            }
                        />
                        Nur vorläufig
                    </label>
                </div>

                <p
                    className="text-muted-foreground text-sm"
                    data-test="crm-accounts-result-summary"
                >
                    {pagination.total === 0
                        ? filters.q
                            ? 'Keine Treffer für diese Suche.'
                            : 'Keine Accounts gefunden.'
                        : `${pagination.from}–${pagination.to} von ${pagination.total}${
                              filters.q ? ` · Suche „${filters.q}“` : ''
                          }`}
                </p>

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
                    <EmptyState
                        title={
                            filters.q
                                ? 'Keine Treffer für diese Suche.'
                                : 'Keine Accounts gefunden.'
                        }
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2">Firmierung</th>
                                    <th className="px-3 py-2">Kennzeichnung</th>
                                    <th className="px-3 py-2">Meridian</th>
                                    <th className="px-3 py-2">Salesforce</th>
                                    <th className="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((account) => (
                                    <tr
                                        key={account.id}
                                        className="border-t"
                                        data-test={`crm-account-row-${account.id}`}
                                    >
                                        <td className="px-3 py-2">
                                            <div className="font-medium">
                                                {account.label.title}
                                            </div>
                                            {account.label.meta.length > 0 ? (
                                                <div className="text-muted-foreground text-xs">
                                                    {account.label.meta.join(
                                                        ' · ',
                                                    )}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.type_label}
                                            {account.is_provisional
                                                ? ' · vorläufig'
                                                : ''}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.meridian_pending
                                                ? 'Meridian-Nummer folgt'
                                                : (account.meridian_number ??
                                                  '–')}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {account.salesforce_account_id_raw ??
                                                '–'}
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

                {pagination.last_page > 1 ? (
                    <div
                        className="flex flex-wrap items-center gap-3"
                        data-test="crm-accounts-pagination"
                    >
                        {pagination.current_page > 1 ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={filterHref(
                                        filters,
                                        {},
                                        pagination.current_page - 1,
                                    )}
                                    data-test="crm-accounts-page-prev"
                                >
                                    Zurück
                                </Link>
                            </Button>
                        ) : null}
                        <span className="text-muted-foreground text-sm">
                            Seite {pagination.current_page} von{' '}
                            {pagination.last_page}
                        </span>
                        {pagination.current_page < pagination.last_page ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={filterHref(
                                        filters,
                                        {},
                                        pagination.current_page + 1,
                                    )}
                                    data-test="crm-accounts-page-next"
                                >
                                    Weiter
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </>
    );
}
