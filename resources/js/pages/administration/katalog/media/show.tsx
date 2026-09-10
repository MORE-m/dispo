import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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
    is_medium_default?: boolean;
    is_operative: boolean;
    stored_inactive_note: string | null;
    method_show_url: string;
};

type CategoryMethodRow = {
    calculation_method_id: number;
    key: string;
    name: string;
    method_is_active: boolean;
    assigned: boolean;
    is_active: boolean;
    sort: number;
    engine_profile_key: string | null;
    registry_pairs: RegistryPair[];
    technical_status: string;
    technical_status_label: string;
    is_category_default: boolean;
    is_operative: boolean;
    method_show_url: string;
};

type Medium = {
    id: number;
    name: string;
    code: string;
    kind: string | null;
    category_id: number;
    category_name: string | null;
    category_key: string | null;
    category_is_active: boolean;
    calculation_method_mode: 'inherit' | 'override';
    default_calculation_method_id: number | null;
    category_default_calculation_method_id: number | null;
    effective_source: string;
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
    calculationMethodsPreview: string;
    calculationMethodsReplace: string;
    categoryShow: string | null;
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

type MethodsPreview = {
    fingerprint: string;
    lock_version: number;
    has_changes: boolean;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    evaluation: {
        source_before: string;
        source_after: string;
        bookable_before: boolean;
        bookable_after: boolean;
        reason_before: string | null;
        reason_after: string | null;
    };
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

function MethodMeta({
    row,
    testPrefix,
}: {
    row: MethodRow | CategoryMethodRow;
    testPrefix: string;
}) {
    const statusLabel =
        'method_status_label' in row
            ? row.method_status_label
            : row.method_is_active
              ? 'Aktiv'
              : 'Inaktiv';

    return (
        <div className="space-y-1 text-sm">
            <div className="font-medium">
                {row.name}{' '}
                <span className="text-muted-foreground font-mono text-xs">
                    {row.key}
                </span>
            </div>
            <div>
                Global:{' '}
                <span data-test={`${testPrefix}-method-global-${row.key}`}>
                    {statusLabel}
                </span>
            </div>
            <div>
                Technik:{' '}
                <span data-test={`${testPrefix}-method-tech-${row.key}`}>
                    {row.technical_status_label}
                </span>
            </div>
            {row.registry_pairs.length > 0 ? (
                <div
                    className="text-muted-foreground"
                    data-test={`${testPrefix}-method-registry-${row.key}`}
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
            {'is_category_default' in row && row.is_category_default ? (
                <div className="text-muted-foreground text-xs">
                    Kategorie-Default
                </div>
            ) : null}
            {'is_medium_default' in row && row.is_medium_default ? (
                <div className="text-muted-foreground text-xs">
                    Medium-Default
                </div>
            ) : null}
            {'stored_inactive_note' in row && row.stored_inactive_note ? (
                <div
                    className="text-muted-foreground text-xs italic"
                    data-test={`${testPrefix}-method-stored-note-${row.key}`}
                >
                    {row.stored_inactive_note}
                </div>
            ) : null}
            {row.is_operative ? (
                <div
                    className="text-xs font-medium text-emerald-700 dark:text-emerald-300"
                    data-test={`${testPrefix}-method-operative-${row.key}`}
                >
                    Wirksam
                </div>
            ) : null}
            <Link
                href={row.method_show_url}
                className="underline-offset-4 hover:underline"
                data-test={`${testPrefix}-method-link-${row.key}`}
            >
                Methodendetail öffnen
            </Link>
        </div>
    );
}

export default function MediaShow({
    medium: initialMedium,
    calculationMethods: initialCalculationMethods,
    categoryCalculationMethods: initialCategoryCalculationMethods,
    methodsBoundaryNote,
    formOptions,
    catalogNote,
    routes,
}: {
    medium: Medium;
    calculationMethods: MethodRow[];
    categoryCalculationMethods: CategoryMethodRow[];
    methodsBoundaryNote: string;
    formOptions: { categories: CategoryOption[] };
    catalogNote: string;
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [medium, setMedium] = useState(initialMedium);
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
    const [calculationMethodMode, setCalculationMethodMode] = useState<
        'inherit' | 'override'
    >(medium.calculation_method_mode);
    const [methods, setMethods] = useState(initialCalculationMethods);
    const [categoryMethods, setCategoryMethods] = useState(
        initialCategoryCalculationMethods,
    );
    const [defaultMethodId, setDefaultMethodId] = useState<number | null>(
        medium.default_calculation_method_id,
    );
    const [drafts, setDrafts] = useState<Record<number, DraftRow>>(() => {
        const map: Record<number, DraftRow> = {};
        for (const row of initialCalculationMethods) {
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
    const [deactivatePreview, setDeactivatePreview] =
        useState<ImpactPreview | null>(null);
    const [categoryPreview, setCategoryPreview] =
        useState<ImpactPreview | null>(null);
    const [methodsPreview, setMethodsPreview] = useState<MethodsPreview | null>(
        null,
    );
    const methodsPreviewRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (methodsPreview && methodsPreviewRef.current) {
            methodsPreviewRef.current.focus();
        }
    }, [methodsPreview]);

    const compatibleTargets = formOptions.categories;

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
            return row.default_eligible;
        });
    }, [sortedMethods, drafts]);

    function buildAssignmentsPayload(): DraftRow[] {
        return Object.values(drafts)
            .filter((row) => {
                const original = methods.find(
                    (m) =>
                        m.calculation_method_id === row.calculation_method_id,
                );
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
                    calculation_method_mode: calculationMethodMode,
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
                    data.errors?.calculation_method_mode?.[0] ||
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
                    calculation_method_mode: calculationMethodMode,
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
            setCalculationMethodMode(
                data.calculation_method_mode ?? calculationMethodMode,
            );
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
            if (Array.isArray(data.categoryCalculationMethods)) {
                setCategoryMethods(data.categoryCalculationMethods);
            }
            if (data.medium) {
                setMedium(data.medium);
            }
            setMethodsPreview(null);
            setSuccess(data.message || 'Berechnungsmethoden gespeichert.');
        } catch {
            setError('Speichern der Berechnungsmethoden fehlgeschlagen.');
        } finally {
            setBusy(false);
        }
    }

    function renderCategoryReferenceList() {
        if (categoryMethods.length === 0) {
            return (
                <p
                    className="text-muted-foreground text-sm"
                    data-test="medium-category-methods-empty"
                >
                    Keine Kategorie-Zuordnungen vorhanden.
                </p>
            );
        }

        return (
            <ul className="space-y-3" data-test="medium-category-methods-list">
                {categoryMethods.map((row) => (
                    <li
                        key={row.calculation_method_id}
                        className="rounded-lg border p-3"
                        data-test={`medium-category-method-row-${row.key}`}
                    >
                        <MethodMeta row={row} testPrefix="medium-category" />
                    </li>
                ))}
            </ul>
        );
    }

    function renderMediumMethodsList() {
        return (
            <ul className="space-y-3" data-test="medium-methods-list">
                {sortedMethods.map((row) => {
                    const draft = drafts[row.calculation_method_id] ?? {
                        calculation_method_id: row.calculation_method_id,
                        is_active: false,
                        sort: row.sort,
                    };
                    return (
                        <li
                            key={row.calculation_method_id}
                            className="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_auto]"
                            data-test={`medium-method-row-${row.key}`}
                        >
                            <MethodMeta row={row} testPrefix="medium" />
                            <div className="flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={draft.is_active}
                                        disabled={busy || !row.method_is_active}
                                        onChange={(e) =>
                                            updateDraft(
                                                row.calculation_method_id,
                                                {
                                                    is_active: e.target.checked,
                                                },
                                            )
                                        }
                                        data-test={`medium-method-active-${row.key}`}
                                    />
                                    Mediumzuordnung aktiv
                                </label>
                                <FormField
                                    label="Sortierung"
                                    htmlFor={`medium-method-sort-${row.key}`}
                                >
                                    <Input
                                        id={`medium-method-sort-${row.key}`}
                                        type="number"
                                        min={0}
                                        value={draft.sort}
                                        onChange={(e) =>
                                            updateDraft(
                                                row.calculation_method_id,
                                                {
                                                    sort: Number(
                                                        e.target.value || 0,
                                                    ),
                                                },
                                            )
                                        }
                                        data-test={`medium-method-sort-${row.key}`}
                                    />
                                </FormField>
                            </div>
                        </li>
                    );
                })}
            </ul>
        );
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

                <section
                    className="space-y-4 rounded-xl border p-4"
                    data-test="medium-calculation-methods-section"
                    aria-labelledby="medium-calculation-methods-heading"
                >
                    <div>
                        <h2
                            id="medium-calculation-methods-heading"
                            className="text-base font-semibold"
                        >
                            Berechnungsmethoden
                        </h2>
                        <p
                            className="text-muted-foreground mt-1 text-sm"
                            data-test="medium-methods-boundary-note"
                        >
                            {methodsBoundaryNote}
                        </p>
                        <p
                            className="text-muted-foreground mt-1 text-sm"
                            data-test="medium-effective-source"
                        >
                            Wirksame Quelle: {medium.effective_source}
                        </p>
                        {routes.categoryShow ? (
                            <p className="mt-1 text-sm">
                                <Link
                                    href={routes.categoryShow}
                                    className="underline-offset-4 hover:underline"
                                    data-test="medium-category-show-link"
                                >
                                    Oberkategorie {categoryName} ({categoryKey})
                                    öffnen
                                </Link>
                            </p>
                        ) : null}
                    </div>

                    <FormField
                        label="Vererbungsmodus"
                        htmlFor="medium-calculation-method-mode"
                    >
                        <select
                            id="medium-calculation-method-mode"
                            className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full max-w-xl rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            value={calculationMethodMode}
                            onChange={(e) => {
                                setCalculationMethodMode(
                                    e.target.value as 'inherit' | 'override',
                                );
                                setMethodsPreview(null);
                            }}
                            data-test="medium-calculation-method-mode"
                        >
                            <option value="inherit">
                                Von Oberkategorie erben
                            </option>
                            <option value="override">
                                Eigene Konfiguration
                            </option>
                        </select>
                    </FormField>

                    {calculationMethodMode === 'inherit' ? (
                        <>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    Kategorie-Konfiguration (wirksam)
                                </h3>
                                {renderCategoryReferenceList()}
                            </div>
                            <p
                                className="text-muted-foreground text-sm"
                                data-test="medium-inherit-stored-note"
                            >
                                Gespeicherte aktive Werbemittel-Zuordnungen
                                bleiben erhalten und können die globale
                                Deaktivierung einer Berechnungsmethode weiterhin
                                blockieren.
                            </p>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    Gespeicherte Werbemittel-Overrides
                                </h3>
                                <FormField
                                    label="Medium-Default (gespeichert)"
                                    htmlFor="medium-default-method"
                                >
                                    <select
                                        id="medium-default-method"
                                        className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full max-w-xl rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        value={defaultMethodId ?? ''}
                                        onChange={(e) => {
                                            const value = e.target.value;
                                            setDefaultMethodId(
                                                value === ''
                                                    ? null
                                                    : Number(value),
                                            );
                                            setMethodsPreview(null);
                                        }}
                                        data-test="medium-default-method-select"
                                    >
                                        <option value="">Kein Default</option>
                                        {defaultOptions.map((row) => (
                                            <option
                                                key={row.calculation_method_id}
                                                value={
                                                    row.calculation_method_id
                                                }
                                                data-test={`medium-default-option-${row.key}`}
                                            >
                                                {row.name} ({row.key})
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                {renderMediumMethodsList()}
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    Werbemittel-Konfiguration (wirksam)
                                </h3>
                                <FormField
                                    label="Medium-Default"
                                    htmlFor="medium-default-method"
                                >
                                    <select
                                        id="medium-default-method"
                                        className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full max-w-xl rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        value={defaultMethodId ?? ''}
                                        onChange={(e) => {
                                            const value = e.target.value;
                                            setDefaultMethodId(
                                                value === ''
                                                    ? null
                                                    : Number(value),
                                            );
                                            setMethodsPreview(null);
                                        }}
                                        data-test="medium-default-method-select"
                                    >
                                        <option value="">Kein Default</option>
                                        {defaultOptions.map((row) => (
                                            <option
                                                key={row.calculation_method_id}
                                                value={
                                                    row.calculation_method_id
                                                }
                                                data-test={`medium-default-option-${row.key}`}
                                            >
                                                {row.name} ({row.key})
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                                {renderMediumMethodsList()}
                            </div>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    Kategorie-Referenz (nicht wirksam)
                                </h3>
                                {renderCategoryReferenceList()}
                            </div>
                        </>
                    )}

                    <Button
                        type="button"
                        disabled={busy}
                        onClick={loadMethodsPreview}
                        data-test="medium-methods-preview-button"
                    >
                        Vorschau und speichern…
                    </Button>

                    {methodsPreview ? (
                        <div
                            ref={methodsPreviewRef}
                            tabIndex={-1}
                            className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                            data-test="medium-methods-preview"
                            role="dialog"
                            aria-labelledby="medium-methods-preview-title"
                        >
                            <h3
                                id="medium-methods-preview-title"
                                className="font-semibold"
                            >
                                Methodenvorschau
                            </h3>
                            <p>{methodsPreview.dependency_note}</p>
                            <p data-test="medium-methods-preview-changes">
                                {methodsPreview.has_changes
                                    ? 'Es liegen Änderungen vor.'
                                    : 'Keine Änderungen'}
                            </p>
                            <div data-test="medium-methods-preview-evaluation">
                                <p>
                                    Buchbar vorher:{' '}
                                    {methodsPreview.evaluation.bookable_before
                                        ? 'Ja'
                                        : 'Nein'}
                                    {methodsPreview.evaluation.reason_before
                                        ? ` (${methodsPreview.evaluation.reason_before})`
                                        : ''}
                                </p>
                                <p>
                                    Buchbar nachher:{' '}
                                    {methodsPreview.evaluation.bookable_after
                                        ? 'Ja'
                                        : 'Nein'}
                                    {methodsPreview.evaluation.reason_after
                                        ? ` (${methodsPreview.evaluation.reason_after})`
                                        : ''}
                                </p>
                                <p className="text-muted-foreground">
                                    Quelle vorher:{' '}
                                    {methodsPreview.evaluation.source_before} ·
                                    Quelle nachher:{' '}
                                    {methodsPreview.evaluation.source_after}
                                </p>
                            </div>
                            {methodsPreview.blocking_reasons.map((reason) => (
                                <p
                                    key={reason.code + reason.message}
                                    className="font-medium text-red-800 dark:text-red-200"
                                    data-test="medium-methods-preview-blocker"
                                >
                                    {reason.message}
                                </p>
                            ))}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setMethodsPreview(null)}
                                    data-test="medium-methods-preview-cancel"
                                >
                                    Abbrechen
                                </Button>
                                {methodsPreview.has_changes ? (
                                    <Button
                                        disabled={
                                            busy || !methodsPreview.can_proceed
                                        }
                                        onClick={confirmMethodsApply}
                                        data-test="medium-methods-preview-confirm"
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
                                        data-test="medium-methods-preview-noop"
                                    >
                                        Keine Änderungen
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : null}
                </section>

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
