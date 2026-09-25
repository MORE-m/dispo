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
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [partialFailures, setPartialFailures] = useState<PartialFailure[]>(
        [],
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

    async function upload() {
        if (uploading || category === '' || files.length === 0) {
            return;
        }

        setUploading(true);
        setError(null);
        setPartialFailures([]);

        let currentLockVersion = lockVersion;
        const failures: PartialFailure[] = [];
        let successCount = 0;
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
                successCount += 1;
                currentLockVersion += 1;
                lastRedirect = result.redirect;
            } catch (caught) {
                if (caught instanceof JsonPostError) {
                    failures.push({
                        name: file.name,
                        message: caught.isConflict
                            ? caught.message
                            : (firstValidationMessage(caught.fieldErrors) ??
                              caught.message),
                    });
                } else {
                    failures.push({
                        name: file.name,
                        message: 'Die Anfrage ist fehlgeschlagen.',
                    });
                }
            }
        }

        if (successCount > 0 && lastRedirect !== null) {
            flushDispoOrderInertiaCache(orderId);
            router.visit(lastRedirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
            return;
        }

        setPartialFailures(failures);
        if (failures.length > 0) {
            setError(
                failures.length === 1
                    ? failures[0].message
                    : 'Einige Dateien konnten nicht hochgeladen werden.',
            );
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

                {partialFailures.length > 1 ? (
                    <ul
                        className="text-destructive space-y-1 text-sm"
                        data-test="dispo-order-material-upload-partial-failures"
                    >
                        {partialFailures.map((failure) => (
                            <li key={`${failure.name}-${failure.message}`}>
                                {failure.name}: {failure.message}
                            </li>
                        ))}
                    </ul>
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
                        id={fileInputId}
                        type="file"
                        accept={accept}
                        multiple={multiple}
                        data-test="dispo-order-material-upload-file"
                        onChange={(event) => {
                            const list = event.target.files;
                            setFiles(list ? Array.from(list) : []);
                        }}
                    />
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
