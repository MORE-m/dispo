import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formatDateTime } from '@/lib/date-time';
import { formatFileSize } from '@/lib/format-file-size';
import { formDataPost, JsonPostError, jsonPut } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';
import type { DispoOrderUpload } from '@/types/dispo-order';

type ActionResponse = {
    message: string;
    redirect: string;
};

export function DispoOrderCustomerConfirmationSection({
    orderId,
    lockVersion,
    status,
    canEdit,
    canUpload = false,
    withoutUpload,
    exceptionReason,
    setByName,
    setAt,
    activeUpload = null,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    canEdit: boolean;
    canUpload?: boolean;
    withoutUpload: boolean;
    exceptionReason: string | null;
    setByName: string | null;
    setAt: string | null;
    activeUpload?: DispoOrderUpload | null;
}) {
    const [checked, setChecked] = useState(withoutUpload);
    const [reason, setReason] = useState(exceptionReason ?? '');
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const checkboxId = useId();
    const reasonId = useId();
    const fileInputId = useId();

    const isDraft = status === 'draft';
    const hasActiveUpload = activeUpload !== null;
    const dirty =
        checked !== withoutUpload ||
        (checked ? reason.trim() !== (exceptionReason ?? '').trim() : false);

    async function save() {
        if (saving || !canEdit) {
            return;
        }

        if (checked && reason.trim() === '') {
            setError('Ein Ausnahmegrund ist erforderlich.');
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const result = await jsonPut<ActionResponse>(
                `/dispoauftraege/${orderId}/kundenbestaetigung`,
                {
                    lock_version: lockVersion,
                    confirmation_without_upload: checked,
                    exception_reason: checked ? reason.trim() : null,
                },
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
            setSaving(false);
        }
    }

    async function upload() {
        if (uploading || !canUpload || !selectedFile) {
            return;
        }

        setUploading(true);
        setError(null);

        try {
            const body = new FormData();
            body.append('file', selectedFile);
            body.append('lock_version', String(lockVersion));

            const result = await formDataPost<ActionResponse>(
                `/dispoauftraege/${orderId}/uploads/kundenbestaetigung`,
                body,
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
            setUploading(false);
        }
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-customer-confirmation"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Kundenbestätigung
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {hasActiveUpload ? (
                    <div
                        className="space-y-1 text-sm"
                        data-test="customer-confirmation-active-file"
                    >
                        <p className="font-medium">
                            {activeUpload.original_filename}
                        </p>
                        <p className="text-muted-foreground">
                            {formatFileSize(activeUpload.size_bytes)}
                            {activeUpload.uploaded_by_name
                                ? ` · ${activeUpload.uploaded_by_name}`
                                : ''}
                            {activeUpload.uploaded_at
                                ? ` · ${formatDateTime(activeUpload.uploaded_at)}`
                                : ''}
                        </p>
                        <a
                            href={activeUpload.download_url}
                            className="text-primary text-sm underline-offset-4 hover:underline"
                            data-test="customer-confirmation-active-download"
                        >
                            Herunterladen
                        </a>
                    </div>
                ) : !withoutUpload ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="customer-confirmation-status"
                    >
                        Noch nicht bestätigt
                    </p>
                ) : null}

                {canUpload && isDraft ? (
                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor={fileInputId}>
                                Kundenbestätigung hochladen
                            </Label>
                            <Input
                                id={fileInputId}
                                type="file"
                                disabled={uploading || saving}
                                data-test="customer-confirmation-upload-input"
                                onChange={(event) => {
                                    setSelectedFile(
                                        event.target.files?.[0] ?? null,
                                    );
                                    setError(null);
                                }}
                            />
                            <p
                                className="text-muted-foreground text-xs"
                                data-test="customer-confirmation-upload-max-size-hint"
                            >
                                Maximal 50 MB pro Datei
                            </p>
                        </div>
                        <Button
                            type="button"
                            data-test="customer-confirmation-upload-button"
                            disabled={
                                uploading || saving || selectedFile === null
                            }
                            onClick={() => void upload()}
                        >
                            {uploading
                                ? 'Wird hochgeladen…'
                                : 'Datei hochladen'}
                        </Button>
                    </div>
                ) : null}

                {canEdit && isDraft ? (
                    <>
                        {hasActiveUpload ? (
                            <p
                                className="text-muted-foreground text-sm"
                                data-test="customer-confirmation-upload-precedence-hint"
                            >
                                Es liegt eine aktive Kundenbestätigungsdatei
                                vor. Der Ausnahmeweg ist für die Einreichung
                                nicht erforderlich.
                            </p>
                        ) : null}

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id={checkboxId}
                                checked={checked}
                                disabled={saving || uploading}
                                data-test="customer-confirmation-without-upload"
                                onCheckedChange={(value) => {
                                    const next = value === true;
                                    setChecked(next);
                                    setError(null);
                                    if (!next) {
                                        setReason('');
                                    }
                                }}
                            />
                            <Label
                                htmlFor={checkboxId}
                                className="text-sm leading-snug font-normal"
                            >
                                Kundenbestätigung liegt vor, ist aber nicht als
                                Datei hinterlegt
                            </Label>
                        </div>

                        {checked ? (
                            <div className="grid gap-2">
                                <Label htmlFor={reasonId}>Ausnahmegrund</Label>
                                <textarea
                                    id={reasonId}
                                    className={formTextareaClass}
                                    value={reason}
                                    maxLength={2000}
                                    disabled={saving || uploading}
                                    data-test="customer-confirmation-exception-reason"
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    Begründe, warum der Auftrag ohne hinterlegte
                                    Kundenbestätigung zur Freigabe eingereicht
                                    wird.
                                </p>
                            </div>
                        ) : null}

                        <Button
                            type="button"
                            data-test="customer-confirmation-save"
                            disabled={
                                saving ||
                                uploading ||
                                !dirty ||
                                (checked && reason.trim() === '')
                            }
                            onClick={() => void save()}
                        >
                            {saving ? 'Wird gespeichert…' : 'Speichern'}
                        </Button>
                    </>
                ) : withoutUpload && !hasActiveUpload ? (
                    <div
                        className="space-y-2 text-sm"
                        data-test="customer-confirmation-readonly"
                    >
                        <p className="font-medium">Ausnahme ohne Upload</p>
                        {exceptionReason ? (
                            <p data-test="customer-confirmation-exception-reason-readonly">
                                Ausnahmegrund: {exceptionReason}
                            </p>
                        ) : null}
                        {setByName && setAt ? (
                            <p className="text-muted-foreground">
                                Gesetzt von {setByName} am{' '}
                                {formatDateTime(setAt)}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {error ? (
                    <ErrorState
                        message={error}
                        data-test="customer-confirmation-error"
                    />
                ) : null}
            </CardContent>
        </Card>
    );
}
