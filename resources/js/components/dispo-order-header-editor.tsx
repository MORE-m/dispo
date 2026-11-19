import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    CrmAccountPicker,
    crmPickerFromCalculation,
    crmPickerToPayload,
    type CrmAccountPickerValue,
} from '@/components/crm-account-picker';
import { ErrorState } from '@/components/feedback/states';
import {
    FormField,
    formSelectClass,
    formTextareaClass,
} from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { flushDispoOrderInertiaCache } from '@/lib/dispo-order-inertia-cache';
import {
    firstValidationMessage,
    mapValidationErrors,
} from '@/lib/validation-errors';

export type DispoOrderHeaderEditorOrder = {
    id: number;
    lock_version: number;
    status: string;
    customer_name: string | null;
    agency_name: string | null;
    agency_account_id?: number | null;
    agency_meridian_number?: string | null;
    invoice_recipient?: string | null;
    briefing: string | null;
};

function agencyIsSet(value: CrmAccountPickerValue): boolean {
    return (
        value.mode === 'account' ||
        value.mode === 'freitext' ||
        value.mode === 'legacy_freitext'
    );
}

function invoiceLabel(value: string | null | undefined): string {
    if (value === 'agency') {
        return 'Agentur';
    }
    if (value === 'customer') {
        return 'Kunde';
    }
    return 'Noch nicht festgelegt';
}

