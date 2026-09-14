import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    importConfirmEnabled,
    importIssuesBySeverity,
    type ImportPreview,
} from '@/lib/price-list-import';

type ImportMeta = {
    id: number;
    year: number;
    status: string;
    original_filename: string;
    checksum_sha256: string;
    fingerprint: string | null;
};

type CreatedList = {
    id: number;
    name: string;
    version: string;
    inventory_name: string | null;
    url: string;
};

function csrfToken(): string {
    const row = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));
    return row ? decodeURIComponent(row.slice(11)) : '';
}

export default function PriceListImportPage({
    currentYear,
    urls,
    contractNote,
}: {
    currentYear: number;
    urls: { upload: string; index: string };
    contractNote: string;
    limits: { max_bytes: number; extensions: string[] };
}) {
    const [year, setYear] = useState(String(currentYear));
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [importMeta, setImportMeta] = useState<ImportMeta | null>(null);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [confirmUrl, setConfirmUrl] = useState<string | null>(null);
    const [created, setCreated] = useState<CreatedList[]>([]);
    const inFlight = useRef(false);

    async function runUploadAndValidate() {
        if (inFlight.current || !file) {
            return;
        }
        inFlight.current = true;
        setBusy(true);
        setError(null);
        setCreated([]);
        try {
            const body = new FormData();
            body.append('year', year);
            body.append('file', file);
            const response = await fetch(urls.upload, {
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
                        data.errors?.year?.[0] ||
                        'Prüfung fehlgeschlagen.',
                );
            }
            setImportMeta(data.import);
            setPreview(data.preview);
            setConfirmUrl(data.urls.confirm);
        } catch (err) {
            setPreview(null);
            setImportMeta(null);
            setConfirmUrl(null);
            setError(
                err instanceof Error ? err.message : 'Unbekannter Fehler.',
            );
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    async function runConfirm() {
        if (inFlight.current || !preview || !confirmUrl) {
            return;
        }
        if (!importConfirmEnabled(preview, busy)) {
            return;
        }
        inFlight.current = true;
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(confirmUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ fingerprint: preview.fingerprint }),
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (response.status === 409) {
                throw new Error(
                    data.message ||
                        'Vorschau veraltet. Bitte Datei erneut prüfen.',
                );
            }
            if (!response.ok) {
                throw new Error(
                    data.message ||
                        data.errors?.import?.[0] ||
                        'Übernahme fehlgeschlagen.',
                );
            }
            setCreated(data.created ?? []);
            setImportMeta(data.import);
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
        ? importIssuesBySeverity(preview.issues, 'error')
        : [];
    const warnings = preview
        ? importIssuesBySeverity(preview.issues, 'warning')
        : [];

    return (
        <>
            <Head title="Preislisten importieren" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Excel-Import"
                    description="Datei prüfen, Vorschau bestätigen, Entwürfe anlegen. Keine automatische Aktivierung."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={urls.index}>Zur Liste</Link>
                        </Button>
                    }
                />

                <p className="text-muted-foreground max-w-3xl text-sm">
                    {contractNote}
                </p>

                <div
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="price-list-import-form"
                >
                    <FormField label="Kalenderjahr" htmlFor="import-year">
                        <Input
                            id="import-year"
                            type="number"
                            value={year}
                            readOnly={busy}
                            onChange={(event) => setYear(event.target.value)}
                            data-test="price-list-import-year"
                        />
                    </FormField>
                    <FormField
                        label="Excel-Datei (XLSX/XLS)"
                        htmlFor="import-file"
                    >
                        <Input
                            id="import-file"
                            type="file"
                            accept=".xlsx,.xls"
                            disabled={busy}
                            onChange={(event) =>
                                setFile(event.target.files?.[0] ?? null)
                            }
                            data-test="price-list-import-file"
                        />
                    </FormField>
                    <Button
                        type="button"
                        disabled={busy || !file}
                        onClick={() => void runUploadAndValidate()}
                        data-test="price-list-import-validate"
                    >
                        {busy ? 'Prüfe…' : 'Prüfen'}
                    </Button>
                </div>

                {error ? (
                    <p
                        className="text-sm text-red-700"
                        data-test="price-list-import-error"
                    >
                        {error}
                    </p>
                ) : null}

                {importMeta && preview ? (
                    <div
                        className="space-y-4"
                        data-test="price-list-import-preview"
                    >
                        <div className="rounded-xl border p-4 text-sm">
                            <p>
                                Datei:{' '}
                                <strong>{importMeta.original_filename}</strong>
                            </p>
                            <p className="font-mono text-xs break-all">
                                Prüfsumme: {importMeta.checksum_sha256}
                            </p>
                            <p>
                                Jahr {importMeta.year} · gültige Zeilen{' '}
                                {preview.valid_row_count} · Fehler{' '}
                                {preview.error_count} · Warnungen{' '}
                                {preview.warning_count}
                            </p>
                        </div>

                        {errors.length > 0 ? (
                            <div
                                className="rounded-xl border border-red-200 bg-red-50 p-4"
                                data-test="price-list-import-errors"
                            >
                                <h2 className="mb-2 font-medium">Fehler</h2>
                                <ul className="space-y-1 text-sm">
                                    {errors.map((issue, index) => (
                                        <li key={`${issue.code}-${index}`}>
                                            {issue.sheet
                                                ? `${issue.sheet} `
                                                : ''}
                                            {issue.row
                                                ? `Z.${issue.row}: `
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
                                        <li key={`${issue.code}-${index}`}>
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
                                        <th className="px-2 py-2">Inventar</th>
                                        <th className="px-2 py-2">Stunde</th>
                                        <th className="px-2 py-2">Gruppe</th>
                                        <th className="px-2 py-2">Preis</th>
                                        <th className="px-2 py-2">Sheet</th>
                                        <th className="px-2 py-2">Zeile</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.rows.map((row) => (
                                        <tr
                                            key={`${row.inventory_code}-${row.hour}-${row.day_group_label}-${row.source_row}`}
                                            className="border-t"
                                        >
                                            <td className="px-2 py-1">
                                                {row.inventory_name} (
                                                {row.inventory_code})
                                            </td>
                                            <td className="px-2 py-1 font-mono">
                                                {row.hour}
                                            </td>
                                            <td className="px-2 py-1">
                                                {row.day_group_label}
                                            </td>
                                            <td className="px-2 py-1 font-mono">
                                                {row.second_price}
                                            </td>
                                            <td className="px-2 py-1">
                                                {row.sheet}
                                            </td>
                                            <td className="px-2 py-1">
                                                {row.source_row}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Button
                            type="button"
                            disabled={!importConfirmEnabled(preview, busy)}
                            onClick={() => void runConfirm()}
                            data-test="price-list-import-confirm"
                        >
                            {busy ? 'Importiere…' : 'Import bestätigen'}
                        </Button>
                    </div>
                ) : null}

                {created.length > 0 ? (
                    <div
                        className="rounded-xl border border-green-200 bg-green-50 p-4"
                        data-test="price-list-import-result"
                    >
                        <h2 className="mb-2 font-medium">Entwürfe angelegt</h2>
                        <ul className="space-y-1 text-sm">
                            {created.map((list) => (
                                <li key={list.id}>
                                    <Link
                                        href={list.url}
                                        className="underline"
                                        data-test={`price-list-import-created-${list.id}`}
                                    >
                                        {list.inventory_name} · {list.name} · v
                                        {list.version}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Bitte über den bestehenden Preislisten-Lifecycle
                            veröffentlichen.
                        </p>
                    </div>
                ) : null}
            </div>
        </>
    );
}
