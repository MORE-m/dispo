import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type MediaRow = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    status_label: string;
};

type RegistryPair = {
    engine_profile_key: string;
    pair_status: string;
    current_released_version: string | null;
};

type MethodRow = {
    calculation_method_id: number;
    key: string;
    name: string;
    method_is_active: boolean;
    method_status_label: string;
    assigned: boolean;
    is_active: boolean;
    sort: number;
    engine_profile_key: string | null;
    assignment_lock_version: number | null;
    registry_pairs: RegistryPair[];
    technical_status: string;
    technical_status_label: string;
    default_eligible: boolean;
    is_category_default: boolean;
    method_show_url: string;
};

type Category = {
    id: number;
    key: string;
    name: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    lock_version: number;
    default_calculation_method_id: number | null;
    media_active_count: number;
    media_inactive_count: number;
    assignments_active_count: number;
    assignments_inactive_count: number;
    media: MediaRow[];
};

type Routes = {
    index: string;
    update: string;
    deactivatePreview: string;
    deactivate: string;
    reactivate: string;
    calculationMethodsPreview: string;
    calculationMethodsReplace: string;
};

type ImpactPreview = {
    fingerprint: string;
    lock_version: number;
    can_proceed: boolean;
    active_media: Array<{ id: number; name: string; code: string }>;
    blocking_reasons: Array<{ code: string; message: string }>;
    assignments: { active: number; inactive: number; total: number };
    reactivation_assignment_warning: string | null;
    historical_snapshots_note: string;
    new_processes_note: string;
    calculation_positions_count: number;
    dispo_order_positions_count: number;
};

type MethodsPreview = {
    fingerprint: string;
    lock_version: number;
    has_changes: boolean;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    protected_inherit_media: Array<{
        id: number;
        code: string;
        name: string;
        bookable_before: boolean;
        bookable_after: boolean;
        unbookable_reason_after: string | null;
    }>;
    override_media_count: number;
    dependency_note: string;
};

