import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ProductionPriceListDetail = {
    id: number;
    name: string;
    production_type_label: string;
    year: number;
    version: string;
    status: string;
    status_label: string;
    editable: boolean;
    lock_version: number;
    unit_price: string;
    is_discountable: boolean;
    is_ae_eligible: boolean;
    inventory_name: string | null;
    inventory_code: string | null;
    inventory_type_label: string | null;
};

type Routes = {
    index: string;
    update: string;
    activate: string;
    archive: string;
    copy: string;
};

type JsonResult = {
    message?: string;
    errors?: Record<string, string[]>;
    productionPriceList?: ProductionPriceListDetail & { id: number };
};

function requestHeaders(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token ? decodeURIComponent(token.slice(11)) : '',
    };
}

function firstError(result: JsonResult, fallback: string): string {
    const errors = result.errors ?? {};
    for (const messages of Object.values(errors)) {
        if (messages.length > 0) {
            return messages[0];
        }
    }

    return result.message ?? fallback;
}

export default function ProductionPriceListShow({
    productionPriceList,
    routes,
}: {
    productionPriceList: ProductionPriceListDetail;
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [current, setCurrent] = useState(productionPriceList);
    const [name, setName] = useState(productionPriceList.name);
    const [unitPrice, setUnitPrice] = useState(productionPriceList.unit_price);
    const [discountable, setDiscountable] = useState(
        productionPriceList.is_discountable,
    );
    const [aeEligible, setAeEligible] = useState(
        productionPriceList.is_ae_eligible,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ?? null,
    );

    const dirty =
        name !== current.name ||
        unitPrice !== current.unit_price ||
        discountable !== current.is_discountable ||
        aeEligible !== current.is_ae_eligible;

    function apply(detail: ProductionPriceListDetail) {
        setCurrent(detail);
        setName(detail.name);
        setUnitPrice(detail.unit_price);
        setDiscountable(detail.is_discountable);
        setAeEligible(detail.is_ae_eligible);
    }

    async function send(
        method: 'PUT' | 'POST',
        url: string,
        body: Record<string, unknown>,
        onOk?: (result: JsonResult) => void,
    ) {
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(url, {
                method,
                headers: requestHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const result = (await response.json()) as JsonResult;
            if (!response.ok) {
                setError(
                    response.status === 409
                        ? `${result.message ?? 'Konflikt.'} Bitte Seite neu laden.`
                        : firstError(result, 'Die Aktion ist fehlgeschlagen.'),
                );

                return;
            }
            if (onOk) {
                onOk(result);
            } else if (result.productionPriceList) {
                apply(result.productionPriceList);
                setSuccess(result.message ?? null);
            }
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');
        } finally {
            setBusy(false);
        }
    }

    const editable = current.editable;

    return (
        <>
            <Head title="Produktionspreis" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`${current.inventory_name ?? 'Inventar'} · ${current.production_type_label} ${current.year}`}
                    description={`Version ${current.version} · Status: ${current.status_label}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={routes.index}>Zur Liste</Link>
                        </Button>
                    }
                />

                {success ? <SuccessState message={success} /> : null}
                {error ? <ErrorState message={error} /> : null}

                <div
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="production-price-show-form"
                >
                    <p className="text-muted-foreground text-sm">
                        Inventar, Produktionsart und Jahr sind unveränderlich.
                        {editable
                            ? ''
                            : ' Veröffentlichte und archivierte Stände sind schreibgeschützt – bitte kopieren.'}
                    </p>
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            disabled={!editable || busy}
                            onChange={(event) => setName(event.target.value)}
                            data-test="production-price-name-input"
                        />
                    </FormField>
                    <FormField
                        label="Produktionspreis (€, netto je Einheit)"
                        htmlFor="unit_price"
                    >
                        <Input
                            id="unit_price"
                            inputMode="decimal"
                            value={unitPrice}
                            disabled={!editable || busy}
                            onChange={(event) =>
                                setUnitPrice(event.target.value)
                            }
                            data-test="production-price-unit-price-input"
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={discountable}
                            disabled={!editable || busy}
                            onChange={(event) =>
                                setDiscountable(event.target.checked)
                            }
                            data-test="production-price-discountable-input"
                        />
                        Rabattfähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={aeEligible}
                            disabled={!editable || busy}
                            onChange={(event) =>
                                setAeEligible(event.target.checked)
                            }
                            data-test="production-price-ae-input"
                        />
                        AE-fähig
                    </label>

                    <div className="flex flex-wrap gap-2">
                        {editable ? (
                            <Button
                                type="button"
                                disabled={busy || !dirty}
                                data-test="production-price-save"
                                onClick={() =>
                                    send('PUT', routes.update, {
                                        name,
                                        unit_price: unitPrice,
                                        is_discountable: discountable,
                                        is_ae_eligible: aeEligible,
                                        lock_version: current.lock_version,
                                    })
                                }
                            >
                                Speichern
                            </Button>
                        ) : null}
                        {current.status === 'draft' ? (
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={busy || dirty}
                                data-test="production-price-activate"
                                onClick={() =>
                                    send('POST', routes.activate, {
                                        lock_version: current.lock_version,
                                    })
                                }
                            >
                                Aktivieren
                            </Button>
                        ) : null}
                        {current.status !== 'archived' ? (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || dirty}
                                data-test="production-price-archive"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Produktionspreis wirklich archivieren?',
                                        )
                                    ) {
                                        void send('POST', routes.archive, {
                                            lock_version: current.lock_version,
                                        });
                                    }
                                }}
                            >
                                Archivieren
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            data-test="production-price-copy"
                            onClick={() =>
                                send('POST', routes.copy, {}, (result) => {
                                    const copyId =
                                        result.productionPriceList?.id;
                                    if (copyId) {
                                        router.visit(
                                            `/administration/produktionspreise/${copyId}`,
                                        );
                                    }
                                })
                            }
                        >
                            Als Entwurf kopieren
                        </Button>
                    </div>
                    {dirty ? (
                        <p className="text-muted-foreground text-sm">
                            Ungespeicherte Änderungen: bitte zuerst speichern.
                        </p>
                    ) : null}
                </div>
            </div>
        </>
    );
}
