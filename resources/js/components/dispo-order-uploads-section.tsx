import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formatDateTime } from '@/lib/date-time';
import { formatFileSize } from '@/lib/format-file-size';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';
import type { DispoOrderUpload } from '@/types/dispo-order';

type ActionResponse = {
    message: string;
    redirect: string;
};

export function DispoOrderUploadsSection({
    orderId,
    lockVersion,
    uploads,
    canArchive = false,
}: {
    orderId: number;
    lockVersion: number;
    uploads: DispoOrderUpload[];
    canArchive?: boolean;
}) {
    const [archivingId, setArchivingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function archive(uploadId: number) {
        if (archivingId !== null) {
            return;
        }

        setArchivingId(uploadId);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/uploads/${uploadId}/archivieren`,
                { lock_version: lockVersion },
            );
            flushDispoOrderInertiaCache(orderId);
            router.visit(result.redirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : (firstValidationMessage(caught.fieldErrors) ??
                              caught.message),
                );
            } else {
                setError('Die Anfrage ist fehlgeschlagen.');
            }
            setArchivingId(null);
        }
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-uploads"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">Dateien</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {error ? (
                    <ErrorState
                        message={error}
                        data-test="dispo-order-uploads-error"
                    />
                ) : null}

                {uploads.length === 0 ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="dispo-order-uploads-empty"
                    >
                        Noch keine Dateien hinterlegt.
                    </p>
                ) : (
                    <ul className="divide-border divide-y">
                        {uploads.map((upload) => (
                            <li
                                key={upload.id}
                                className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"
                                data-test={`dispo-order-upload-${upload.id}`}
                            >
                                <div className="min-w-0 space-y-1 text-sm">
                                    <p className="font-medium">
                                        {upload.category_label}
                                    </p>
                                    <p className="truncate">
                                        {upload.original_filename}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {formatFileSize(upload.size_bytes)}
                                        {upload.uploaded_by_name
                                            ? ` · ${upload.uploaded_by_name}`
                                            : ''}
                                        {upload.uploaded_at
                                            ? ` · ${formatDateTime(upload.uploaded_at)}`
                                            : ''}
                                    </p>
                                    <p
                                        className="text-muted-foreground"
                                        data-test={`dispo-order-upload-status-${upload.id}`}
                                    >
                                        {upload.archived
                                            ? 'Archiviert'
                                            : 'Aktiv'}
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-wrap items-center gap-2">
                                    <a
                                        href={upload.download_url}
                                        className="text-primary text-sm underline-offset-4 hover:underline"
                                        data-test={`dispo-order-upload-download-${upload.id}`}
                                    >
                                        Herunterladen
                                    </a>
                                    {canArchive && !upload.archived ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            data-test="dispo-order-upload-archive"
                                            disabled={archivingId !== null}
                                            onClick={() =>
                                                void archive(upload.id)
                                            }
                                        >
                                            {archivingId === upload.id
                                                ? 'Wird archiviert…'
                                                : 'Archivieren'}
                                        </Button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
