import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
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

type CategoryAssignment = {
    assignment_id: number;
    category_id: number;
    category_key: string;
    category_name: string;
    is_active: boolean;
};

type MediumAssignment = {
    assignment_id: number;
    medium_id: number;
    medium_code: string;
    medium_name: string;
    is_active: boolean;
};

type MethodDetail = {
    id: number;
    key: string;
    name: string;
    help_text: string | null;
    sort: number;
    is_active: boolean;
    status_label: string;
    registry_pairs: RegistryPair[];
    registry_summary: string;
    active_category_assignments: CategoryAssignment[];
    active_medium_assignments: MediumAssignment[];
    active_category_assignments_count: number;
    active_medium_assignments_count: number;
    lock_version: number;
};

type Routes = {
    index: string;
    update: string;
    deactivatePreview: string;
    deactivate: string;
    reactivate: string;
};

type ImpactPreview = {
    fingerprint: string;
    lock_version: number;
    can_proceed: boolean;
    blocking_reasons: Array<{ code: string; message: string }>;
    active_category_assignments: CategoryAssignment[];
    active_medium_assignments: MediumAssignment[];
    dependency_note: string;
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

export default function MethodShow({
    method,
    routes,
    boundaryNote,
}: {
    method: MethodDetail;
    routes: Routes;
    boundaryNote: string;
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(method.name);
    const [helpText, setHelpText] = useState(method.help_text ?? '');
    const [sort, setSort] = useState(method.sort);
    const [lockVersion, setLockVersion] = useState(method.lock_version);
    const [isActive, setIsActive] = useState(method.is_active);
    const [statusLabel, setStatusLabel] = useState(method.status_label);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ? String(flash.success) : null,
    );
    const [preview, setPreview] = useState<ImpactPreview | null>(null);
    const dialogRef = useRef<HTMLDivElement | null>(null);
    const confirmRef = useRef<HTMLButtonElement | null>(null);

    useEffect(() => {
        if (preview && confirmRef.current) {
            confirmRef.current.focus();
        } else if (preview && dialogRef.current) {
            dialogRef.current.focus();
        }
    }, [preview]);

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
                    help_text: helpText,
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
                        Object.values(data.errors ?? {})
                            .flat()
                            .at(0) ||
                        'Speichern fehlgeschlagen.',
                );
                return;
            }
            setLockVersion(data.lock_version ?? lockVersion + 1);
            if (data.method?.name) {
                setName(data.method.name);
            }
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
        if (!preview || !preview.can_proceed) {
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
                setError(
                    data.message ||
                        data.errors?.method?.[0] ||
                        'Deaktivierung fehlgeschlagen.',
                );
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
                setError(
                    data.message ||
                        data.errors?.method?.[0] ||
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

    return (
        <>
            <Head title={method.name} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={method.name}
                    description={`Key ${method.key} (unveränderlich) · Sperrversion ${lockVersion}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={routes.index}
                                    data-test="method-back-link"
                                >
                                    Zur Liste
                                </Link>
                            </Button>
                            {isActive ? (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={loadDeactivatePreview}
                                    data-test="method-deactivate-preview-button"
                                >
                                    Deaktivieren…
                                </Button>
                            ) : (
                                <Button
                                    disabled={busy}
                                    onClick={reactivate}
                                    data-test="method-reactivate-button"
                                >
                                    Reaktivieren
                                </Button>
                            )}
                        </div>
                    }
                />

                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="method-boundary-note"
                >
                    {boundaryNote}
                </p>

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <span data-test="method-status-badge">
                        Stammdaten: {statusLabel}
                    </span>
                    <span
                        className="text-muted-foreground"
                        data-test="method-registry-badge"
                    >
                        Registry: {method.registry_summary}
                    </span>
                    <span className="text-muted-foreground">
                        Aktive Zuordnungen: Kat.{' '}
                        {method.active_category_assignments_count} / Med.{' '}
                        {method.active_medium_assignments_count}
                    </span>
                </div>

                {error ? (
                    <ErrorState message={error} data-test="method-show-error" />
                ) : null}
                {success ? <SuccessState message={success} /> : null}

                <form
                    className="grid max-w-xl gap-4 rounded-xl border p-4"
                    onSubmit={saveMetadata}
                    data-test="method-edit-form"
                >
                    <FormField label="Name" htmlFor="method-name">
                        <Input
                            id="method-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            data-test="method-name-input"
                        />
                    </FormField>
                    <FormField label="Technischer Key" htmlFor="method-key">
                        <Input
                            id="method-key"
                            value={method.key}
                            disabled
                            className="font-mono"
                            data-test="method-key-readonly"
                        />
                    </FormField>
                    <FormField label="Hilfetext" htmlFor="method-help">
                        <textarea
                            id="method-help"
                            className="border-input bg-background min-h-24 w-full rounded-md border px-3 py-2 text-sm"
                            value={helpText}
                            onChange={(e) => setHelpText(e.target.value)}
                            data-test="method-help-input"
                        />
                    </FormField>
                    <FormField label="Sortierung" htmlFor="method-sort">
                        <Input
                            id="method-sort"
                            type="number"
                            min={0}
                            value={sort}
                            onChange={(e) =>
                                setSort(Number(e.target.value || 0))
                            }
                            data-test="method-sort-input"
                        />
                    </FormField>
                    <Button
                        type="submit"
                        disabled={busy}
                        data-test="method-save-button"
                    >
                        Speichern
                    </Button>
                </form>

                <section
                    className="max-w-3xl space-y-2 rounded-xl border p-4 text-sm"
                    data-test="method-registry-section"
                >
                    <h2 className="font-semibold">
                        Technische Registry-Paare (read-only)
                    </h2>
                    {method.registry_pairs.length === 0 ? (
                        <p data-test="method-registry-empty">
                            Keinem technischen Profil zugeordnet
                        </p>
                    ) : (
                        <ul className="space-y-1">
                            {method.registry_pairs.map((pair) => (
                                <li
                                    key={pair.engine_profile_key}
                                    data-test={`method-registry-pair-${pair.engine_profile_key}`}
                                >
                                    Profil{' '}
                                    <span className="font-mono">
                                        {pair.engine_profile_key}
                                    </span>
                                    : {pair.pair_status}
                                    {pair.current_released_version
                                        ? ` · Version ${pair.current_released_version}`
                                        : ' · keine freigegebene Version'}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    className="max-w-3xl space-y-2 rounded-xl border p-4 text-sm"
                    data-test="method-assignments-section"
                >
                    <h2 className="font-semibold">
                        Aktive fachliche Zuordnungen (read-only)
                    </h2>
                    <div>
                        <h3 className="font-medium">Kategorien</h3>
                        {method.active_category_assignments.length === 0 ? (
                            <p className="text-muted-foreground">Keine</p>
                        ) : (
                            <ul>
                                {method.active_category_assignments.map(
                                    (row) => (
                                        <li key={row.assignment_id}>
                                            {row.category_name} (
                                            {row.category_key})
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </div>
                    <div>
                        <h3 className="font-medium">Werbemittel</h3>
                        {method.active_medium_assignments.length === 0 ? (
                            <p className="text-muted-foreground">Keine</p>
                        ) : (
                            <>
                                <p
                                    className="text-muted-foreground mb-2"
                                    data-test="method-medium-assignment-inherit-note"
                                >
                                    Diese Methode wird in einer gespeicherten
                                    Werbemittel-Konfiguration verwendet. Die
                                    Zuordnung kann auch bei Vererbung
                                    gespeichert sein.
                                </p>
                                <ul>
                                    {method.active_medium_assignments.map(
                                        (row) => (
                                            <li key={row.assignment_id}>
                                                {row.medium_name} (
                                                {row.medium_code})
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </>
                        )}
                    </div>
                </section>

                {preview ? (
                    <div
                        ref={dialogRef}
                        tabIndex={-1}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="method-deactivate-title"
                        className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
                        data-test="method-deactivate-preview"
                    >
                        <h2
                            id="method-deactivate-title"
                            className="font-semibold"
                        >
                            Deaktivierungsvorschau
                        </h2>
                        <p>{preview.dependency_note}</p>
                        {preview.blocking_reasons.map((reason) => (
                            <p key={reason.code} role="alert">
                                {reason.message}
                            </p>
                        ))}
                        {preview.active_category_assignments.length > 0 ? (
                            <div>
                                <h3 className="font-medium">
                                    Aktive Kategoriezuordnungen
                                </h3>
                                <ul data-test="method-preview-categories">
                                    {preview.active_category_assignments.map(
                                        (row) => (
                                            <li key={row.assignment_id}>
                                                {row.category_name} (
                                                {row.category_key})
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : null}
                        {preview.active_medium_assignments.length > 0 ? (
                            <div>
                                <h3 className="font-medium">
                                    Aktive Mediumzuordnungen
                                </h3>
                                <ul data-test="method-preview-media">
                                    {preview.active_medium_assignments.map(
                                        (row) => (
                                            <li key={row.assignment_id}>
                                                {row.medium_name} (
                                                {row.medium_code})
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setPreview(null)}
                                data-test="method-deactivate-cancel"
                            >
                                Abbrechen
                            </Button>
                            <Button
                                ref={confirmRef}
                                disabled={busy || !preview.can_proceed}
                                onClick={confirmDeactivate}
                                data-test="method-deactivate-confirm"
                            >
                                Deaktivierung bestätigen
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>
        </>
    );
}
