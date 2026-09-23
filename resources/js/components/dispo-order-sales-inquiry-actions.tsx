import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { formTextareaClass } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import { JsonPostError, jsonPost } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export type OpenSalesInquiry = {
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

export function DispoOrderSalesInquiryActions({
    orderId,
    lockVersion,
    canAsk,
    canAnswer,
    openInquiry,
}: {
    orderId: number;
    lockVersion: number;
    canAsk: boolean;
    canAnswer: boolean;
    openInquiry: OpenSalesInquiry | null;
}) {
    const [askOpen, setAskOpen] = useState(false);
    const [answerOpen, setAnswerOpen] = useState(false);
    const [question, setQuestion] = useState('');
    const [answer, setAnswer] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const questionId = useId();
    const answerId = useId();

    if (!canAsk && !canAnswer) {
        return null;
    }

    async function submitAsk() {
        if (submitting || question.trim() === '') {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/rueckfragen`,
                {
                    lock_version: lockVersion,
                    question: question.trim(),
                },
            );
            setAskOpen(false);
            setQuestion('');
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
            setSubmitting(false);
        }
    }

    async function submitAnswer() {
        if (submitting || openInquiry === null || answer.trim() === '') {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const result = await jsonPost<ActionResponse>(
                `/dispoauftraege/${orderId}/rueckfragen/${openInquiry.id}/antwort`,
                {
                    lock_version: lockVersion,
                    answer: answer.trim(),
                },
            );
            setAnswerOpen(false);
            setAnswer('');
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
            setSubmitting(false);
        }
    }

    return (
        <div
            className="flex flex-col gap-3"
            data-test="dispo-order-sales-inquiry-actions"
        >
            {error && !askOpen && !answerOpen ? (
                <ErrorState
                    message={error}
                    data-test="dispo-order-sales-inquiry-error"
                />
            ) : null}

            <div className="flex flex-wrap gap-2">
                {canAsk ? (
                    <Button
                        type="button"
                        variant="outline"
                        data-test="dispo-order-ask-sales-inquiry"
                        disabled={submitting}
                        onClick={() => {
                            setError(null);
                            setQuestion('');
                            setAskOpen(true);
                        }}
                    >
                        Rückfrage an Vertrieb
                    </Button>
                ) : null}

                {canAnswer ? (
                    <Button
                        type="button"
                        variant="default"
                        data-test="dispo-order-answer-sales-inquiry"
                        disabled={submitting}
                        onClick={() => {
                            setError(null);
                            setAnswer('');
                            setAnswerOpen(true);
                        }}
                    >
                        Rückfrage beantworten
                    </Button>
                ) : null}
            </div>

            <Dialog
                open={askOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setAskOpen(false);
                        setQuestion('');
                        setError(null);
                    }
                }}
            >
                <DialogContent data-test="dispo-order-ask-inquiry-dialog">
                    <DialogHeader>
                        <DialogTitle>Rückfrage an Vertrieb</DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird auf „Rückfrage Vertrieb“ gesetzt
                            und kann nach Antwort vom Vertrieb erneut von der
                            Disposition übernommen werden.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={questionId}>Rückfrage</Label>
                        <textarea
                            id={questionId}
                            className={formTextareaClass}
                            value={question}
                            data-test="dispo-order-ask-inquiry-question"
                            onChange={(event) =>
                                setQuestion(event.target.value)
                            }
                            rows={5}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-ask-inquiry-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => {
                                setAskOpen(false);
                                setQuestion('');
                                setError(null);
                            }}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-ask-inquiry-confirm"
                            disabled={submitting || question.trim() === ''}
                            onClick={() => {
                                void submitAsk();
                            }}
                        >
                            Rückfrage stellen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={answerOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setAnswerOpen(false);
                        setAnswer('');
                        setError(null);
                    }
                }}
            >
                <DialogContent data-test="dispo-order-answer-inquiry-dialog">
                    <DialogHeader>
                        <DialogTitle>Rückfrage beantworten</DialogTitle>
                        <DialogDescription>
                            Der Auftrag wird nach dem Absenden wieder auf „Liegt
                            bei Disposition“ gesetzt.
                        </DialogDescription>
                    </DialogHeader>
                    {openInquiry ? (
                        <div
                            className="bg-muted/30 rounded-lg border p-3 text-sm"
                            data-test="dispo-order-answer-inquiry-question"
                        >
                            <p className="font-medium">Offene Rückfrage</p>
                            <p className="text-muted-foreground mt-1">
                                {openInquiry.created_by_name}
                            </p>
                            <p className="mt-2 break-words whitespace-pre-wrap">
                                {openInquiry.body}
                            </p>
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label htmlFor={answerId}>Antwort</Label>
                        <textarea
                            id={answerId}
                            className={formTextareaClass}
                            value={answer}
                            data-test="dispo-order-answer-inquiry-answer"
                            onChange={(event) => setAnswer(event.target.value)}
                            rows={5}
                        />
                    </div>
                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-answer-inquiry-error"
                        />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => {
                                setAnswerOpen(false);
                                setAnswer('');
                                setError(null);
                            }}
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="button"
                            data-test="dispo-order-answer-inquiry-confirm"
                            disabled={submitting || answer.trim() === ''}
                            onClick={() => {
                                void submitAnswer();
                            }}
                        >
                            Antwort senden
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
