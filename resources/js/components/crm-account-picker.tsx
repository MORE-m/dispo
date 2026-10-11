import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/form-field';
import { cn } from '@/lib/utils';

export type CrmPickerAccountHit = {
    id: number;
    name: string | null;
    meridian_number: string | null;
    meridian_pending: boolean;
    is_provisional: boolean;
    matching_domain: string | null;
    salesforce_account_id_raw: string | null;
    salesforce_record_type: string | null;
    type_label: string;
};

export type CrmAccountPickerValue =
    | { mode: 'empty' }
    | {
          mode: 'account';
          accountId: number;
          name: string;
          meridianNumber: string | null;
          meridianPending: boolean;
          isProvisional: boolean;
          matchingDomain: string | null;
          salesforceAccountIdRaw: string | null;
          salesforceRecordType: string | null;
          typeLabel: string;
      }
    | {
          mode: 'freitext';
          name: string;
          billingEmail: string;
          matchingDomain: string;
          /** Explizite Überführung historischer Freitext-Bestände in einen vorläufigen Account. */
          createProvisional: boolean;
      }
    | {
          mode: 'legacy_freitext';
          name: string;
      };

type Props = {
    label: string;
    type: 'customer' | 'agency';
    value: CrmAccountPickerValue;
    disabled?: boolean;
    optional?: boolean;
    onChange: (next: CrmAccountPickerValue) => void;
    testIdPrefix: string;
};

function hitToAccountValue(hit: CrmPickerAccountHit): CrmAccountPickerValue {
    return {
        mode: 'account',
        accountId: hit.id,
        name: hit.name ?? `Account #${hit.id}`,
        meridianNumber: hit.meridian_number,
        meridianPending: hit.meridian_pending,
        isProvisional: hit.is_provisional,
        matchingDomain: hit.matching_domain,
        salesforceAccountIdRaw: hit.salesforce_account_id_raw,
        salesforceRecordType: hit.salesforce_record_type,
        typeLabel: hit.type_label,
    };
}

function distinguishLabel(hit: CrmPickerAccountHit): string {
    const parts: string[] = [];
    if (hit.salesforce_record_type) {
        parts.push(hit.salesforce_record_type.replace(/^Account\s+/i, ''));
    } else if (hit.type_label) {
        parts.push(hit.type_label);
    }
    if (hit.is_provisional) {
        parts.push('vorläufig');
    }
    if (hit.matching_domain) {
        parts.push(hit.matching_domain);
    }
    if (hit.meridian_number) {
        parts.push(`Meridian ${hit.meridian_number}`);
    } else if (hit.meridian_pending) {
        parts.push('Meridian folgt');
    }
    if (hit.salesforce_account_id_raw) {
        const sf = hit.salesforce_account_id_raw;
        parts.push(`SF …${sf.slice(-6)}`);
    }
    return parts.join(' · ');
}

