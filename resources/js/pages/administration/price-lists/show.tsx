import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    draftFormSnapshot,
    formFieldsReadOnly,
    formLockAfterPreview,
    gridFromBaseItems,
    isDraftDirty,
    itemsFromGrid,
    PRICE_LIST_BASE_GROUPS,
    PRICE_LIST_HOURS,
    UNSAVED_LIFECYCLE_MESSAGE,
    type PriceListBaseItem,
} from '@/lib/price-list-draft-form';

type InventoryInfo = {
    id: number | null;
    name: string | null;
    code: string | null;
    type: string | null;
    type_label: string | null;
};

type DerivedPrice = {
    hour: number;
    day_group: string;
    day_group_label: string;
    second_price: string;
};

type PriceListDetail = {
    id: number;
    name: string;
    year: number;
    version: string;
    revision_number: number;
    status: string;
    status_label: string;
    editable: boolean;
    lock_version: number;
    valid_from: string | null;
    inventory: InventoryInfo;
    derived: DerivedPrice[];
};

type Routes = {
    index: string;
    update: string;
    inspect: string;
    activatePreview: string;
    activate: string;
    archivePreview: string;
    archive: string;
    copy: string;
};

type Preview = {
    fingerprint: string;
    lock_version: number;
    action?: string;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    historical_note?: string;
    current_year_note?: string;
    warnings?: string[];
    replaced_active?: Array<{
        id: number;
        name: string;
        version: string;
        year: number;
    }>;
    unchanged_active_years?: Array<{
        id: number;
        name: string;
        version: string;
        year: number;
    }>;
    calculation_positions_count?: number;
    dispo_order_positions_count?: number;
    is_last_active_of_year?: boolean;
    affects_current_year_default?: boolean;
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

function hourLabel(hour: number): string {
    const start = String(hour).padStart(2, '0');
    const end = String(hour).padStart(2, '0');

    return `${start}:00–${end}:59`;
}

export default function PriceListShow({
    priceList,
    baseItems,
    routes,
}: {
    priceList: PriceListDetail;
    baseItems: PriceListBaseItem[];
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(priceList.name);
    const [lockVersion, setLockVersion] = useState(priceList.lock_version);
    const [editable, setEditable] = useState(priceList.editable);
    const [statusLabel, setStatusLabel] = useState(priceList.status_label);
    const [status, setStatus] = useState(priceList.status);
    const [derived, setDerived] = useState(priceList.derived);
    const [savedDerived, setSavedDerived] = useState(priceList.derived);
    const [busy, setBusy] = useState(false);
    const requestInFlight = useRef(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ?? null,
    );
    const [preview, setPreview] = useState<Preview | null>(null);
    const [grid, setGrid] = useState<Record<string, string>>(() =>
        gridFromBaseItems(baseItems),
    );
    const [savedSnapshot, setSavedSnapshot] = useState(() =>
        draftFormSnapshot(priceList.name, gridFromBaseItems(baseItems)),
    );

    const dirty = isDraftDirty(savedSnapshot, name, grid);
    const fieldsReadOnly = formFieldsReadOnly(editable, busy);

    function beginRequest(): boolean {
        if (requestInFlight.current) {
            return false;
        }
        requestInFlight.current = true;
        setBusy(true);
        return true;
    }

    function endRequest(): void {
        requestInFlight.current = false;
        setBusy(false);
    }

    const derivedByKey = useMemo(() => {
        const map: Record<string, string> = {};
        for (const row of derived) {
            map[`${row.hour}|${row.day_group}`] = row.second_price;
        }

        return map;
    }, [derived]);

    function markLocalChange(
        nextName: string,
        nextGrid: Record<string, string>,
    ) {
        if (requestInFlight.current || !editable) {
            return;
        }
        setName(nextName);
        setGrid(nextGrid);
        setPreview(null);
        setDerived(savedDerived);
    }

    function applySavedState(payload: {
        lock_version?: number;
        priceList?: PriceListDetail;
        baseItems?: PriceListBaseItem[];
        status?: string;
        status_label?: string;
    }) {
        const nextLock =
            payload.priceList?.lock_version ?? payload.lock_version;
        if (typeof nextLock === 'number') {
            setLockVersion(nextLock);
        }
        if (payload.priceList) {
            setName(payload.priceList.name);
            setEditable(payload.priceList.editable);
            setStatusLabel(payload.priceList.status_label);
            setStatus(payload.priceList.status);
            if (payload.priceList.derived) {
                setDerived(payload.priceList.derived);
                setSavedDerived(payload.priceList.derived);
            }
        }
        if (payload.status) {
            setStatus(payload.status);
        }
        if (payload.status_label) {
            setStatusLabel(payload.status_label);
        }
        const items = payload.baseItems;
        const nextName = payload.priceList?.name ?? name;
        if (items) {
            const nextGrid = gridFromBaseItems(items);
            setGrid(nextGrid);
            setSavedSnapshot(draftFormSnapshot(nextName, nextGrid));
        } else if (payload.priceList) {
            setSavedSnapshot(draftFormSnapshot(payload.priceList.name, grid));
        }
        setPreview(null);
    }

    async function saveDraft(event: React.FormEvent) {
        event.preventDefault();
        if (!editable || !beginRequest()) {
            return;
        }
        setError(null);
        setSuccess(null);
        setPreview(null);
        try {
            const response = await fetch(routes.update, {
                method: 'PUT',
                headers: await csrfHeaders(),
                body: JSON.stringify({
                    name,
                    items: itemsFromGrid(grid),
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
                        data.errors?.items?.[0] ||
                        data.errors?.name?.[0] ||
                        data.errors?.lock_version?.[0] ||
                        'Speichern fehlgeschlagen.',
                );
                return;
            }
            applySavedState(data);
            setSuccess(data.message || 'Gespeichert.');
        } catch {
            setError('Speichern fehlgeschlagen.');
        } finally {
            endRequest();
        }
    }

    async function inspectDraft() {
        if (!beginRequest()) {
            return;
        }
        setError(null);
        setSuccess(null);
        try {
            const response = await fetch(routes.inspect, {
                method: 'POST',
                headers: await csrfHeaders(),
                body: JSON.stringify({ items: itemsFromGrid(grid) }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                setError(
                    data.message ||
                        data.errors?.items?.[0] ||
                        'Prüfung fehlgeschlagen.',
                );
                return;
            }
            setDerived(data.derived ?? []);
            if (!isDraftDirty(savedSnapshot, name, grid)) {
                setSavedDerived(data.derived ?? []);
            }
            setSuccess(
                'Eingaben geprüft. Abgeleitete Tagesgruppen aktualisiert.',
            );
        } catch {
            setError('Prüfung fehlgeschlagen.');
        } finally {
            endRequest();
        }
    }

    async function loadPreview(url: string) {
        if (dirty) {
            setPreview(null);
            setError(UNSAVED_LIFECYCLE_MESSAGE);
            setSuccess(null);
            return;
        }
        if (!beginRequest()) {
            return;
        }
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
            setLockVersion(
                formLockAfterPreview(lockVersion, data.lock_version),
            );
        } catch {
            setError('Vorschau fehlgeschlagen.');
        } finally {
            endRequest();
        }
    }

    async function confirmAction(url: string, successMessage: string) {
        if (!preview || dirty) {
            if (dirty) {
                setPreview(null);
                setError(UNSAVED_LIFECYCLE_MESSAGE);
            }
            return;
        }
        if (!beginRequest()) {
            return;
        }
        setError(null);
        try {
            const response = await fetch(url, {
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
                        data.errors?.price_list?.[0] ||
                        data.errors?.fingerprint?.[0] ||
                        'Aktion fehlgeschlagen.',
                );
                return;
            }
            applySavedState(data);
            setEditable(false);
            setSuccess(data.message || successMessage);
            router.reload({
                only: ['priceList', 'baseItems'],
                onSuccess: (page) => {
                    const props = page.props as {
                        priceList?: PriceListDetail;
                        baseItems?: PriceListBaseItem[];
                    };
                    applySavedState({
                        priceList: props.priceList,
                        baseItems: props.baseItems,
                        lock_version: props.priceList?.lock_version,
                    });
                },
            });
        } catch {
            setError('Aktion fehlgeschlagen.');
        } finally {
            endRequest();
        }
    }

    return (
        <>
            <Head title={priceList.name} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={priceList.name}
                    description={`${priceList.inventory.name} · ${priceList.inventory.type_label} · Jahr ${priceList.year} · Version ${priceList.version}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href={routes.index}>Zur Liste</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={routes.copy}
                                    data-test="price-list-copy-link"
                                >
                                    Als neuen Entwurf kopieren
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <p data-test="price-list-status">
                    Status: {statusLabel}
                    {' · '}
                    Sperrversion {lockVersion}
                </p>
                {success ? (
                    <SuccessState
                        message={success}
                        data-test="price-list-show-success"
                    />
                ) : null}
                {error ? (
                    <div data-test="price-list-show-error">
                        <ErrorState message={error} />
                    </div>
                ) : null}

                <form
                    className="space-y-4 rounded-xl border p-4"
                    data-test="price-list-edit-form"
                    onSubmit={saveDraft}
                >
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            readOnly={fieldsReadOnly}
                            onChange={(event) =>
                                markLocalChange(event.target.value, grid)
                            }
                            data-test="price-list-name-input"
                        />
                    </FormField>
                    <p className="text-muted-foreground text-sm">
                        Basispreise je Tagesgruppe und Stunde. Leeres Feld =
                        für diese Tagesgruppe/Stunde nicht buchbar (kein Preis
                        0). Mo–Sa und Mo–So erscheinen nur, wenn die
                        erforderlichen Basispreise vorhanden sind; sonst „—“.
                        Stunden dürfen je Tagesgruppe unterschiedlich sein.
                        Aktivierung braucht mindestens einen Basispreis, keine
                        vollständige 24×3-Matrix.
                    </p>
                    <div className="overflow-x-auto">
                        <table
                            className="w-full text-left text-sm"
                            data-test="price-list-hour-grid"
                        >
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-2 py-2">Stunde</th>
                                    <th className="px-2 py-2">Mo–Fr</th>
                                    <th className="px-2 py-2">Samstag</th>
                                    <th className="px-2 py-2">Sonntag</th>
                                    <th className="px-2 py-2">Mo–Sa</th>
                                    <th className="px-2 py-2">Mo–So</th>
                                </tr>
                            </thead>
                            <tbody>
                                {PRICE_LIST_HOURS.map((hour) => (
                                    <tr key={hour} className="border-t">
                                        <td className="px-2 py-1 font-mono">
                                            {hourLabel(hour)}
                                        </td>
                                        {PRICE_LIST_BASE_GROUPS.map((group) => (
                                            <td
                                                key={group.value}
                                                className="px-2 py-1"
                                            >
                                                <Input
                                                    value={
                                                        grid[
                                                            `${hour}|${group.value}`
                                                        ] ?? ''
                                                    }
                                                    readOnly={fieldsReadOnly}
                                                    onChange={(event) =>
                                                        markLocalChange(name, {
                                                            ...grid,
                                                            [`${hour}|${group.value}`]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    data-test={`price-cell-${hour}-${group.value}`}
                                                />
                                            </td>
                                        ))}
                                        <td
                                            className="text-muted-foreground px-2 py-1 font-mono"
                                            data-test={`price-derived-${hour}-mo_sa`}
                                        >
                                            {derivedByKey[`${hour}|mo_sa`] ??
                                                '—'}
                                        </td>
                                        <td
                                            className="text-muted-foreground px-2 py-1 font-mono"
                                            data-test={`price-derived-${hour}-mo_so`}
                                        >
                                            {derivedByKey[`${hour}|mo_so`] ??
                                                '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {editable ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={busy}
                                data-test="price-list-save-button"
                            >
                                Entwurf speichern
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => void inspectDraft()}
                                data-test="price-list-inspect-button"
                            >
                                Änderungen prüfen
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    void loadPreview(routes.activatePreview)
                                }
                                data-test="price-list-activate-preview-button"
                            >
                                Veröffentlichung prüfen
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    void loadPreview(routes.archivePreview)
                                }
                                data-test="price-list-archive-preview-button"
                            >
                                Archivierung prüfen
                            </Button>
                        </div>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {status === 'active' ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void loadPreview(routes.archivePreview)
                                    }
                                    data-test="price-list-archive-preview-button"
                                >
                                    Archivierung prüfen
                                </Button>
                            ) : null}
                        </div>
                    )}
                </form>

                {preview ? (
                    <div
                        className="space-y-3 rounded-xl border p-4"
                        data-test="price-list-impact-preview"
                    >
                        <h2 className="font-semibold">Auswirkungsvorschau</h2>
                        {preview.current_year_note ? (
                            <p>{preview.current_year_note}</p>
                        ) : null}
                        {preview.historical_note ? (
                            <p>{preview.historical_note}</p>
                        ) : null}
                        {preview.replaced_active &&
                        preview.replaced_active.length > 0 ? (
                            <p data-test="price-list-replaced-active">
                                Wird ersetzt:{' '}
                                {preview.replaced_active
                                    .map(
                                        (row) =>
                                            `${row.name} ${row.year}/${row.version}`,
                                    )
                                    .join(', ')}
                            </p>
                        ) : (
                            <p>Keine aktive Version dieses Jahres vorhanden.</p>
                        )}
                        {preview.unchanged_active_years &&
                        preview.unchanged_active_years.length > 0 ? (
                            <p data-test="price-list-unchanged-years">
                                Unveränderte aktive Jahre:{' '}
                                {preview.unchanged_active_years
                                    .map(
                                        (row) => `${row.year} (${row.version})`,
                                    )
                                    .join(', ')}
                            </p>
                        ) : null}
                        <p>
                            Historische Kalkulationspositionen:{' '}
                            {preview.calculation_positions_count ?? 0} ·
                            Dispopositionen:{' '}
                            {preview.dispo_order_positions_count ?? 0}
                        </p>
                        {preview.warnings?.map((warning) => (
                            <p
                                key={warning}
                                className="font-medium"
                                data-test="price-list-preview-warning"
                            >
                                {warning}
                            </p>
                        ))}
                        {preview.blocking_reasons.map((reason) => (
                            <p key={reason.code} className="text-destructive">
                                {reason.message}
                            </p>
                        ))}
                        {preview.can_proceed &&
                        preview.action === 'price_list_activate' ? (
                            <Button
                                disabled={busy}
                                onClick={() =>
                                    void confirmAction(
                                        routes.activate,
                                        'Preisliste veröffentlicht.',
                                    )
                                }
                                data-test="price-list-activate-confirm"
                            >
                                Veröffentlichen bestätigen
                            </Button>
                        ) : null}
                        {preview.can_proceed &&
                        preview.action === 'price_list_archive' ? (
                            <Button
                                disabled={busy}
                                onClick={() =>
                                    void confirmAction(
                                        routes.archive,
                                        'Preisliste archiviert.',
                                    )
                                }
                                data-test="price-list-archive-confirm"
                            >
                                Archivieren bestätigen
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </>
    );
}
