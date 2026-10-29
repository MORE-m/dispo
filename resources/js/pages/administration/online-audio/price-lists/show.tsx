import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    buildMatrixRows,
    filledMatrixItems,
    firstApiError,
    matrixSnapshot,
    oaCsrfHeaders,
    oaMediumLabel,
    oaPairKey,
    oaStatusLabel,
    type OaFieldError,
    type OaPriceListItemRow,
    type OaPriceListSummary,
    type OaRequiredPair,
} from '@/lib/oa-tkp-admin';

type SiblingRevision = {
    id: number;
    revision_number: number;
    status: string;
    status_label?: string;
    name: string;
};

type Routes = {
    index: string;
    update: string;
    validate: string;
    activate: string;
    archive: string;
    history: string;
};

type ValidateResult = {
    valid?: boolean;
    complete?: boolean;
    errors?: OaFieldError[];
    missing_pairs?: string[];
    unexpected_pairs?: string[];
    items_count?: number;
    required_pairs?: number;
};

type ActivatePreview = {
    archived_previous_ids: number[];
    dry_run?: boolean;
};

type HistoryEvent = {
    id: number;
    action: string;
    user_id: number | null;
    old_values: unknown;
    new_values: unknown;
    correlation_id: string | null;
    created_at: string;
};

type ApiPriceListResponse = {
    message?: string;
    price_list?: OaPriceListSummary;
    lock_version?: number;
    errors?: string[] | Record<string, string[]>;
    field_errors?: OaFieldError[];
    result?: ActivatePreview;
};

function statusBadgeClass(status: string): string {
    switch (status) {
        case 'active':
            return 'bg-emerald-100 text-emerald-900';
        case 'archived':
            return 'bg-muted text-muted-foreground';
        default:
            return 'bg-amber-100 text-amber-900';
    }
}

function groupRowIndices(rows: OaPriceListItemRow[]): number[][] {
    const groups: number[][] = [];
    let currentMedium = '';
    let current: number[] = [];
    rows.forEach((row, index) => {
        if (row.medium_code !== currentMedium) {
            if (current.length > 0) {
                groups.push(current);
            }
            currentMedium = row.medium_code;
            current = [index];
        } else {
            current.push(index);
        }
    });
    if (current.length > 0) {
        groups.push(current);
    }

    return groups;
}

function fieldErrorsByPair(
    fieldErrors: OaFieldError[],
    filledItems: ReturnType<typeof filledMatrixItems>,
): Map<string, Partial<Record<'list_tkp' | 'minimum_tkp', string>>> {
    const map = new Map<
        string,
        Partial<Record<'list_tkp' | 'minimum_tkp', string>>
    >();
    for (const err of fieldErrors) {
        if (err.line == null || err.line < 1 || !err.field) {
            continue;
        }
        const item = filledItems[err.line - 1];
        if (!item) {
            continue;
        }
        if (err.field !== 'list_tkp' && err.field !== 'minimum_tkp') {
            continue;
        }
        const key = oaPairKey(item.medium_code, item.inventory_code);
        const existing = map.get(key) ?? {};
        existing[err.field] = err.message;
        map.set(key, existing);
    }

    return map;
}

