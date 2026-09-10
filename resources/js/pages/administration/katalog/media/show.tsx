import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Medium = {
    id: number;
    name: string;
    code: string;
    kind: string | null;
    category_id: number;
    category_name: string | null;
    category_key: string | null;
    category_is_active: boolean;
    default_length_seconds: number;
    is_discountable: boolean;
    is_ae_eligible: boolean;
    sort: number;
    is_active: boolean;
    status_label: string;
    is_bookable_for_new_positions: boolean;
    unbookable_reason: string | null;
    bookability_label: string;
    lock_version: number;
    has_position_reference: boolean;
    calculation_positions_count: number;
    dispo_order_positions_count: number;
    inventory_medium_rules_count: number;
    assignments_active_count: number;
    assignments_inactive_count: number;
};

type CategoryOption = {
    id: number;
    key: string;
    name: string;
};

type Routes = {
    index: string;
    update: string;
    deactivatePreview: string;
    deactivate: string;
    reactivate: string;
    categoryChangePreview: string;
    categoryChange: string;
};

type ImpactPreview = {
    fingerprint: string;
    lock_version: number;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    reactivation_assignment_warning: string | null;
    historical_snapshots_note: string;
    new_processes_note: string;
    schema_change_warning?: string;
    assignments?: Record<string, unknown>;
    calculation_positions_count: number;
    dispo_order_positions_count: number;
    inventory_medium_rules_count?: number;
    intended_change?: Record<string, unknown>;
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

export default function MediaShow({
    medium,
    formOptions,
    catalogNote,
    routes,
}: {
    medium: Medium;
    formOptions: { categories: CategoryOption[] };
    catalogNote: string;
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(medium.name);
    const [length, setLength] = useState(medium.default_length_seconds);
    const [sort, setSort] = useState(medium.sort);
    const [discountable, setDiscountable] = useState(medium.is_discountable);
    const [aeEligible, setAeEligible] = useState(medium.is_ae_eligible);
    const [lockVersion, setLockVersion] = useState(medium.lock_version);
    const [isActive, setIsActive] = useState(medium.is_active);
    const [statusLabel, setStatusLabel] = useState(medium.status_label);
    const [categoryId, setCategoryId] = useState(medium.category_id);
    const [categoryName, setCategoryName] = useState(medium.category_name);
    const [categoryKey, setCategoryKey] = useState(medium.category_key);
    const [targetCategoryId, setTargetCategoryId] = useState(
        medium.category_id.toString(),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ? String(flash.success) : null,
    );
    const [deactivatePreview, setDeactivatePreview] =
        useState<ImpactPreview | null>(null);
    const [categoryPreview, setCategoryPreview] =
        useState<ImpactPreview | null>(null);

    const compatibleTargets = formOptions.categories;

    async function saveMetadata(event: React.FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(routes.update, {
                method: 'PUT',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    name,
                    default_length_seconds: length,
                    is_discountable: discountable,
                    is_ae_eligible: aeEligible,
                    sort,
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
                setError(data.message || 'Speichern fehlgeschlagen.');
                return;
            }
            setLockVersion(data.lock_version ?? lockVersion + 1);
            setSuccess(data.message || 'Gespeichert.');
        } catch {
            setError('Speichern fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function loadDeactivatePreview() {
        setBusy(true);
        setError(null);
        setCategoryPreview(null);
        try {
            const response = await fetch(routes.deactivatePreview, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({}),
            });
            const data = await response.json();
            if (!response.ok) {
                setError(data.message || 'Vorschau fehlgeschlagen.');
                return;
            }
            setDeactivatePreview(data);
            setLockVersion(data.lock_version ?? lockVersion);
        } catch {
            setError('Vorschau fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmDeactivate() {
        if (!deactivatePreview) {
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
                    fingerprint: deactivatePreview.fingerprint,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message ||
                        'Vorschau oder Sperrversion veraltet. Bitte erneut laden.',
                );
                setDeactivatePreview(null);
                return;
            }
            if (!response.ok) {
                setError(data.message || 'Deaktivierung fehlgeschlagen.');
                return;
            }
            setIsActive(false);
            setStatusLabel('Inaktiv');
            setLockVersion(data.lock_version);
            setDeactivatePreview(null);
            setSuccess(data.message || 'Deaktiviert.');
        } catch {
            setError('Deaktivierung fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function reactivate() {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(routes.reactivate, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({ lock_version: lockVersion }),
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
                        data.errors?.medium?.[0] ||
                        'Reaktivierung fehlgeschlagen.',
                );
                return;
            }
            setIsActive(true);
            setStatusLabel('Aktiv');
            setLockVersion(data.lock_version);
            setSuccess(data.message || 'Reaktiviert.');
        } catch {
            setError('Reaktivierung fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function loadCategoryPreview() {
        setBusy(true);
        setError(null);
        setDeactivatePreview(null);
        try {
            const response = await fetch(routes.categoryChangePreview, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    category_id: Number(targetCategoryId),
                }),
            });
            const data = await response.json();
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.category_id?.[0] ||
                        'Vorschau fehlgeschlagen.',
                );
                return;
            }
            setCategoryPreview(data);
            setLockVersion(data.lock_version ?? lockVersion);
        } catch {
            setError('Vorschau fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmCategoryChange() {
        if (!categoryPreview) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(routes.categoryChange, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    category_id: Number(targetCategoryId),
                    lock_version: lockVersion,
                    fingerprint: categoryPreview.fingerprint,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message ||
                        'Vorschau oder Sperrversion veraltet. Bitte erneut laden.',
                );
                setCategoryPreview(null);
                return;
            }
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.category_id?.[0] ||
                        'Kategoriewechsel fehlgeschlagen.',
                );
                return;
            }
            const next = data.medium;
            if (next) {
                setCategoryId(next.category_id);
                setCategoryName(next.category_name);
                setCategoryKey(next.category_key);
                setTargetCategoryId(String(next.category_id));
                setLockVersion(next.lock_version);
            } else {
                setLockVersion(data.lock_version ?? lockVersion + 1);
            }
            setCategoryPreview(null);
            setSuccess(data.message || 'Oberkategorie gewechselt.');
        } catch {
            setError('Kategoriewechsel fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={medium.name} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={medium.name}
                    description={`Code ${medium.code} (unveränderlich) · Sperrversion ${lockVersion}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={routes.index}
                                    data-test="medium-back-link"
                                >
                                    Zur Liste
                                </Link>
                            </Button>
                            {isActive ? (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={loadDeactivatePreview}
                                    data-test="medium-deactivate-preview-button"
                                >
                                    Deaktivieren…
                                </Button>
                            ) : (
                                <Button
                                    disabled={busy}
                                    onClick={reactivate}
                                    data-test="medium-reactivate-button"
                                >
                                    Reaktivieren
                                </Button>
                            )}
                        </div>
                    }
                />

                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="medium-catalog-note"
                >
                    {catalogNote}
                </p>

                <div
                    className="grid max-w-xl gap-2 rounded-xl border p-4 text-sm"
                    data-test="medium-status-panel"
                >
                    <div>
                        <span className="font-medium">Katalog: </span>
                        <span data-test="medium-status-badge">
                            {statusLabel}
                        </span>
                    </div>
                    <div>
                        <span className="font-medium">
                            Neue Kalkulationen:{' '}
                        </span>
                        <span data-test="medium-bookability-badge">
                            {medium.bookability_label}
                        </span>
                    </div>
                    {medium.unbookable_reason ? (
                        <p
                            className="text-muted-foreground"
                            data-test="medium-unbookable-reason"
                        >
                            Grund: {medium.unbookable_reason}
                        </p>
                    ) : null}
                    <p className="text-muted-foreground">
                        Oberkategorie: {categoryName} ({categoryKey}) · Calc{' '}
                        {medium.calculation_positions_count} · Dispo{' '}
                        {medium.dispo_order_positions_count} · Rules{' '}
                        {medium.inventory_medium_rules_count} · Assignments{' '}
                        {medium.assignments_active_count} aktiv /{' '}
                        {medium.assignments_inactive_count} inaktiv
                    </p>
                </div>

                {error ? (
                    <ErrorState message={error} data-test="medium-show-error" />
                ) : null}
                {success ? (
                    <SuccessState
                        message={success}
                        data-test="medium-show-success"
                    />
                ) : null}

                <form
                    className="grid max-w-xl gap-4 rounded-xl border p-4"
                    onSubmit={saveMetadata}
                    data-test="medium-edit-form"
                >
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            data-test="medium-name-input"
                        />
                    </FormField>
                    <FormField label="Technischer Code" htmlFor="code">
                        <Input
                            id="code"
                            value={medium.code}
                            disabled
                            className="font-mono"
                            data-test="medium-code-readonly"
                        />
                    </FormField>
                    <FormField
                        label="Standardlänge (Sekunden)"
                        htmlFor="default_length_seconds"
                    >
                        <Input
                            id="default_length_seconds"
                            type="number"
                            min={1}
                            max={3600}
                            value={length}
                            onChange={(e) =>
                                setLength(Number(e.target.value || 30))
                            }
                            data-test="medium-length-input"
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
                            data-test="medium-sort-input"
                        />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={discountable}
                            onChange={(e) => setDiscountable(e.target.checked)}
                            data-test="medium-discountable-input"
                        />
                        Rabattfähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={aeEligible}
                            onChange={(e) => setAeEligible(e.target.checked)}
                            data-test="medium-ae-input"
                        />
                        AE-fähig
                    </label>
                    <Button
                        type="submit"
                        disabled={busy}
                        data-test="medium-save-button"
                    >
                        Speichern
                    </Button>
                </form>

                <div
                    className="grid max-w-xl gap-3 rounded-xl border p-4"
                    data-test="medium-category-change-panel"
                >
                    <h2 className="text-base font-semibold">
                        Oberkategorie wechseln
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Aktuell: {categoryName} ({categoryKey}, ID {categoryId}
                        ). Historische Snapshots bleiben unverändert.
                    </p>
                    <select
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        value={targetCategoryId}
                        onChange={(e) => setTargetCategoryId(e.target.value)}
                        data-test="medium-target-category-select"
                    >
                        {compatibleTargets.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name} ({c.key})
                            </option>
                        ))}
                    </select>
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={loadCategoryPreview}
                        data-test="medium-category-preview-button"
                    >
                        Auswirkungsvorschau laden
                    </Button>
                </div>

                {deactivatePreview ? (
                    <div
                        className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                        data-test="medium-deactivate-preview"
                    >
                        <h2 className="font-semibold">
                            Deaktivierungsvorschau
                        </h2>
                        <p>{deactivatePreview.historical_snapshots_note}</p>
                        <p>{deactivatePreview.new_processes_note}</p>
                        {deactivatePreview.reactivation_assignment_warning ? (
                            <p data-test="medium-reactivation-warning">
                                {
                                    deactivatePreview.reactivation_assignment_warning
                                }
                            </p>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setDeactivatePreview(null)}
                            >
                                Abbrechen
                            </Button>
                            <Button
                                disabled={
                                    busy || !deactivatePreview.can_proceed
                                }
                                onClick={confirmDeactivate}
                                data-test="medium-deactivate-confirm"
                            >
                                Deaktivierung bestätigen
                            </Button>
                        </div>
                    </div>
                ) : null}

                {categoryPreview ? (
                    <div
                        className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                        data-test="medium-category-preview"
                    >
                        <h2 className="font-semibold">
                            Kategoriewechsel-Vorschau
                        </h2>
                        <p>{categoryPreview.historical_snapshots_note}</p>
                        <p>{categoryPreview.new_processes_note}</p>
                        {categoryPreview.schema_change_warning ? (
                            <p>{categoryPreview.schema_change_warning}</p>
                        ) : null}
                        {categoryPreview.blocking_reasons.map((reason) => (
                            <p
                                key={reason.code}
                                className="font-medium text-red-800 dark:text-red-200"
                                data-test="medium-category-blocker"
                            >
                                {reason.message}
                            </p>
                        ))}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setCategoryPreview(null)}
                            >
                                Abbrechen
                            </Button>
                            <Button
                                disabled={busy || !categoryPreview.can_proceed}
                                onClick={confirmCategoryChange}
                                data-test="medium-category-confirm"
                            >
                                Wechsel bestätigen
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>
        </>
    );
}
