import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
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

type Category = {
    id: number;
    key: string;
    name: string;
    sort: number;
    is_active: boolean;
    status_label: string;
    lock_version: number;
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
    routes,
}: {
    category: Category;
    routes: Routes;
}) {
    const flash = usePage().props.flash;
    const [name, setName] = useState(category.name);
    const [sort, setSort] = useState(category.sort);
    const [lockVersion, setLockVersion] = useState(category.lock_version);
    const [isActive, setIsActive] = useState(category.is_active);
    const [statusLabel, setStatusLabel] = useState(category.status_label);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(
        flash.success ? String(flash.success) : null,
    );
    const [preview, setPreview] = useState<ImpactPreview | null>(null);

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
                {success ? <SuccessState message={success} /> : null}

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