export function CrmAccountPicker({
    label,
    type,
    value,
    disabled = false,
    optional = false,
    onChange,
    testIdPrefix,
}: Props) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<CrmPickerAccountHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const requestSeq = useRef(0);

    useEffect(() => {
        if (value.mode === 'account' || value.mode === 'legacy_freitext') {
            setQuery('');
            setHits([]);
            setError(null);
        }
    }, [value.mode, value.mode === 'account' ? value.accountId : null]);

    async function runSearch(raw: string) {
        const q = raw.trim();
        if (q === '') {
            setHits([]);
            setError(null);
            return;
        }
        const seq = ++requestSeq.current;
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(
                `/crm/accounts/suche?type=${encodeURIComponent(type)}&q=${encodeURIComponent(q)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );
            if (!response.ok) {
                throw new Error('Suche fehlgeschlagen');
            }
            const data = (await response.json()) as {
                accounts?: CrmPickerAccountHit[];
            };
            if (seq !== requestSeq.current) {
                return;
            }
            setHits(data.accounts ?? []);
        } catch {
            if (seq !== requestSeq.current) {
                return;
            }
            setHits([]);
            setError('Suche derzeit nicht möglich. Bitte erneut versuchen.');
        } finally {
            if (seq === requestSeq.current) {
                setLoading(false);
            }
        }
    }

    function clearSelection() {
        requestSeq.current += 1;
        setHits([]);
        setQuery('');
        setError(null);
        onChange({ mode: 'empty' });
    }

    function useFreitext(name: string) {
        requestSeq.current += 1;
        setHits([]);
        setQuery('');
        setError(null);
        onChange({
            mode: 'freitext',
            name,
            billingEmail: '',
            matchingDomain: '',
            createProvisional: false,
        });
    }

    function convertLegacyToProvisional() {
        if (value.mode !== 'legacy_freitext') {
            return;
        }
        onChange({
            mode: 'freitext',
            name: value.name,
            billingEmail: '',
            matchingDomain: '',
            createProvisional: true,
        });
    }

    const selectedName =
        value.mode === 'account'
            ? value.name
            : value.mode === 'freitext' || value.mode === 'legacy_freitext'
              ? value.name
              : null;

    return (
        <div className="space-y-3" data-test={testIdPrefix}>
            <FormField label={label} htmlFor={`${testIdPrefix}-search`}>
                {value.mode === 'account' ||
                value.mode === 'freitext' ||
                value.mode === 'legacy_freitext' ? (
                    <div
                        className="border-border bg-muted/30 flex flex-col gap-2 rounded-lg border px-3 py-3"
                        data-test={`${testIdPrefix}-selection`}
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0 space-y-1">
                                <p
                                    className="text-sm font-medium"
                                    data-test={`${testIdPrefix}-selected-name`}
                                >
                                    {selectedName}
                                </p>
                                {value.mode === 'account' ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test={`${testIdPrefix}-selected-meta`}
                                    >
                                        {[
                                            value.isProvisional
                                                ? 'Vorläufiger Account'
                                                : value.typeLabel,
                                            value.matchingDomain,
                                            value.salesforceRecordType?.replace(
                                                /^Account\s+/i,
                                                '',
                                            ),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                ) : null}
                                {value.mode === 'freitext' ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test={`${testIdPrefix}-freitext-hint`}
                                    >
                                        Neue Firmierung – wird beim Speichern
                                        als vorläufiger Account verknüpft.
                                    </p>
                                ) : null}
                                {value.mode === 'legacy_freitext' ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test={`${testIdPrefix}-legacy-hint`}
                                    >
                                        Historische Freitext-Firmierung ohne
                                        CRM-Verknüpfung.
                                    </p>
                                ) : null}
                                {value.mode === 'account' ? (
                                    value.meridianNumber ? (
                                        <p
                                            className="text-muted-foreground text-xs"
                                            data-test={
                                                type === 'customer'
                                                    ? 'customer-meridian-number'
                                                    : 'agency-meridian-number'
                                            }
                                        >
                                            Meridian: {value.meridianNumber}
                                        </p>
                                    ) : (
                                        <p
                                            className="text-muted-foreground text-xs"
                                            data-test={
                                                type === 'customer'
                                                    ? 'customer-meridian-pending'
                                                    : 'agency-meridian-pending'
                                            }
                                        >
                                            Meridian-Nummer folgt
                                        </p>
                                    )
                                ) : null}
                                {value.mode === 'freitext' ||
                                value.mode === 'legacy_freitext' ? (
                                    <p
                                        className="text-muted-foreground text-xs"
                                        data-test={
                                            type === 'customer'
                                                ? 'customer-meridian-pending'
                                                : 'agency-meridian-pending'
                                        }
                                    >
                                        Meridian-Nummer folgt
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {value.mode === 'legacy_freitext' ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={disabled}
                                        data-test={`${testIdPrefix}-convert-provisional`}
                                        onClick={convertLegacyToProvisional}
                                    >
                                        Als vorläufigen Account übernehmen
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={disabled}
                                    data-test={`${testIdPrefix}-clear`}
                                    onClick={clearSelection}
                                >
                                    {optional ? 'Entfernen' : 'Ändern'}
                                </Button>
                            </div>
                        </div>
                        {value.mode === 'freitext' ? (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <FormField
                                    label="Matching-Domain (optional)"
                                    htmlFor={
                                        type === 'customer'
                                            ? 'customer-matching-domain'
                                            : 'agency-matching-domain'
                                    }
                                >
                                    <Input
                                        id={
                                            type === 'customer'
                                                ? 'customer-matching-domain'
                                                : 'agency-matching-domain'
                                        }
                                        data-test={
                                            type === 'customer'
                                                ? 'customer-matching-domain'
                                                : 'agency-matching-domain'
                                        }
                                        value={value.matchingDomain}
                                        disabled={disabled}
                                        placeholder="z. B. firma.test"
                                        onChange={(event) =>
                                            onChange({
                                                ...value,
                                                matchingDomain:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    label="Rechnungs-E-Mail (optional)"
                                    htmlFor={`${testIdPrefix}-email`}
                                >
                                    <Input
                                        id={`${testIdPrefix}-email`}
                                        data-test={
                                            type === 'customer'
                                                ? 'customer-billing-email'
                                                : 'agency-billing-email'
                                        }
                                        value={value.billingEmail}
                                        disabled={disabled}
                                        placeholder="billing@firma.test"
                                        onChange={(event) =>
                                            onChange({
                                                ...value,
                                                billingEmail:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-2">
                        <div className="flex gap-2">
                            <Input
                                id={`${testIdPrefix}-search`}
                                data-test={`${testIdPrefix}-search`}
                                value={query}
                                disabled={disabled}
                                placeholder="Name, Meridian, SF-ID…"
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        void runSearch(query);
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={disabled || query.trim() === ''}
                                data-test={`${testIdPrefix}-search-btn`}
                                onClick={() => void runSearch(query)}
                            >
                                Suchen
                            </Button>
                        </div>
                        {loading ? (
                            <p
                                className="text-muted-foreground text-xs"
                                data-test={`${testIdPrefix}-loading`}
                            >
                                Suche läuft …
                            </p>
                        ) : null}
                        {error ? (
                            <p
                                className="text-destructive text-xs"
                                data-test={`${testIdPrefix}-error`}
                            >
                                {error}
                            </p>
                        ) : null}
                        {!loading &&
                        !error &&
                        query.trim() !== '' &&
                        hits.length === 0 ? (
                            <p
                                className="text-muted-foreground text-xs"
                                data-test={`${testIdPrefix}-empty`}
                            >
                                Keine Treffer. Sie können die eingegebene
                                Firmierung übernehmen.
                            </p>
                        ) : null}
                        {hits.length > 0 ? (
                            <ul
                                id={listId}
                                className="border-border max-h-48 space-y-1 overflow-y-auto rounded-lg border p-2"
                                data-test={`${testIdPrefix}-hits`}
                            >
                                {hits.map((hit) => (
                                    <li key={hit.id}>
                                        <button
                                            type="button"
                                            disabled={disabled}
                                            className={cn(
                                                'hover:bg-muted w-full rounded-md px-2 py-1.5 text-left text-sm',
                                            )}
                                            data-test={`${testIdPrefix}-hit-${hit.id}`}
                                            onClick={() => {
                                                requestSeq.current += 1;
                                                setHits([]);
                                                setQuery('');
                                                onChange(
                                                    hitToAccountValue(hit),
                                                );
                                            }}
                                        >
                                            <span className="font-medium">
                                                {hit.name ?? `#${hit.id}`}
                                            </span>
                                            <span className="text-muted-foreground mt-0.5 block text-xs">
                                                {distinguishLabel(hit)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {query.trim() !== '' ? (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                disabled={disabled}
                                data-test={`${testIdPrefix}-use-freitext`}
                                onClick={() => useFreitext(query.trim())}
                            >
                                Firmierung „{query.trim()}“ übernehmen
                            </Button>
                        ) : null}
                    </div>
                )}
            </FormField>
        </div>
    );
}

