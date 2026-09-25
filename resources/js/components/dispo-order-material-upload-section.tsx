import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formDataPost, JsonPostError } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export type MaterialUploadCategoryOption = {
    value: string;
    label: string;
};

type ActionResponse = {
    message: string;
    redirect: string;
};

type PartialFailure = {
    name: string;
    message: string;
};

type MixedUploadReport = {
    successes: string[];
    failures: PartialFailure[];
};

const SHOW_RELOAD_ONLY = [
    'order',
    'uploads',
    'canUploadMaterial',
    'materialUploadCategories',
    'canArchiveUpload',
    'activeCustomerConfirmationUpload',
] as const;

function failureMessage(caught: unknown): string {
    if (caught instanceof JsonPostError) {
        return caught.isConflict
            ? caught.message
            : (firstValidationMessage(caught.fieldErrors) ?? caught.message);
    }

    return 'Die Anfrage ist fehlgeschlagen.';
}

export function DispoOrderMaterialUploadSection({
    orderId,
    lockVersion,
    categories,
}: {
    orderId: number;
    lockVersion: number;
    categories: MaterialUploadCategoryOption[];
}) {
    const [category, setCategory] = useState(categories[0]?.value ?? '');
    const [files, setFiles] = useState<File[]>([]);
    const [fileInputKey, setFileInputKey] = useState(0);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [mixedReport, setMixedReport] = useState<MixedUploadReport | null>(
        null,
    );
    const categoryId = useId();
    const fileInputId = useId();

    const isAudio = category === 'audio_motif';
    const hint = isAudio
        ? 'MP3/WAV, maximal 50 MB pro Datei'
        : 'Maximal 50 MB pro Datei';
    const accept = isAudio
        ? '.mp3,.wav,audio/mpeg,audio/wav,audio/x-wav'
        : undefined;
    const multiple = isAudio;

    function clearReport() {
        setMixedReport(null);
        setError(null);
    }

    async function upload() {
        if (uploading || category === '' || files.length === 0) {
            return;
        }

        setUploading(true);
        setError(null);

        let currentLockVersion = lockVersion;
        const failures: PartialFailure[] = [];
        const successes: string[] = [];
        const failedFiles: File[] = [];
        let lastRedirect: string | null = null;

        for (const file of files) {
            try {
                const body = new FormData();
                body.append('category', category);
                body.append('file', file);
                body.append('lock_version', String(currentLockVersion));

                const result = await formDataPost<ActionResponse>(
                    `/dispoauftraege/${orderId}/uploads`,
                    body,
                );
                successes.push(file.name);
                currentLockVersion += 1;
                lastRedirect = result.redirect;
            } catch (caught) {
                failures.push({
                    name: file.name,
                    message: failureMessage(caught),
                });
                failedFiles.push(file);
            }
        }

        const hasSuccess = successes.length > 0;
        const hasFailure = failures.length > 0;

        if (hasSuccess && hasFailure) {
            // Gemischter Erfolg: Liste aktualisieren, Bericht behalten,
            // nur fehlgeschlagene Dateien für Retry behalten (kein Doppel-Upload).
            setMixedReport({ successes, failures });
            setError('Einige Dateien konnten nicht hochgeladen werden.');
            setFiles(failedFiles);
            setFileInputKey((key) => key + 1);
            setUploading(false);
            flushDispoOrderInertiaCache(orderId);
            router.reload({
                only: [...SHOW_RELOAD_ONLY],
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
            return;
        }

        if (hasSuccess && lastRedirect !== null) {
            clearReport();
            setFiles([]);
            setFileInputKey((key) => key + 1);
            flushDispoOrderInertiaCache(orderId);
            router.visit(lastRedirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
            return;
        }

        setMixedReport(null);
        setFiles(failedFiles);
        setFileInputKey((key) => key + 1);
        if (failures.length > 0) {
            setError(
                failures.length === 1
                    ? failures[0].message
                    : 'Einige Dateien konnten nicht hochgeladen werden.',
            );
            setMixedReport({ successes: [], failures });
        }
        setUploading(false);
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-material-upload"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Material hochladen
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {error ? (
                    <ErrorState
                        message={error}
                        data-test="dispo-order-material-upload-error"
                    />
                ) : null}

                {mixedReport !== null ? (
                    <div
                        className="space-y-2 text-sm"
                        data-test="dispo-order-material-upload-mixed-report"
                    >
                        {mixedReport.successes.length > 0 ? (
                            <div data-test="dispo-order-material-upload-successes">
                                <p className="font-medium text-emerald-700 dark:text-emerald-400">
                                    Erfolgreich hochgeladen
                                </p>
                                <ul className="mt-1 list-inside list-disc space-y-1">
                                    {mixedReport.successes.map((name) => (
                                        <li
                                            key={`ok-${name}`}
                                            data-test="dispo-order-material-upload-success-item"
                                        >
                                            {name}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        {mixedReport.failures.length > 0 ? (
                            <div data-test="dispo-order-material-upload-partial-failures">
                                <p className="text-destructive font-medium">
                                    Fehlgeschlagen
                                </p>
                                <ul className="text-destructive mt-1 list-inside list-disc space-y-1">
                                    {mixedReport.failures.map((failure) => (
                                        <li
                                            key={`${failure.name}-${failure.message}`}
                                            data-test="dispo-order-material-upload-failure-item"
                                        >
                                            {failure.name}: {failure.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        {files.length > 0 && mixedReport.failures.length > 0 ? (
                            <p
                                className="text-muted-foreground text-xs"
                                data-test="dispo-order-material-upload-retry-hint"
                            >
                                Erneut hochladen überträgt nur die
                                fehlgeschlagenen Dateien (lock_version wird
                                fortgeschrieben).
                            </p>
                        ) : null}
                    </div>
                ) : null}

                <div className="space-y-2">
                    <Label htmlFor={categoryId}>Kategorie</Label>
                    <select
                        id={categoryId}
                        className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-hidden"
                        value={category}
                        data-test="dispo-order-material-upload-category"
                        onChange={(event) => {
                            setCategory(event.target.value);
                            setFiles([]);
                            setFileInputKey((key) => key + 1);
                            clearReport();
                        }}
                    >
                        {categories.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-2">
                    <Label htmlFor={fileInputId}>Datei</Label>
                    <Input
                        key={fileInputKey}
                        id={fileInputId}
                        type="file"
                        accept={accept}
                        multiple={multiple}
                        data-test="dispo-order-material-upload-file"
                        onChange={(event) => {
                            const list = event.target.files;
                            setFiles(list ? Array.from(list) : []);
                            clearReport();
                        }}
                    />
                    {files.length > 0 ? (
                        <ul
                            className="text-muted-foreground list-inside list-disc text-xs"
                            data-test="dispo-order-material-upload-pending-files"
                        >
                            {files.map((file) => (
                                <li key={`${file.name}-${file.size}`}>
                                    {file.name}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <p
                        className="text-muted-foreground text-xs"
                        data-test="dispo-order-material-upload-hint"
                    >
                        {hint}
                    </p>
                </div>

                <Button
                    type="button"
                    data-test="dispo-order-material-upload-submit"
                    disabled={
                        uploading || category === '' || files.length === 0
                    }
                    onClick={() => void upload()}
                >
                    {uploading ? 'Wird hochgeladen…' : 'Hochladen'}
                </Button>
            </CardContent>
        </Card>
    );
}
