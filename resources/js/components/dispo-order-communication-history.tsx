import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/date-time';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { cn } from '@/lib/utils';
import { firstValidationMessage } from '@/lib/validation-errors';

export type CommunicationEntry = {
    id: number;
    type: string;
    type_label: string;
    body: string;
    created_by_name: string;
    created_at: string | null;
    parent_id: number | null;
};

type ActionResponse = {
    message: string;
    redirect: string;
};

const commentTextareaClass = cn(
    formTextareaClass,
    'dark:bg-input/30 dark:hover:bg-input/50',
);

export function DispoOrderCommunicationHistory({
    entries,
    orderId,
    canAddComment = false,
}: {
    entries: CommunicationEntry[];
    orderId?: number;
    canAddComment?: boolean;
}) {
    const [body, setBody] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const bodyId = useId();

    const showForm = canAddComment && orderId !== undefined;
    const showEmpty = entries.length === 0 && !showForm;

    if (showEmpty) {
        return null;
    }

    async function submitComment() {
        if (submitting || orderId === undefined || body.trim() === '') {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/kommentare`,
                { body: body.trim() },
            );
            setBody('');
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
                setError('Der Kommentar konnte nicht gespeichert werden.');
            }
            setSubmitting(false);
        }
    }

    return (
        <Card
            className="border-border/70 rounded-xl shadow-xs"
            data-test="dispo-order-communication-history"
        >
            <CardHeader className="border-border/60 bg-muted/20 border-b px-5 py-4">
                <CardTitle className="text-sm font-semibold">
                    Kommunikation
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 px-5 py-4">
                {entries.map((entry) => {
                    const isResponse = entry.type === 'sales_inquiry_response';
                    const isGeneral = entry.type === 'general';

                    return (
                        <article
                            key={entry.id}
                            className={cn(
                                'rounded-lg border p-3 text-sm',
                                isResponse && 'ml-4 border-l-4',
                                isGeneral &&
                                    'border-border/80 bg-muted/10 dark:bg-muted/20',
                            )}
                            data-test={`communication-entry-${entry.id}`}
                            data-type={entry.type}
                            data-parent-id={entry.parent_id ?? undefined}
                        >
                            <p className="font-medium">{entry.type_label}</p>
                            <p className="text-muted-foreground mt-1">
                                {entry.created_by_name}
                                {entry.created_at ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <span data-test="communication-created-at">
                                            {formatDateTime(entry.created_at)}
                                        </span>
                                    </>
                                ) : null}
                            </p>
                            <p
                                className="mt-2 max-w-full break-words whitespace-pre-wrap"
                                data-test="communication-body"
                            >
                                {entry.body}
                            </p>
                        </article>
                    );
                })}

                {showForm ? (
                    <div
                        className="border-border/70 space-y-2 border-t pt-3"
                        data-test="dispo-order-comment-form"
                    >
                        <Label htmlFor={bodyId}>Kommentar hinzufügen</Label>
                        <textarea
                            id={bodyId}
                            className={commentTextareaClass}
                            value={body}
                            rows={4}
                            maxLength={2000}
                            data-test="dispo-order-comment-body"
                            disabled={submitting}
                            onChange={(event) => setBody(event.target.value)}
                        />
                        {error ? (
                            <ErrorState
                                message={error}
                                data-test="dispo-order-comment-error"
                            />
                        ) : null}
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                data-test="dispo-order-comment-submit"
                                disabled={submitting || body.trim() === ''}
                                onClick={() => void submitComment()}
                            >
                                Kommentar speichern
                            </Button>
                        </div>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