export function DispoOrderHeaderEditor({
    order,
    canEdit,
}: {
    order: DispoOrderHeaderEditorOrder;
    canEdit: boolean;
}) {
    const [agencyPicker, setAgencyPicker] = useState<CrmAccountPickerValue>(
        () =>
            crmPickerFromCalculation({
                accountId: order.agency_account_id,
                name: order.agency_name,
                meridianNumber: order.agency_meridian_number,
            }),
    );
    const [invoiceRecipient, setInvoiceRecipient] = useState<
        '' | 'customer' | 'agency'
    >(
        order.invoice_recipient === 'agency' ||
            order.invoice_recipient === 'customer'
            ? order.invoice_recipient
            : '',
    );
    const [briefing, setBriefing] = useState(order.briefing ?? '');
    const [saving, setSaving] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        setAgencyPicker(
            crmPickerFromCalculation({
                accountId: order.agency_account_id,
                name: order.agency_name,
                meridianNumber: order.agency_meridian_number,
            }),
        );
        setInvoiceRecipient(
            order.invoice_recipient === 'agency' ||
                order.invoice_recipient === 'customer'
                ? order.invoice_recipient
                : '',
        );
        setBriefing(order.briefing ?? '');
        setFieldErrors({});
        setFormError(null);
    }, [
        order.id,
        order.lock_version,
        order.agency_account_id,
        order.agency_name,
        order.agency_meridian_number,
        order.invoice_recipient,
        order.briefing,
    ]);

    const agencySet = agencyIsSet(agencyPicker);
    const initialInvoice: '' | 'customer' | 'agency' =
        order.invoice_recipient === 'agency' ||
        order.invoice_recipient === 'customer'
            ? order.invoice_recipient
            : '';
    const dirty =
        JSON.stringify(crmPickerToPayload('agency', agencyPicker)) !==
            JSON.stringify(
                crmPickerToPayload(
                    'agency',
                    crmPickerFromCalculation({
                        accountId: order.agency_account_id,
                        name: order.agency_name,
                        meridianNumber: order.agency_meridian_number,
                    }),
                ),
            ) ||
        invoiceRecipient !== initialInvoice ||
        briefing !== (order.briefing ?? '');

    function onAgencyChange(next: CrmAccountPickerValue) {
        setAgencyPicker(next);
        if (next.mode === 'empty' && invoiceRecipient === 'agency') {
            setInvoiceRecipient('');
        }
    }

    function save() {
        if (saving || !canEdit) {
            return;
        }

        setSaving(true);
        setFieldErrors({});
        setFormError(null);

        router.patch(
            `/dispoauftraege/${order.id}/kopfdaten`,
            {
                lock_version: order.lock_version,
                ...crmPickerToPayload('agency', agencyPicker),
                invoice_recipient:
                    invoiceRecipient === '' ? null : invoiceRecipient,
                briefing: briefing.trim() === '' ? null : briefing,
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    flushDispoOrderInertiaCache(order.id);
                },
                onError: (errors) => {
                    const mapped = mapValidationErrors(
                        errors as Record<string, string | string[]>,
                    );
                    const next: Record<string, string> = {};
                    for (const [key, messages] of Object.entries(mapped)) {
                        if (messages[0]) {
                            next[key] = messages[0];
                        }
                    }
                    setFieldErrors(next);
                    setFormError(
                        firstValidationMessage(mapped) ??
                            'Speichern fehlgeschlagen.',
                    );
                },
                onHttpException: (response) => {
                    if (response.status === 409) {
                        setFormError(
                            'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                        );
                        return;
                    }
                    setFormError('Speichern fehlgeschlagen.');
                },
            },
        );
    }

    if (!canEdit) {
        return (
            <div
                className="grid gap-4 sm:grid-cols-2"
                data-test="dispo-order-header-readonly"
            >
                <ReadOnlyDetail label="Kunde" value={order.customer_name} />
                <ReadOnlyDetail label="Agentur" value={order.agency_name} />
                <ReadOnlyDetail
                    label="Rechnungsempfänger"
                    value={invoiceLabel(order.invoice_recipient)}
                    testId="dispo-order-invoice-recipient-readonly"
                />
                <div
                    className="sm:col-span-2"
                    data-test="dispo-order-briefing-readonly"
                >
                    <ReadOnlyDetail
                        label="Briefing-Text"
                        value={order.briefing}
                        multiline
                    />
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-4" data-test="dispo-order-header-editor">
            <ReadOnlyDetail label="Kunde" value={order.customer_name} />
            <CrmAccountPicker
                label="Agentur"
                type="agency"
                testIdPrefix="dispo-crm-agency"
                value={agencyPicker}
                optional
                onChange={onAgencyChange}
            />
            {fieldErrors.agency_name || fieldErrors.agency_account_id ? (
                <ErrorState
                    message={
                        fieldErrors.agency_name ??
                        fieldErrors.agency_account_id ??
                        'Agentur ungültig.'
                    }
                />
            ) : null}
            <FormField
                label="Rechnungsempfänger"
                htmlFor="dispo-invoice-recipient"
                error={fieldErrors.invoice_recipient}
            >
                <select
                    id="dispo-invoice-recipient"
                    data-test="dispo-invoice-recipient"
                    className={formSelectClass}
                    value={invoiceRecipient}
                    onChange={(event) =>
                        setInvoiceRecipient(
                            event.target.value as '' | 'customer' | 'agency',
                        )
                    }
                >
                    <option value="">Noch nicht festgelegt</option>
                    <option value="customer">
                        Kunde
                        {order.customer_name ? ` (${order.customer_name})` : ''}
                    </option>
                    <option value="agency" disabled={!agencySet}>
                        Agentur
                        {agencySet &&
                        agencyPicker.mode !== 'empty' &&
                        'name' in agencyPicker
                            ? ` (${agencyPicker.name})`
                            : ''}
                    </option>
                </select>
            </FormField>
            <FormField
                label="Briefing-Text (optional)"
                htmlFor="dispo-briefing"
                error={fieldErrors.briefing}
            >
                <textarea
                    id="dispo-briefing"
                    data-test="dispo-briefing"
                    className={formTextareaClass}
                    rows={4}
                    value={briefing}
                    onChange={(event) => setBriefing(event.target.value)}
                    placeholder="Operativer Briefing-Text – nicht die Upload-Kategorie Briefing."
                />
            </FormField>
            {formError ? <ErrorState message={formError} /> : null}
            <div className="flex justify-end">
                <Button
                    type="button"
                    data-test="dispo-order-header-save"
                    disabled={saving || !dirty}
                    onClick={save}
                >
                    {saving ? 'Speichern…' : 'Kopfdaten speichern'}
                </Button>
            </div>
        </div>
    );
}

function ReadOnlyDetail({
    label,
    value,
    multiline = false,
    testId,
}: {
    label: string;
    value: string | null | undefined;
    multiline?: boolean;
    testId?: string;
}) {
    return (
        <div data-test={testId}>
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
            {value != null && value !== '' ? (
                <p
                    className={
                        multiline
                            ? 'mt-1 text-sm whitespace-pre-wrap'
                            : 'mt-1 text-sm font-medium'
                    }
                >
                    {value}
                </p>
            ) : (
                <p className="text-muted-foreground mt-1 text-sm">–</p>
            )}
        </div>
    );
}