type DraftRow = {
    calculation_method_id: number;
    is_active: boolean;
    sort: number;
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

export default function CategoryShow({
    category,
    calculationMethods,
    boundaryNote,
    routes,
}: {
    category: Category;
    calculationMethods: MethodRow[];
    boundaryNote: string;
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(category.name);
    const [sort, setSort] = useState(category.sort);
    const [lockVersion, setLockVersion] = useState(category.lock_version);
    const [isActive, setIsActive] = useState(category.is_active);
    const [statusLabel, setStatusLabel] = useState(category.status_label);
    const [methods, setMethods] = useState(calculationMethods);
    const [defaultMethodId, setDefaultMethodId] = useState<number | null>(
        category.default_calculation_method_id,
    );
    const [drafts, setDrafts] = useState<Record<number, DraftRow>>(() => {
        const map: Record<number, DraftRow> = {};
        for (const row of calculationMethods) {
            map[row.calculation_method_id] = {
                calculation_method_id: row.calculation_method_id,
                is_active: row.is_active,
                sort: row.sort,
            };
        }
        return map;
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ? String(flash.success) : null,
    );
    const [preview, setPreview] = useState<ImpactPreview | null>(null);
    const [methodsPreview, setMethodsPreview] = useState<MethodsPreview | null>(
        null,
    );
    const methodsPreviewRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (methodsPreview && methodsPreviewRef.current) {
            methodsPreviewRef.current.focus();
        }
    }, [methodsPreview]);

    const sortedMethods = useMemo(() => {
        return [...methods].sort((a, b) => {
            const draftA = drafts[a.calculation_method_id];
            const draftB = drafts[b.calculation_method_id];
            const sortA = draftA?.sort ?? a.sort;
            const sortB = draftB?.sort ?? b.sort;
            if (sortA !== sortB) {
                return sortA - sortB;
            }
            return a.calculation_method_id - b.calculation_method_id;
        });
    }, [methods, drafts]);

    const defaultOptions = useMemo(() => {
        return sortedMethods.filter((row) => {
            const draft = drafts[row.calculation_method_id];
            if (!draft?.is_active || !row.method_is_active) {
                return false;
            }
            return (
                row.technical_status === 'released_executable' &&
                Boolean(row.engine_profile_key)
            );
        });
    }, [sortedMethods, drafts]);

    function buildAssignmentsPayload(): DraftRow[] {
        return Object.values(drafts)
            .filter((row) => {
                const original = methods.find(
                    (m) =>
                        m.calculation_method_id === row.calculation_method_id,
                );
                // Send active drafts and existing assigned rows (incl. explicit inactive).
                return row.is_active || Boolean(original?.assigned);
            })
            .map((row) => ({
                calculation_method_id: row.calculation_method_id,
                is_active: row.is_active,
                sort: row.sort,
            }))
            .sort((a, b) => a.calculation_method_id - b.calculation_method_id);
    }

    function updateDraft(
        methodId: number,
        patch: Partial<Pick<DraftRow, 'is_active' | 'sort'>>,
    ) {
        setDrafts((prev) => ({
            ...prev,
            [methodId]: {
                calculation_method_id: methodId,
                is_active:
                    patch.is_active ?? prev[methodId]?.is_active ?? false,
                sort: patch.sort ?? prev[methodId]?.sort ?? 0,
            },
        }));
        setMethodsPreview(null);
    }

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
                setError(
                    data.message ||
                        data.errors?.name?.[0] ||
                        'Speichern fehlgeschlagen.',
                );
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
        setSuccess(null);
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
                    data.message ||
                        'Vorschau oder Sperrversion veraltet. Bitte erneut laden.',
                );
                setPreview(null);
                return;
            }
            if (!response.ok) {
                const msg =
                    data.message ||
                    data.errors?.category?.[0] ||
                    'Deaktivierung fehlgeschlagen.';
                setError(msg);
                return;
            }
            setIsActive(false);
            setStatusLabel('Inaktiv');
            setLockVersion(data.lock_version);
            setPreview(null);
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
                setError(data.message || 'Reaktivierung fehlgeschlagen.');
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

    async function loadMethodsPreview() {
        setBusy(true);
        setError(null);
        setSuccess(null);
        setMethodsPreview(null);
        try {
            const response = await fetch(routes.calculationMethodsPreview, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    lock_version: lockVersion,
                    default_calculation_method_id: defaultMethodId,
                    assignments: buildAssignmentsPayload(),
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const msg =
                    data.message ||
                    data.errors?.assignments?.[0] ||
                    data.errors?.default_calculation_method_id?.[0] ||
                    data.errors?.calculation_method_id?.[0] ||
                    'Methodenvorschau fehlgeschlagen.';
                setError(msg);
                return;
            }
            setMethodsPreview(data);
            setLockVersion(data.lock_version ?? lockVersion);
        } catch {
            setError('Methodenvorschau fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmMethodsApply() {
        if (!methodsPreview) {
            return;
        }
        if (!methodsPreview.has_changes) {
            setMethodsPreview(null);
            setSuccess('Keine Änderungen');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(routes.calculationMethodsReplace, {
                method: 'PUT',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    lock_version: lockVersion,
                    fingerprint: methodsPreview.fingerprint,
                    default_calculation_method_id: defaultMethodId,
                    assignments: buildAssignmentsPayload(),
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409) {
                setError(
                    data.message ||
                        'Vorschau oder Sperrversion veraltet. Bitte erneut laden.',
                );
                setMethodsPreview(null);
                return;
            }
            if (!response.ok) {
                const msg =
                    data.message ||
                    data.errors?.assignments?.[0] ||
                    data.errors?.default_calculation_method_id?.[0] ||
                    'Speichern der Berechnungsmethoden fehlgeschlagen.';
                setError(msg);
                return;
            }
            setLockVersion(data.lock_version);
            setDefaultMethodId(
                data.default_calculation_method_id ?? defaultMethodId,
            );
            if (Array.isArray(data.calculationMethods)) {
                setMethods(data.calculationMethods);
                const next: Record<number, DraftRow> = {};
                for (const row of data.calculationMethods as MethodRow[]) {
                    next[row.calculation_method_id] = {
                        calculation_method_id: row.calculation_method_id,
                        is_active: row.is_active,
                        sort: row.sort,
                    };
                }
                setDrafts(next);
            }
            setMethodsPreview(null);
            setSuccess(data.message || 'Berechnungsmethoden gespeichert.');
        } catch {
            setError('Speichern der Berechnungsmethoden fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={category.name} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={category.name}
                    description={`Key ${category.key} (unveränderlich) · Sperrversion ${lockVersion}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={routes.index}
                                    data-test="category-back-link"
                                >
                                    Zur Liste
                                </Link>
                            </Button>
                            {isActive ? (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={loadDeactivatePreview}
                                    data-test="category-deactivate-preview-button"
                                >
                                    Deaktivieren…
                                </Button>
                            ) : (
                                <Button
                                    disabled={busy}
                                    onClick={reactivate}
                                    data-test="category-reactivate-button"
                                >
                                    Reaktivieren
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <span data-test="category-status-badge">{statusLabel}</span>
                    <span className="text-muted-foreground text-sm">
                        Werbemittel: {category.media_active_count} aktiv /{' '}
                        {category.media_inactive_count} inaktiv · Assignments:{' '}
                        {category.assignments_active_count} aktiv /{' '}
                        {category.assignments_inactive_count} inaktiv
                    </span>
                </div>

                {error ? (
                    <ErrorState
                        message={error}
                        data-test="category-show-error"
                    />
                ) : null}
                {success ? (
                    <SuccessState
                        message={success}
                        data-test="category-show-success"
                    />
                ) : null}

                <form
                    className="grid max-w-xl gap-4 rounded-xl border p-4"
                    onSubmit={saveMetadata}
                    data-test="category-edit-form"
                >
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            data-test="category-name-input"
                        />
                    </FormField>
                    <FormField label="Technischer Key" htmlFor="key">
                        <Input
                            id="key"
                            value={category.key}
                            disabled
                            className="font-mono"
                            data-test="category-key-readonly"
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
                            data-test="category-sort-input"
                        />
                    </FormField>
                    <Button
                        type="submit"
                        disabled={busy}
                        data-test="category-save-button"
                    >
                        Speichern
                    </Button>
                </form>

                <section
                    className="space-y-4 rounded-xl border p-4"
                    data-test="category-calculation-methods-section"
                    aria-labelledby="category-calculation-methods-heading"
                >
                    <div>
                        <h2
                            id="category-calculation-methods-heading"
                            className="text-base font-semibold"
                        >
                            Berechnungsmethoden
                        </h2>
                        <p
                            className="text-muted-foreground mt-1 text-sm"
                            data-test="category-methods-boundary-note"
                        >
                            {boundaryNote}
                        </p>
                    </div>

                    <FormField
                        label="Kategorie-Default"
                        htmlFor="category-default-method"
                    >
                        <select
                            id="category-default-method"
                            className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full max-w-xl rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            value={defaultMethodId ?? ''}
                            onChange={(e) => {
                                const value = e.target.value;
                                setDefaultMethodId(
                                    value === '' ? null : Number(value),
                                );
                                setMethodsPreview(null);
                            }}
                            data-test="category-default-method-select"
                        >
                            <option value="">Kein Default</option>
                            {defaultOptions.map((row) => (
                                <option
                                    key={row.calculation_method_id}
                                    value={row.calculation_method_id}
                                    data-test={`category-default-option-${row.key}`}
                                >
                                    {row.name} ({row.key})
                                </option>
                            ))}
                        </select>
                    </FormField>

                    <ul className="space-y-3" data-test="category-methods-list">
                        {sortedMethods.map((row) => {
                            const draft = drafts[row.calculation_method_id] ?? {
                                calculation_method_id:
                                    row.calculation_method_id,
                                is_active: false,
                                sort: row.sort,
                            };
                            return (
                                <li
                                    key={row.calculation_method_id}
                                    className="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_auto]"
                                    data-test={`category-method-row-${row.key}`}
                                >
                                    <div className="space-y-1 text-sm">
                                        <div className="font-medium">
                                            {row.name}{' '}
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {row.key}
                                            </span>
                                        </div>
                                        <div>
                                            Global:{' '}
                                            <span
                                                data-test={`category-method-global-${row.key}`}
                                            >
                                                {row.method_status_label}
                                            </span>
                                        </div>
                                        <div>
                                            Technik:{' '}
                                            <span
                                                data-test={`category-method-tech-${row.key}`}
                                            >
                                                {row.technical_status_label}
                                            </span>
                                        </div>
                                        {row.registry_pairs.length > 0 ? (
                                            <div
                                                className="text-muted-foreground"
                                                data-test={`category-method-registry-${row.key}`}
                                            >
                                                Registry:{' '}
                                                {row.registry_pairs
                                                    .map(
                                                        (pair) =>
                                                            `${pair.engine_profile_key}:${pair.pair_status}${pair.current_released_version ? `/${pair.current_released_version}` : ''}`,
                                                    )
                                                    .join(', ')}
                                            </div>
                                        ) : (
                                            <div className="text-muted-foreground">
                                                Keine Registry-Paare
                                            </div>
                                        )}
                                        {row.engine_profile_key ? (
                                            <div className="font-mono text-xs">
                                                Profil: {row.engine_profile_key}
                                            </div>
                                        ) : null}
                                        <Link
                                            href={row.method_show_url}
                                            className="underline-offset-4 hover:underline"
                                            data-test={`category-method-link-${row.key}`}
                                        >
                                            Methodendetail öffnen
                                        </Link>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={draft.is_active}
                                                disabled={
                                                    busy ||
                                                    !row.method_is_active
                                                }
                                                onChange={(e) =>
                                                    updateDraft(
                                                        row.calculation_method_id,
                                                        {
                                                            is_active:
                                                                e.target
                                                                    .checked,
                                                        },
                                                    )
                                                }
                                                data-test={`category-method-active-${row.key}`}
                                            />
                                            Kategoriezuordnung aktiv
                                        </label>
                                        <FormField
                                            label="Sortierung"
                                            htmlFor={`method-sort-${row.key}`}
                                        >
                                            <Input
                                                id={`method-sort-${row.key}`}
                                                type="number"
                                                min={0}
                                                value={draft.sort}
                                                onChange={(e) =>
                                                    updateDraft(
                                                        row.calculation_method_id,
                                                        {
                                                            sort: Number(
                                                                e.target
                                                                    .value || 0,
                                                            ),
                                                        },
                                                    )
                                                }
                                                data-test={`category-method-sort-${row.key}`}
                                            />
                                        </FormField>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <Button
                        type="button"
                        disabled={busy}
                        onClick={loadMethodsPreview}
                        data-test="category-methods-preview-button"
                    >
                        Vorschau und speichern…
                    </Button>

                    {methodsPreview ? (
                        <div
                            ref={methodsPreviewRef}
                            tabIndex={-1}
                            className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                            data-test="category-methods-preview"
                            role="dialog"
                            aria-labelledby="category-methods-preview-title"
                        >
                            <h3
                                id="category-methods-preview-title"
                                className="font-semibold"
                            >
                                Methodenvorschau
                            </h3>
                            <p>{methodsPreview.dependency_note}</p>
                            <p data-test="category-methods-preview-changes">
                                {methodsPreview.has_changes
                                    ? 'Es liegen Änderungen vor.'
                                    : 'Keine Änderungen'}
                            </p>
                            {methodsPreview.override_media_count > 0 ? (
                                <p data-test="category-methods-preview-overrides">
                                    Override-Medien (unverändert):{' '}
                                    {methodsPreview.override_media_count}
                                </p>
                            ) : null}
                            {methodsPreview.blocking_reasons.map((reason) => (
                                <p
                                    key={reason.code + reason.message}
                                    className="font-medium text-red-800 dark:text-red-200"
                                    data-test="category-methods-preview-blocker"
                                >
                                    {reason.message}
                                </p>
                            ))}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setMethodsPreview(null)}
                                    data-test="category-methods-preview-cancel"
                                >
                                    Abbrechen
                                </Button>
                                {methodsPreview.has_changes ? (
                                    <Button
                                        disabled={
                                            busy || !methodsPreview.can_proceed
                                        }
                                        onClick={confirmMethodsApply}
                                        data-test="category-methods-preview-confirm"
                                    >
                                        Desired State anwenden
                                    </Button>
                                ) : (
                                    <Button
                                        disabled={busy}
                                        onClick={() => {
                                            setMethodsPreview(null);
                                            setSuccess('Keine Änderungen');
                                        }}
                                        data-test="category-methods-preview-noop"
                                    >
                                        Schließen
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : null}
                </section>

                {preview ? (
                    <div
                        className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                        data-test="category-deactivate-preview"
                    >
                        <h2 className="font-semibold">Auswirkungsvorschau</h2>
                        <p>{preview.historical_snapshots_note}</p>
                        <p>{preview.new_processes_note}</p>
                        {preview.reactivation_assignment_warning ? (
                            <p data-test="category-reactivation-warning">
                                {preview.reactivation_assignment_warning}
                            </p>
                        ) : null}
                        <p>
                            Assignments: {preview.assignments.active} aktiv /{' '}
                            {preview.assignments.inactive} inaktiv · Positionen
                            Calc/Dispo: {preview.calculation_positions_count}/
                            {preview.dispo_order_positions_count}
                        </p>
                        {preview.active_media.length > 0 ? (
                            <div data-test="category-active-media-list">
                                <p className="font-medium">
                                    Aktive Werbemittel (blockieren):
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {preview.active_media.map((m) => (
                                        <li key={m.id}>
                                            {m.name} ({m.code})
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                        {preview.blocking_reasons.map((reason) => (
                            <p
                                key={reason.code}
                                className="font-medium text-red-800 dark:text-red-200"
                                data-test="category-deactivate-blocker"
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
                            <Button
                                disabled={busy || !preview.can_proceed}
                                onClick={confirmDeactivate}
                                data-test="category-deactivate-confirm"
                            >
                                Deaktivierung bestätigen
                            </Button>
                        </div>
                    </div>
                ) : null}

                <div className="rounded-xl border p-4">
                    <h2 className="mb-3 text-base font-semibold">
                        Zugeordnete Werbemittel
                    </h2>
                    {category.media.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Noch keine Werbemittel in dieser Kategorie.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {category.media.map((m) => (
                                <li key={m.id}>
                                    <Link
                                        href={`/administration/katalog/werbemittel/${m.id}`}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {m.name}
                                    </Link>{' '}
                                    <span className="text-muted-foreground font-mono text-xs">
                                        {m.code}
                                    </span>{' '}
                                    · {m.status_label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
