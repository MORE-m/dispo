import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { JsonPostError, jsonPut } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';
import { formatDateTime } from '@/lib/date-time';

type ActionResponse = {
    message: string;
    redirect: string;
};

export function DispoOrderCustomerConfirmationSection({
    orderId,
    lockVersion,
    status,
    canEdit,
    withoutUpload,
    exceptionReason,
    setByName,
    setAt,
}: {
    orderId: number;
    lockVersion: number;
    status: string;
    canEdit: boolean;
    withoutUpload: boolean;
    exceptionReason: string | null;
    setByName: string | null;
    setAt: string | null;
}) {
    const [checked, setChecked] = useState(withoutUpload);
    const [reason, setReason] = useState(exceptionReason ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const checkboxId = useId();
    const reasonId = useId();

    const isDraft = status === 'draft';
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
                {!withoutUpload ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="customer-confirmation-status"
                    >
                        Noch nicht bestätigt
                    </p>
                ) : null}

                {canEdit && isDraft ? (
                    <>
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id={checkboxId}
                                checked={checked}
                                disabled={saving}
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
                                    disabled={saving}
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

                        {error ? (
                            <ErrorState
                                message={error}
                                data-test="customer-confirmation-error"
                            />
                        ) : null}

                        <Button
                            type="button"
                            data-test="customer-confirmation-save"
                            disabled={
                                saving ||
                                !dirty ||
                                (checked && reason.trim() === '')
                            }
                            onClick={() => void save()}
                        >
                            {saving ? 'Wird gespeichert…' : 'Speichern'}
                        </Button>
                    </>
                ) : withoutUpload ? (
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
            </CardContent>
        </Card>
    );
}
