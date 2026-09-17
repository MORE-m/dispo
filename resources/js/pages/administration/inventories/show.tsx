import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Inventory = {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    logo_path: string | null;
    has_logo: boolean;
    lock_version: number;
    membership_note: string | null;
    impact: {
        calculation_positions_count: number;
        dispo_order_positions_count: number;
        price_lists_count: number;
        inventory_medium_rules_count: number;
    };
};

type Routes = {
    index: string;
    update: string;
    deactivatePreview: string;
    deactivate: string;
    reactivatePreview: string;
    reactivate: string;
    updateMediumRule: string;
};

type MediumRuleRow = {
    id: number;
    advertising_medium_id: number;
    advertising_medium_name: string;
    advertising_medium_code: string | null;
    is_active: boolean;
    surcharge_percent: string;
    default_length_seconds: number | null;
    component_calculation_strategy: string;
    component_calculation_strategy_label: string;
};

type StrategyOption = { value: string; label: string };

type ImpactPreview = {
    fingerprint: string;
    lock_version: number;
    action?: string;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    historical_snapshots_note: string;
    new_processes_note: string;
    calculation_positions_count: number;
    dispo_order_positions_count: number;
    price_lists_count: number;
    inventory_medium_rules_count: number;
};

async function csrfHeaders(): Promise<Record<string, string>> {
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

export default function InventoryShow({
    inventory,
    routes,
    mediumRules = [],
    componentStrategyOptions = [],
}: {
    inventory: Inventory;
    routes: Routes;
    mediumRules?: MediumRuleRow[];
    componentStrategyOptions?: StrategyOption[];
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(inventory.name);
    const [sort, setSort] = useState(inventory.sort);
    const [logoPath, setLogoPath] = useState(inventory.logo_path ?? '');
    const [lockVersion, setLockVersion] = useState(inventory.lock_version);
    const [isActive, setIsActive] = useState(inventory.is_active);
    const [statusLabel, setStatusLabel] = useState(inventory.status_label);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [rules, setRules] = useState(mediumRules);
    const [success, setSuccess] = useState<string | null>(
        flash.success ?? null,
    );
    const [preview, setPreview] = useState<ImpactPreview | null>(null);

    async function saveMetadata(event: React.FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setSuccess(null);
        setPreview(null);
        try {
            const response = await fetch(routes.update, {
                method: 'PUT',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    name,
                    sort,
                    logo_path: logoPath,
                    lock_version: lockVersion,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message || 'Parallel geändert. Bitte Seite neu laden.',
                );
                return;
            }
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.name?.[0] ||
                        data.errors?.logo_path?.[0] ||
                        'Speichern fehlgeschlagen.',
                );
                return;
            }
            setLockVersion(data.lock_version ?? lockVersion);
            setSuccess(data.message || 'Gespeichert.');
        } catch {
            setError('Speichern fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function loadPreview(url: string) {
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({}),
            });
            const data = await response.json();
            if (!response.ok) {
                setError(data.message || 'Vorschau fehlgeschlagen.');
                return;
            }
            setPreview(data);
            setLockVersion(data.lock_version ?? lockVersion);
        } catch {
            setError('Vorschau fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmDeactivate() {
        if (!preview) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(routes.deactivate, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    lock_version: lockVersion,
                    fingerprint: preview.fingerprint,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message || 'Parallel geändert. Bitte Seite neu laden.',
                );
                return;
            }
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.inventory?.[0] ||
                        'Deaktivierung fehlgeschlagen.',
                );
                return;
            }
            setPreview(null);
            setIsActive(false);
            setStatusLabel('Inaktiv');
            setLockVersion(data.lock_version ?? lockVersion + 1);
            setSuccess(data.message || 'Inventar deaktiviert.');
            router.reload({ only: ['inventory'] });
        } catch {
            setError('Deaktivierung fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmReactivate() {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(routes.reactivate, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    lock_version: lockVersion,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message || 'Parallel geändert. Bitte Seite neu laden.',
                );
                return;
            }
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.inventory?.[0] ||
                        'Reaktivierung fehlgeschlagen.',
                );
                return;
            }
            setPreview(null);
            setIsActive(true);
            setStatusLabel('Aktiv');
            setLockVersion(data.lock_version ?? lockVersion + 1);
            setSuccess(data.message || 'Inventar reaktiviert.');
            router.reload({ only: ['inventory'] });
        } catch {
            setError('Reaktivierung fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={inventory.name} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={inventory.name}
                    description={`${inventory.code} · ${inventory.type_label}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={routes.index}>Zur Liste</Link>
                        </Button>
                    }
                />

                {error ? (
                    <ErrorState
                        message={error}
                        data-test="inventory-show-error"
                    />
                ) : null}
                {success ? (
                    <SuccessState
                        message={success}
                        data-test="inventory-show-success"
                    />
                ) : null}

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-xl border p-4 text-sm">
                        <p>
                            Status:{' '}
                            <span data-test="inventory-status">
                                {statusLabel}
                            </span>
                        </p>
                        <p className="text-muted-foreground mt-1">
                            Sperrversion {lockVersion}
                        </p>
                        <p className="mt-2">
                            Logo:{' '}
                            {inventory.has_logo ? 'Vorhanden' : 'Kein Logo'}
                        </p>
                    </div>
                    <div
                        className="rounded-xl border p-4 text-sm"
                        data-test="inventory-impact-summary"
                    >
                        <h2 className="mb-2 font-semibold">Bezüge</h2>
                        <p>
                            Kalkulationspositionen:{' '}
                            {inventory.impact.calculation_positions_count}
                        </p>
                        <p>
                            Dispoauftragspositionen:{' '}
                            {inventory.impact.dispo_order_positions_count}
                        </p>
                        <p>Preislisten: {inventory.impact.price_lists_count}</p>
                        <p>
                            Inventar-Werbemittel-Regeln:{' '}
                            {inventory.impact.inventory_medium_rules_count}
                        </p>
                    </div>
                </div>

                {inventory.membership_note ? (
                    <div
                        className="rounded-xl border p-4 text-sm"
                        data-test="inventory-membership-note"
                    >
                        {inventory.membership_note}
                    </div>
                ) : null}

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="inventory-edit-form"
                    onSubmit={saveMetadata}
                >
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            data-test="inventory-name-input"
                        />
                    </FormField>
                    <FormField label="Kurzcode" htmlFor="code">
                        <Input
                            id="code"
                            value={inventory.code}
                            disabled
                            className="font-mono"
                            data-test="inventory-code-input"
                        />
                    </FormField>
                    <FormField label="Typ" htmlFor="type">
                        <Input
                            id="type"
                            value={inventory.type_label}
                            disabled
                            data-test="inventory-type-input"
                        />
                    </FormField>
                    <FormField label="Sortierung" htmlFor="sort">
                        <Input
                            id="sort"
                            type="number"
                            min={0}
                            value={sort}
                            onChange={(e) =>
                                setSort(Number(e.target.value || 0))
                            }
                            data-test="inventory-sort-input"
                        />
                    </FormField>
                    <FormField label="Logo-Pfad" htmlFor="logo_path">
                        <Input
                            id="logo_path"
                            value={logoPath}
                            onChange={(e) => setLogoPath(e.target.value)}
                            className="font-mono"
                            data-test="inventory-logo-input"
                        />
                    </FormField>
                    <Button
                        type="submit"
                        disabled={busy}
                        data-test="inventory-save-button"
                    >
                        Speichern
                    </Button>
                </form>

                <section
                    className="space-y-3 rounded-xl border p-4"
                    data-test="inventory-medium-rules-section"
                    aria-labelledby="inventory-medium-rules-heading"
                >
                    <div>
                        <h2
                            id="inventory-medium-rules-heading"
                            className="text-base font-semibold"
                        >
                            Inventar-/Werbemedium-Regeln
                        </h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Komponenten-Berechnungsstrategie je Kombination
                            (Hauptspot/Allonge). Bestehende Kalkulationen
                            bleiben historisch eingefroren.
                        </p>
                    </div>
                    {rules.length === 0 ? (
                        <p
                            className="text-muted-foreground text-sm"
                            data-test="inventory-medium-rules-empty"
                        >
                            Keine Regeln für dieses Inventar.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {rules.map((rule) => (
                                <li
                                    key={rule.id}
                                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto] sm:items-end"
                                    data-test={`inventory-medium-rule-${rule.id}`}
                                >
                                    <div>
                                        <p className="font-medium">
                                            {rule.advertising_medium_name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {rule.advertising_medium_code ??
                                                'ohne Code'}{' '}
                                            ·{' '}
                                            {rule.is_active
                                                ? 'aktiv'
                                                : 'inaktiv'}{' '}
                                            · Aufschlag {rule.surcharge_percent}
                                            %
                                        </p>
                                    </div>
                                    <FormField
                                        label="Komponentenstrategie"
                                        htmlFor={`rule-strategy-${rule.id}`}
                                    >
                                        <select
                                            id={`rule-strategy-${rule.id}`}
                                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                            value={
                                                rule.component_calculation_strategy
                                            }
                                            data-test={`inventory-medium-rule-strategy-${rule.id}`}
                                            disabled={busy}
                                            onChange={async (event) => {
                                                const next = event.target.value;
                                                setBusy(true);
                                                setError(null);
                                                try {
                                                    const url =
                                                        routes.updateMediumRule.replace(
                                                            'RULE_ID',
                                                            String(rule.id),
                                                        );
                                                    const response =
                                                        await fetch(url, {
                                                            method: 'PUT',
                                                            headers:
                                                                await csrfHeaders(),
                                                            body: JSON.stringify(
                                                                {
                                                                    component_calculation_strategy:
                                                                        next,
                                                                    lock_version:
                                                                        lockVersion,
                                                                },
                                                            ),
                                                        });
                                                    const payload =
                                                        await response.json();
                                                    if (
                                                        response.status === 409
                                                    ) {
                                                        setError(
                                                            payload.message ||
                                                                'Parallel geändert. Bitte Seite neu laden.',
                                                        );
                                                        return;
                                                    }
                                                    if (!response.ok) {
                                                        throw new Error(
                                                            payload.message ??
                                                                payload.errors
                                                                    ?.component_calculation_strategy?.[0] ??
                                                                'Speichern fehlgeschlagen.',
                                                        );
                                                    }
                                                    if (
                                                        payload.lock_version !=
                                                        null
                                                    ) {
                                                        setLockVersion(
                                                            payload.lock_version,
                                                        );
                                                    }
                                                    setRules((current) =>
                                                        current.map((row) =>
                                                            row.id === rule.id
                                                                ? {
                                                                      ...row,
                                                                      component_calculation_strategy:
                                                                          payload
                                                                              .rule
                                                                              .component_calculation_strategy,
                                                                      component_calculation_strategy_label:
                                                                          payload
                                                                              .rule
                                                                              .component_calculation_strategy_label,
                                                                  }
                                                                : row,
                                                        ),
                                                    );
                                                } catch (err) {
                                                    setError(
                                                        err instanceof Error
                                                            ? err.message
                                                            : 'Speichern fehlgeschlagen.',
                                                    );
                                                } finally {
                                                    setBusy(false);
                                                }
                                            }}
                                        >
                                            {(componentStrategyOptions.length >
                                            0
                                                ? componentStrategyOptions
                                                : [
                                                      {
                                                          value: 'shared_total_length',
                                                          label: 'Gemeinsame Gesamtlänge',
                                                      },
                                                      {
                                                          value: 'individual',
                                                          label: 'Komponenten einzeln berechnen',
                                                      },
                                                  ]
                                            ).map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className="flex flex-wrap gap-2">
                    {isActive ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                loadPreview(routes.deactivatePreview)
                            }
                            data-test="inventory-deactivate-preview-button"
                        >
                            Deaktivieren…
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={confirmReactivate}
                            data-test="inventory-reactivate-button"
                        >
                            Reaktivieren
                        </Button>
                    )}
                </div>

                {preview ? (
                    <div
                        className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                        data-test="inventory-impact-preview"
                    >
                        <h2 className="font-semibold">Auswirkungsvorschau</h2>
                        <p>{preview.historical_snapshots_note}</p>
                        <p>{preview.new_processes_note}</p>
                        <p>
                            Calc {preview.calculation_positions_count} · Dispo{' '}
                            {preview.dispo_order_positions_count} · Preise{' '}
                            {preview.price_lists_count} · Regeln{' '}
                            {preview.inventory_medium_rules_count}
                        </p>
                        {preview.blocking_reasons.map((reason) => (
                            <p
                                key={reason.code}
                                className="font-medium text-red-800 dark:text-red-200"
                                data-test="inventory-preview-blocker"
                            >
                                {reason.message}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setPreview(null)}
                            >
                                Abbrechen
                            </Button>
                            {preview.action === 'inventory_deactivate' ? (
                                <Button
                                    disabled={busy || !preview.can_proceed}
                                    onClick={confirmDeactivate}
                                    data-test="inventory-deactivate-confirm"
                                >
                                    Deaktivierung bestätigen
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ) : null}
            </div>
        </>
    );
}