export default function OaPriceListShow({
    priceList: initialPriceList,
    requiredPairs,
    siblingRevisions,
    routes,
}: {
    priceList: OaPriceListSummary;
    requiredPairs: OaRequiredPair[];
    siblingRevisions: SiblingRevision[];
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [current, setCurrent] = useState(initialPriceList);
    const [name, setName] = useState(initialPriceList.name);
    const [lockVersion, setLockVersion] = useState(
        initialPriceList.lock_version,
    );
    const [rows, setRows] = useState<OaPriceListItemRow[]>(() =>
        buildMatrixRows(requiredPairs, initialPriceList.items ?? []),
    );
    const [savedSnapshot, setSavedSnapshot] = useState(() =>
        JSON.stringify({
            name: initialPriceList.name,
            matrix: matrixSnapshot(
                buildMatrixRows(requiredPairs, initialPriceList.items ?? []),
            ),
        }),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ?? null,
    );
    const [validateReport, setValidateReport] = useState<ValidateResult | null>(
        null,
    );
    const [activatePreview, setActivatePreview] =
        useState<ActivatePreview | null>(null);
    const [fieldErrors, setFieldErrors] = useState<
        Map<string, Partial<Record<'list_tkp' | 'minimum_tkp', string>>>
    >(new Map());
    const [historyOpen, setHistoryOpen] = useState(false);
    const [historyEvents, setHistoryEvents] = useState<HistoryEvent[] | null>(
        null,
    );
    const [historyLoading, setHistoryLoading] = useState(false);

    const editable = current.editable ?? current.status === 'draft';
    const statusLabel = current.status_label ?? oaStatusLabel(current.status);

    const dirty = useMemo(() => {
        const snapshot = JSON.stringify({
            name,
            matrix: matrixSnapshot(rows),
        });

        return snapshot !== savedSnapshot;
    }, [name, rows, savedSnapshot]);

    useEffect(() => {
        function onBeforeUnload(event: BeforeUnloadEvent) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        }
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    useEffect(() => {
        setCurrent(initialPriceList);
        setName(initialPriceList.name);
        setLockVersion(initialPriceList.lock_version);
        const nextRows = buildMatrixRows(
            requiredPairs,
            initialPriceList.items ?? [],
        );
        setRows(nextRows);
        setSavedSnapshot(
            JSON.stringify({
                name: initialPriceList.name,
                matrix: matrixSnapshot(nextRows),
            }),
        );
        setValidateReport(null);
        setActivatePreview(null);
        setFieldErrors(new Map());
        setError(null);
    }, [initialPriceList.id, requiredPairs, initialPriceList]);

    function applyDetail(detail: OaPriceListSummary, nextLock?: number) {
        setCurrent(detail);
        setName(detail.name);
        if (nextLock !== undefined) {
            setLockVersion(nextLock);
        } else {
            setLockVersion(detail.lock_version);
        }
        const nextRows = buildMatrixRows(requiredPairs, detail.items ?? []);
        setRows(nextRows);
        setSavedSnapshot(
            JSON.stringify({
                name: detail.name,
                matrix: matrixSnapshot(nextRows),
            }),
        );
        setFieldErrors(new Map());
    }

    function updateCell(
        index: number,
        field: 'list_tkp' | 'minimum_tkp',
        value: string,
    ) {
        setRows((prev) =>
            prev.map((row, i) =>
                i === index ? { ...row, [field]: value } : row,
            ),
        );
        const row = rows[index];
        if (row) {
            const key = oaPairKey(row.medium_code, row.inventory_code);
            setFieldErrors((prev) => {
                const next = new Map(prev);
                const existing = next.get(key);
                if (existing?.[field]) {
                    const copy = { ...existing };
                    delete copy[field];
                    if (Object.keys(copy).length === 0) {
                        next.delete(key);
                    } else {
                        next.set(key, copy);
                    }
                }

                return next;
            });
        }
    }

    async function sendJson(
        method: 'PUT' | 'POST',
        url: string,
        body: Record<string, unknown>,
        onOk?: (result: ApiPriceListResponse) => void,
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
                headers: oaCsrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const result = (await response.json()) as ApiPriceListResponse;
            if (!response.ok) {
                if (result.field_errors && result.field_errors.length > 0) {
                    const filled = filledMatrixItems(rows);
                    setFieldErrors(
                        fieldErrorsByPair(result.field_errors, filled),
                    );
                }
                setError(
                    response.status === 409
                        ? `${result.message ?? 'Konflikt.'} Bitte Seite neu laden.`
                        : firstApiError(
                              result,
                              'Die Aktion ist fehlgeschlagen.',
                          ),
                );

                return;
            }
            if (onOk) {
                onOk(result);
            } else if (result.price_list) {
                applyDetail(
                    result.price_list,
                    result.lock_version ?? result.price_list.lock_version,
                );
                setSuccess(result.message ?? null);
            }
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');
        } finally {
            setBusy(false);
        }
    }

    function onSave() {
        if (!editable) {
            return;
        }
        const items = filledMatrixItems(rows);
        if (items.length === 0) {
            setError(
                'Bitte mindestens ein Paar mit Listen- und Mindest-TKP ausfüllen.',
            );

            return;
        }
        void sendJson('PUT', routes.update, {
            name: name.trim(),
            lock_version: lockVersion,
            items,
        });
    }

    function onValidate() {
        void sendJson(
            'POST',
            routes.validate,
            { require_complete: true },
            (result) => {
                const report = result as unknown as ValidateResult;
                setValidateReport(report);
                if (report.complete) {
                    setSuccess('Matrix vollständig und gültig.');
                } else {
                    setSuccess(null);
                }
            },
        );
    }

    function onActivateDryRun() {
        void sendJson(
            'POST',
            routes.activate,
            { lock_version: lockVersion, dry_run: true },
            (result) => {
                const preview = result.result;
                if (preview) {
                    setActivatePreview(preview);
                }
                setSuccess(result.message ?? 'Aktivierungs-Vorschau ok.');
            },
        );
    }

    function onActivateConfirm() {
        void sendJson(
            'POST',
            routes.activate,
            { lock_version: lockVersion, dry_run: false },
            (result) => {
                setActivatePreview(null);
                if (result.price_list) {
                    applyDetail(
                        result.price_list,
                        result.lock_version ?? result.price_list.lock_version,
                    );
                }
                setSuccess(result.message ?? 'Revision aktiviert.');
                router.reload({ only: ['priceList', 'siblingRevisions'] });
            },
        );
    }

    function onArchive() {
        if (!window.confirm('Diese OA-TKP-Revision wirklich archivieren?')) {
            return;
        }
        void sendJson(
            'POST',
            routes.archive,
            { lock_version: lockVersion },
            (result) => {
                if (result.price_list) {
                    applyDetail(
                        result.price_list,
                        result.lock_version ?? result.price_list.lock_version,
                    );
                }
                setSuccess(result.message ?? 'Revision archiviert.');
                router.reload({ only: ['priceList', 'siblingRevisions'] });
            },
        );
    }

    async function loadHistory() {
        if (historyLoading) {
            return;
        }
        setHistoryLoading(true);
        try {
            const response = await fetch(routes.history, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const result = (await response.json()) as {
                events?: HistoryEvent[];
            };
            if (response.ok) {
                setHistoryEvents(result.events ?? []);
                setHistoryOpen(true);
            } else {
                setError('Historie konnte nicht geladen werden.');
            }
        } catch {
            setError('Historie konnte nicht geladen werden.');
        } finally {
            setHistoryLoading(false);
        }
    }

    const matrixGroups = groupRowIndices(rows);

    return (
        <>
            <Head title={`OA-TKP ${current.year} · ${current.name}`} />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-price-list-show"
            >
                <PageHeader
                    title={`${current.year} · Rev. ${current.revision_number}`}
                    description={`Version ${current.version} · ${current.name}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={routes.index}>Zur Liste</Link>
                        </Button>
                    }
                />

                {dirty ? (
                    <div
                        className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                        data-test="oa-unsaved-banner"
                    >
                        Ungespeicherte Änderungen
                    </div>
                ) : null}

                {success ? <SuccessState message={success} /> : null}
                {error ? <ErrorState message={error} /> : null}

                <div className="flex flex-wrap items-center gap-3">
                    <span
                        className={`rounded-full px-3 py-1 text-xs font-medium ${statusBadgeClass(current.status)}`}
                    >
                        {statusLabel}
                    </span>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Revisionen:
                        </span>
                        {siblingRevisions.map((sibling) => (
                            <Link
                                key={sibling.id}
                                href={`/administration/online-audio/preislisten/${sibling.id}`}
                                className={`rounded-md border px-2 py-1 font-mono text-xs ${
                                    sibling.id === current.id
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-muted/50'
                                }`}
                            >
                                {sibling.revision_number} (
                                {sibling.status_label ??
                                    oaStatusLabel(sibling.status)}
                                )
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="max-w-xl space-y-4 rounded-xl border p-4">
                    <FormField label="Name" htmlFor="name">
                        <Input
                            id="name"
                            value={name}
                            disabled={!editable || busy}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </FormField>
                    <p className="text-muted-foreground text-sm">
                        {editable
                            ? 'Entwurf: Matrix und Name sind editierbar.'
                            : 'Veröffentlichte oder archivierte Revisionen sind schreibgeschützt.'}
                    </p>
                </div>

                <div className="max-h-[70vh] overflow-auto rounded-xl border">
                    <table
                        className="w-full text-left text-sm"
                        data-test="oa-price-list-matrix"
                    >
                        <thead className="bg-muted/40 sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Werbeform
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Inventar
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Listen-TKP (€)
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Mindest-TKP (€)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {matrixGroups.map((indices) => {
                                const mediumCode =
                                    rows[indices[0]]?.medium_code ?? '';
                                const mediumLabel = oaMediumLabel(
                                    mediumCode,
                                    rows[indices[0]]?.medium_name,
                                );

                                return (
                                    <Fragment key={`group-${mediumCode}`}>
                                        <tr className="bg-muted/20 border-t">
                                            <td
                                                colSpan={4}
                                                className="px-3 py-2 text-xs font-semibold tracking-wide uppercase"
                                            >
                                                {mediumLabel}
                                            </td>
                                        </tr>
                                        {indices.map((index) => {
                                            const row = rows[index];
                                            const pairKey = oaPairKey(
                                                row.medium_code,
                                                row.inventory_code,
                                            );
                                            const pairErrors =
                                                fieldErrors.get(pairKey);

                                            return (
                                                <tr
                                                    key={pairKey}
                                                    className="border-t"
                                                >
                                                    <td className="px-3 py-2">
                                                        {row.medium_name ??
                                                            oaMediumLabel(
                                                                row.medium_code,
                                                            )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.inventory_name ??
                                                            row.inventory_code}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {editable ? (
                                                            <FormField
                                                                label=""
                                                                htmlFor={`list-${pairKey}`}
                                                                error={
                                                                    pairErrors?.list_tkp
                                                                }
                                                            >
                                                                <Input
                                                                    id={`list-${pairKey}`}
                                                                    inputMode="decimal"
                                                                    className="font-mono"
                                                                    value={
                                                                        row.list_tkp
                                                                    }
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateCell(
                                                                            index,
                                                                            'list_tkp',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </FormField>
                                                        ) : (
                                                            <span className="font-mono">
                                                                {row.list_tkp ||
                                                                    '—'}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {editable ? (
                                                            <FormField
                                                                label=""
                                                                htmlFor={`min-${pairKey}`}
                                                                error={
                                                                    pairErrors?.minimum_tkp
                                                                }
                                                            >
                                                                <Input
                                                                    id={`min-${pairKey}`}
                                                                    inputMode="decimal"
                                                                    className="font-mono"
                                                                    value={
                                                                        row.minimum_tkp
                                                                    }
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateCell(
                                                                            index,
                                                                            'minimum_tkp',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </FormField>
                                                        ) : (
                                                            <span className="font-mono">
                                                                {row.minimum_tkp ||
                                                                    '—'}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {validateReport ? (
                    <div className="rounded-xl border p-4 text-sm">
                        <h3 className="font-semibold">Prüfergebnis</h3>
                        <p className="mt-1">
                            Vollständig:{' '}
                            {validateReport.complete ? 'Ja' : 'Nein'}
                            {validateReport.required_pairs != null
                                ? ` · ${validateReport.items_count ?? 0}/${validateReport.required_pairs} Paare`
                                : null}
                        </p>
                        {validateReport.missing_pairs &&
                        validateReport.missing_pairs.length > 0 ? (
                            <ul className="text-muted-foreground mt-2 list-disc pl-5">
                                {validateReport.missing_pairs.map((pair) => (
                                    <li key={pair}>Fehlt: {pair}</li>
                                ))}
                            </ul>
                        ) : null}
                        {validateReport.errors &&
                        validateReport.errors.length > 0 ? (
                            <ul className="mt-2 list-disc pl-5 text-red-800">
                                {validateReport.errors.map((err, i) => (
                                    <li key={`${err.message}-${i}`}>
                                        {err.message}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ) : null}

                {activatePreview ? (
                    <div
                        className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm"
                        data-test="oa-activate-confirm"
                    >
                        <p className="font-medium">Aktivierung bestätigen</p>
                        {activatePreview.archived_previous_ids.length > 0 ? (
                            <p className="mt-1">
                                Folgende aktive Revisionen werden archiviert:{' '}
                                {activatePreview.archived_previous_ids.join(
                                    ', ',
                                )}
                            </p>
                        ) : (
                            <p className="mt-1">
                                Keine weiteren aktiven Revisionen im selben
                                Jahr.
                            </p>
                        )}
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                disabled={busy || dirty}
                                data-test="oa-activate-confirm-submit"
                                onClick={onActivateConfirm}
                            >
                                Jetzt aktivieren
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                data-test="oa-activate-confirm-cancel"
                                onClick={() => setActivatePreview(null)}
                            >
                                Abbrechen
                            </Button>
                        </div>
                    </div>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {editable ? (
                        <Button
                            type="button"
                            disabled={busy || !dirty}
                            data-test="oa-price-list-save"
                            onClick={onSave}
                        >
                            Speichern
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={busy || dirty}
                        data-test="oa-price-list-validate"
                        onClick={onValidate}
                    >
                        Matrix prüfen
                    </Button>
                    {current.status === 'draft' ? (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={busy || dirty || activatePreview != null}
                            data-test="oa-price-list-activate"
                            onClick={onActivateDryRun}
                        >
                            Aktivieren…
                        </Button>
                    ) : null}
                    {current.status !== 'archived' ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy || dirty}
                            onClick={onArchive}
                        >
                            Archivieren
                        </Button>
                    ) : null}
                </div>

                <div className="rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-semibold">Historie</h3>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={historyLoading}
                            onClick={() => {
                                if (historyOpen && historyEvents) {
                                    setHistoryOpen(false);
                                } else {
                                    void loadHistory();
                                }
                            }}
                        >
                            {historyOpen ? 'Ausblenden' : 'Historie laden'}
                        </Button>
                    </div>
                    {historyOpen && historyEvents ? (
                        historyEvents.length === 0 ? (
                            <p className="text-muted-foreground mt-3 text-sm">
                                Keine Einträge.
                            </p>
                        ) : (
                            <ul className="mt-3 space-y-2 text-sm">
                                {historyEvents.map((event) => (
                                    <li
                                        key={event.id}
                                        className="border-t pt-2 first:border-t-0 first:pt-0"
                                    >
                                        <span className="font-mono text-xs">
                                            {new Date(
                                                event.created_at,
                                            ).toLocaleString('de-DE')}
                                        </span>
                                        <span className="ml-2">
                                            {event.action}
                                        </span>
                                        {event.user_id != null ? (
                                            <span className="text-muted-foreground ml-2">
                                                User #{event.user_id}
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )
                    ) : null}
                </div>
            </div>
        </>
    );
}