export function crmPickerFromCalculation(args: {
    accountId: number | null | undefined;
    name: string | null | undefined;
    meridianNumber: string | null | undefined;
}): CrmAccountPickerValue {
    if (args.accountId != null) {
        return {
            mode: 'account',
            accountId: args.accountId,
            name: args.name ?? `Account #${args.accountId}`,
            meridianNumber: args.meridianNumber ?? null,
            meridianPending:
                args.meridianNumber == null || args.meridianNumber === '',
            isProvisional: false,
            matchingDomain: null,
            salesforceAccountIdRaw: null,
            salesforceRecordType: null,
            typeLabel: '',
        };
    }
    if (args.name != null && args.name !== '') {
        return { mode: 'legacy_freitext', name: args.name };
    }
    return { mode: 'empty' };
}

export function crmPickerToPayload(
    side: 'customer' | 'agency',
    value: CrmAccountPickerValue,
): Record<string, unknown> {
    const nameKey = side === 'customer' ? 'customer_name' : 'agency_name';
    const accountKey =
        side === 'customer' ? 'customer_account_id' : 'agency_account_id';
    const emailKey =
        side === 'customer' ? 'customer_billing_email' : 'agency_billing_email';
    const domainKey =
        side === 'customer'
            ? 'customer_matching_domain'
            : 'agency_matching_domain';
    const createKey =
        side === 'customer'
            ? 'customer_create_provisional'
            : 'agency_create_provisional';

    if (value.mode === 'account') {
        return {
            [nameKey]: value.name,
            [accountKey]: value.accountId,
        };
    }
    if (value.mode === 'freitext') {
        // Explizite Freitext-Übernahme (inkl. Legacy-Konvertierung) → vorläufiger Account.
        // legacy_freitext sendet denselben null-Account-Schlüssel ohne create_*-Flag.
        return {
            [nameKey]: value.name,
            [accountKey]: null,
            [createKey]: true,
            ...(value.billingEmail.trim() !== ''
                ? { [emailKey]: value.billingEmail.trim() }
                : {}),
            ...(value.matchingDomain.trim() !== ''
                ? { [domainKey]: value.matchingDomain.trim() }
                : {}),
        };
    }
    if (value.mode === 'legacy_freitext') {
        return {
            [nameKey]: value.name,
            [accountKey]: null,
        };
    }
    return {
        [nameKey]: null,
        [accountKey]: null,
    };
}
