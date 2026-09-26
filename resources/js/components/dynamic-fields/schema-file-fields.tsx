import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { formDataPost, JsonPostError } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export type SchemaFileUploadSnapshot = {
    id: number;
    filename: string;
    download_url: string;
    archived: boolean;
};

export type SchemaFileField = {
    key: string;
    label: string;
    help_text?: string | null;
    field_type: string;
    sort?: number;
    required?: boolean;
    visible?: boolean;
    validation_json?: { allowed_mime_types?: string[] } | null;
    current_upload?: SchemaFileUploadSnapshot | null;
};

type SchemaSourceField = {
    key: string;
    label: string;
    help_text?: string | null;
    field_type: string;
    scope?: string;
    sort?: number;
    required?: boolean;
    visible?: boolean;
    validation_json?: { allowed_mime_types?: string[] } | null;
    current_upload?: SchemaFileUploadSnapshot | null;
};

type ActionResponse = {
    message: string;
    redirect: string;
};

type Props = {
    fields: SchemaFileField[];
    orderId: number;
    lockVersion: number;
    positionId?: number | null;
    canUpload: boolean;
    disabled?: boolean;
};

function failureMessage(caught: unknown): string {
    if (caught instanceof JsonPostError) {
        return caught.isConflict
            ? caught.message
            : (firstValidationMessage(caught.fieldErrors) ?? caught.message);
    }

    return 'Die Anfrage ist fehlgeschlagen.';
}

function acceptFromValidation(
    validation: SchemaFileField['validation_json'],
): string | undefined {
    const types = validation?.allowed_mime_types;
    if (!types || types.length === 0) {
        return undefined;
    }

    return types.join(',');
}

export function fileFieldsFromCustomBucket(
    fields: SchemaSourceField[],
): SchemaFileField[] {
    return fields
        .filter((field) => field.field_type === 'file')
        .map((field) => ({
            key: field.key,
            label: field.label,
            help_text: field.help_text,
            field_type: field.field_type,
            sort: field.sort,
            required: field.required === true,
            visible: field.visible !== false,
            validation_json: field.validation_json ?? null,
            current_upload: field.current_upload ?? null,
        }));
}

export function SchemaFileFields({
    fields,
    orderId,
    lockVersion,
    positionId = null,
    canUpload,
    disabled = false,
}: Props) {
    const sorted = [...fields].sort(
        (a, b) => (a.sort ?? 0) - (b.sort ?? 0) || a.key.localeCompare(b.key),
    );
    const visibleFields = sorted.filter(
        (field) => canUpload || field.current_upload,
    );

    const [uploadingKey, setUploadingKey] = useState<string | null>(null);
    const [pendingFiles, setPendingFiles] = useState<Record<string, File>>({});
    const [inputKeys, setInputKeys] = useState<Record<string, number>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    if (visibleFields.length === 0) {
        return null;
    }

    async function uploadField(field: SchemaFileField) {
        const file = pendingFiles[field.key];
        if (uploadingKey !== null || !file || !canUpload) {
            return;
        }

        setUploadingKey(field.key);
        setErrors((current) => {
            const next = { ...current };
            delete next[field.key];
            return next;
        });

        try {
            const body = new FormData();
            body.append('lock_version', String(lockVersion));
            body.append('file', file);
            body.append('field_key', field.key);
            if (positionId !== null && positionId !== undefined) {
                body.append('position_id', String(positionId));
            }

            const result = await formDataPost<ActionResponse>(
                `/dispoauftraege/${orderId}/uploads/dynamisches-feld`,
                body,
            );

            setPendingFiles((current) => {
                const next = { ...current };
                delete next[field.key];
                return next;
            });
            setInputKeys((current) => ({
                ...current,
                [field.key]: (current[field.key] ?? 0) + 1,
            }));
            flushDispoOrderInertiaCache(orderId);
            router.visit(result.redirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
        } catch (caught) {
            setErrors((current) => ({
                ...current,
                [field.key]: failureMessage(caught),
            }));
            setUploadingKey(null);
        }
    }

    return (
        <div className="space-y-4">
            {visibleFields.map((field) => {
                const upload = field.current_upload;
                const fieldError = errors[field.key];
                const isUploading = uploadingKey === field.key;
                const accept = acceptFromValidation(field.validation_json);

                return (
                    <div
                        key={field.key}
                        className="space-y-2"
                        data-test={`schema-file-field-${field.key}`}
                    >
                        <FormField
                            label={field.label}
                            htmlFor={`schema-file-input-${field.key}`}
                            hint={field.help_text ?? undefined}
                            error={fieldError}
                        >
                            {upload ? (
                                <p className="text-sm">
                                    <a
                                        href={upload.download_url}
                                        className="text-primary underline-offset-4 hover:underline"
                                        data-test={`schema-file-download-${field.key}`}
                                    >
                                        {upload.filename}
                                    </a>
                                </p>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    Noch keine Datei hinterlegt.
                                </p>
                            )}

                            {canUpload ? (
                                <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                    <Input
                                        key={inputKeys[field.key] ?? 0}
                                        id={`schema-file-input-${field.key}`}
                                        type="file"
                                        accept={accept}
                                        disabled={disabled || isUploading}
                                        data-test={`schema-file-input-${field.key}`}
                                        onChange={(event) => {
                                            const selected =
                                                event.target.files?.[0];
                                            setPendingFiles((current) => {
                                                const next = { ...current };
                                                if (selected) {
                                                    next[field.key] = selected;
                                                } else {
                                                    delete next[field.key];
                                                }
                                                return next;
                                            });
                                            setErrors((current) => {
                                                const next = { ...current };
                                                delete next[field.key];
                                                return next;
                                            });
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            disabled ||
                                            isUploading ||
                                            !pendingFiles[field.key]
                                        }
                                        data-test={`schema-file-upload-${field.key}`}
                                        onClick={() => void uploadField(field)}
                                    >
                                        {isUploading
                                            ? 'Wird hochgeladen…'
                                            : 'Hochladen'}
                                    </Button>
                                </div>
                            ) : null}
                        </FormField>
                    </div>
                );
            })}
        </div>
    );
}
