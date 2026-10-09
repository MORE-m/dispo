import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    crmImportApplyEnabled,
    crmImportIssuesBySeverity,
    type CrmImportMeta,
    type CrmImportPreview,
} from '@/lib/crm-import';

function csrfToken(): string {
    const row = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));
    return row ? decodeURIComponent(row.slice(11)) : '';
}

export default function CrmImportPage({
    notes,
}: {
    notes: {
        format: string;
        types: string;
        addresses: string;
    };
}) {
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [importMeta, setImportMeta] = useState<CrmImportMeta | null>(null);
    const [preview, setPreview] = useState<CrmImportPreview | null>(null);
    const [applied, setApplied] = useState(false);
    const inFlight = useRef(false);

    async function runUpload() {
        if (inFlight.current || !file) {
            return;
        }
        inFlight.current = true;
        setBusy(true);
        setError(null);
        setApplied(false);
        try {
            const body = new FormData();
            body.append('file', file);
            const response = await fetch('/administration/crm/import', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body,
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(
                    data.message ||
                        data.errors?.file?.[0] ||
                        'Upload fehlgeschlagen.',
                );
            }
            const meta = data.import as CrmImportMeta;
            setImportMeta(meta);
            setPreview(meta.preview);
        } catch (err) {
            setPreview(null);
            setImportMeta(null);
            setError(
                err instanceof Error ? err.message : 'Unbekannter Fehler.',
            );
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    async function runApply() {
        if (inFlight.current || !importMeta || !preview) {
            return;
        }
        if (!crmImportApplyEnabled(importMeta, busy)) {
            return;
        }
        if (!importMeta.catalog_fingerprint || !preview.fingerprint) {
            return;
        }
        inFlight.current = true;
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(
                `/administration/crm/import/${importMeta.id}/anwenden`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        fingerprint: preview.fingerprint,
                        catalog_fingerprint: importMeta.catalog_fingerprint,
                    }),
                    credentials: 'same-origin',
                },
            );
            const data = await response.json();
            if (response.status === 409) {
                if (data.import) {
                    const refreshed = data.import as CrmImportMeta;
                    setImportMeta(refreshed);
                    setPreview(refreshed.preview);
                }
                throw new Error(
                    data.message ||
                        'Bestand oder Vorschau hat sich geändert. Bitte erneut prüfen.',
                );
            }
            if (!response.ok) {
                throw new Error(
                    data.message ||
                        data.errors?.import?.[0] ||
                        'Anwenden fehlgeschlagen.',
                );
            }
            const meta = data.import as CrmImportMeta;
            setImportMeta(meta);
            setPreview(meta.preview);
            setApplied(true);
        } catch (err) {
            setError(
                err instanceof Error ? err.message : 'Unbekannter Fehler.',
            );
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    const errors = preview
        ? crmImportIssuesBySeverity(preview.issues, 'error')
        : [];
    const warnings = preview
        ? crmImportIssuesBySeverity(preview.issues, 'warning')
        : [];

    return (
        <>
            <Head title="CRM-Import" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Salesforce-CSV importieren"
                    description="CSV prüfen, Vorschau anwenden, Accounts und Meridian-IDs aktualisieren."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration">Administration</Link>
                        </Button>
                    }
                />

                <ul className="text-muted-foreground max-w-3xl list-disc space-y-1 pl-5 text-sm">
                    <li>{notes.format}</li>
                    <li>{notes.types}</li>
                    <li>{notes.addresses}</li>
                </ul>

                <div className="max-w-xl space-y-4 rounded-xl border p-4">
                    <FormField label="CSV-Datei" htmlFor="crm-import-file">
                        <Input
                            id="crm-import-file"
                            type="file"
                            accept=".csv"
                            disabled={busy}
                            onChange={(event) =>
                                setFile(event.target.files?.[0] ?? null)
                            }
                            data-test="crm-import-file"
                        />
                    </FormField>
                    <Button
                        type="button"
                        disabled={busy || !file}
                        onClick={() => void runUpload()}
                        data-test="crm-import-upload"
                    >
                        {busy ? 'Prüfe…' : 'Hochladen und prüfen'}
                    </Button>
                </div>

                {error ? <p className="text-sm text-red-700">{error}</p> : null}

                {importMeta && preview ? (
                    <div className="space-y-4" data-test="crm-import-preview">
                        <div className="rounded-xl border p-4 text-sm">
                            <p>
                                Datei:{' '}
                                <strong>{importMeta.original_filename}</strong>
                            </p>
                            <p className="font-mono text-xs break-all">
                                Prüfsumme: {importMeta.checksum_sha256}
                            </p>
                            <p>
                                Status: {importMeta.status} · gültige Zeilen{' '}
                                {preview.stats.valid_rows} · Fehlerzeilen{' '}
                                {preview.stats.error_rows} · Warnungen{' '}
                                {preview.stats.warning_rows} · Aktionen{' '}
                                {preview.stats.action_count}
                            </p>
                            <p className="text-muted-foreground">
                                Geplant: Neu {preview.stats.create_count ?? 0} ·
                                Unverändert {preview.stats.unchanged_count ?? 0}{' '}
                                · Neue Version{' '}
                                {preview.stats.new_version_count ?? 0} ·
                                Konflikteffekte{' '}
                                {preview.stats.conflict_effect_count ?? 0} ·
                                Auto-Zuordnung{' '}
                                {preview.stats.planned_auto_match_count ?? 0} ·
                                Mehrdeutig{' '}
                                {preview.stats.planned_ambiguous_count ?? 0}
                            </p>
                            {preview.stats.blocking_errors > 0 ? (
                                <p className="text-red-700">
                                    Blockierende Fehler:{' '}
                                    {preview.stats.blocking_errors}
                                </p>
                            ) : null}
                        </div>

                        {errors.length > 0 ? (
                            <div className="rounded-xl border border-red-200 bg-red-50 p-4">
                                <h2 className="mb-2 font-medium">Fehler</h2>
                                <ul className="space-y-1 text-sm">
                                    {errors.map((issue, index) => (
                                        <li key={`e-${index}`}>
                                            {issue.line
                                                ? `Z.${issue.line}: `
                                                : ''}
                                            {issue.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        {warnings.length > 0 ? (
                            <div className="rounded-xl border p-4 text-sm">
                                <h2 className="mb-2 font-medium">Warnungen</h2>
                                <ul className="space-y-1">
                                    {warnings.map((issue, index) => (
                                        <li key={`w-${index}`}>
                                            {issue.line
                                                ? `Z.${issue.line}: `
                                                : ''}
                                            {issue.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-2 py-2">Zeile</th>
                                        <th className="px-2 py-2">Wirkung</th>
                                        <th className="px-2 py-2">Typ</th>
                                        <th className="px-2 py-2">Name</th>
                                        <th className="px-2 py-2">
                                            Salesforce-ID
                                        </th>
                                        <th className="px-2 py-2">Meridian</th>
                                        <th className="px-2 py-2">E-Mail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.actions.map((action) => (
                                        <tr
                                            key={`${action.line}-${action.salesforce.canonical}`}
                                            className="border-t"
                                            data-test={`crm-preview-action-${action.line}`}
                                        >
                                            <td className="px-2 py-1">
                                                {action.line}
                                            </td>
                                            <td className="px-2 py-1">
                                                {action.effect_label ??
                                                    action.effect ??
                                                    '–'}
                                            </td>
                                            <td className="px-2 py-1">
                                                {action.type}
                                            </td>
                                            <td className="px-2 py-1">
                                                {action.name}
                                            </td>
                                            <td className="px-2 py-1 font-mono text-xs">
                                                {action.salesforce.raw}
                                            </td>
                                            <td className="px-2 py-1">
                                                {action.meridian_number ??
                                                    (action.meridian_kept
                                                        ? 'beibehalten'
                                                        : 'Meridian-Nummer folgt')}
                                            </td>
                                            <td className="px-2 py-1">
                                                {action.billing_email ?? '–'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {(preview.planned_auto_matches?.length ?? 0) > 0 ? (
                            <div
                                className="rounded-xl border p-4 text-sm"
                                data-test="crm-import-planned-matches"
                            >
                                <h2 className="mb-2 font-medium">
                                    Geplante Zuordnungen (Bestand)
                                </h2>
                                <ul className="space-y-1">
                                    {preview.planned_auto_matches?.map(
                                        (match, index) => (
                                            <li key={`m-${index}`}>
                                                {String(
                                                    match.provisional_name ??
                                                        match.provisional_id,
                                                )}{' '}
                                                · {String(match.domain)} ·{' '}
                                                {String(match.mode)}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : null}

                        <Button
                            type="button"
                            disabled={!crmImportApplyEnabled(importMeta, busy)}
                            onClick={() => void runApply()}
                            data-test="crm-import-apply"
                        >
                            {busy ? 'Wende an…' : 'Import anwenden'}
                        </Button>
                    </div>
                ) : null}

                {applied ? (
                    <div
                        className="space-y-2 rounded-xl border border-green-200 bg-green-50 p-4 text-sm"
                        data-test="crm-import-applied"
                    >
                        <p>Import wurde angewendet.</p>
                        {importMeta?.applied_at ? (
                            <p className="text-muted-foreground">
                                {importMeta.applied_at}
                            </p>
                        ) : null}
                        {importMeta?.report ? (
                            <pre
                                className="bg-background/70 overflow-x-auto rounded-md border p-2 text-xs"
                                data-test="crm-import-report"
                            >
                                {JSON.stringify(importMeta.report, null, 2)}
                            </pre>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </>
    );
}
