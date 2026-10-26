import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateOnly } from '@/lib/date-time';
import {
    DISPO_ORDERS_CACHE_TAG,
    flushDispoOrderInertiaCache,
} from '@/lib/dispo-order-inertia-cache';
import {
    resolveHandoffFlightForAdopt,
    validateOperationalFlightDraft,
    type HandoffFlightSourceInput,
    type OperationalFlightPeriod,
} from '@/lib/dispo-order-operational-flight';
import { JsonPostError, jsonPut } from '@/lib/json-post';
import { firstValidationMessage } from '@/lib/validation-errors';

export const OPERATIONAL_FLIGHT_LABEL =
    'Verbindlicher Kampagnenzeitraum (operativ)';

type ActionResponse = {
    message: string;
    redirect: string;
};

function displayPeriod(start: string | null, end: string | null): string {
    if (start == null || end == null) {
        return 'Noch nicht festgelegt';
    }

    return `${formatDateOnly(start)} – ${formatDateOnly(end)}`;
}

export function DispoOrderOperationalFlightEditor({
    orderId,
    positionId,
    lockVersion,
    flightStart,
    flightEnd,
    flightValid = false,
    canEdit,
    handoffFlightSource = null,
}: {
    orderId: number;
    positionId: number;
    lockVersion: number;
    flightStart: string | null;
    flightEnd: string | null;
    flightValid?: boolean;
    canEdit: boolean;
    handoffFlightSource?: HandoffFlightSourceInput | null;
}) {
    const [start, setStart] = useState(flightStart ?? '');
    const [end, setEnd] = useState(flightEnd ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [isConflict, setIsConflict] = useState(false);

    const adoptSource = useMemo(
        () =>
            handoffFlightSource
                ? resolveHandoffFlightForAdopt(handoffFlightSource)
                : null,
        [handoffFlightSource],
    );

    const hasPersistedFlight =
        flightValid === true ||
        (flightStart != null && flightEnd != null && flightEnd >= flightStart);

    const isUnsetPersisted = flightStart == null && flightEnd == null;

    function resetDraft() {
        setStart(flightStart ?? '');
        setEnd(flightEnd ?? '');
        setError(null);
        setFieldErrors({});
        setIsConflict(false);
    }

    function applyAdopt(source: OperationalFlightPeriod) {
        setStart(source.start);
        setEnd(source.end);
        setError(null);
        setFieldErrors({});
        setIsConflict(false);
    }

    async function persist(nextStart: string | null, nextEnd: string | null) {
        if (submitting || !canEdit) {
            return;
        }

        setSubmitting(true);
        setError(null);
        setFieldErrors({});
        setIsConflict(false);

        try {
            const result = await jsonPut<ActionResponse>(
                `/dispoauftraege/${orderId}/positionen/${positionId}/operativer-flight`,
                {
                    lock_version: lockVersion,
                    operational_flight_start: nextStart,
                    operational_flight_end: nextEnd,
                },
            );
            flushDispoOrderInertiaCache(orderId);
            router.visit(result.redirect, {
                invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
            });
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                if (caught.status === 403) {
                    setError(
                        caught.message ||
                            'Keine Berechtigung, den verbindlichen Kampagnenzeitraum zu ändern.',
                    );
                } else if (caught.isConflict) {
                    setIsConflict(true);
                    setError(
                        caught.message ||
                            'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
                    );
                } else {
                    const mapped: Record<string, string> = {};
                    for (const [key, messages] of Object.entries(
                        caught.fieldErrors,
                    )) {
                        if (messages[0]) {
                            mapped[key] = messages[0];
                        }
                    }
                    setFieldErrors(mapped);
                    setError(
                        firstValidationMessage(caught.fieldErrors) ??
                            caught.message,
                    );
                }
            } else {
                setError('Die Anfrage ist fehlgeschlagen.');
            }
            setSubmitting(false);
        }
    }

    function onSave() {
        const validated = validateOperationalFlightDraft(start, end);
        if (!validated.ok) {
            setFieldErrors(validated.fieldErrors);
            setError(
                Object.values(validated.fieldErrors)[0] ??
                    'Bitte Eingaben prüfen.',
            );
            return;
        }

        void persist(validated.start, validated.end);
    }

    function onClear() {
        void persist(null, null);
    }

    function onReload() {
        flushDispoOrderInertiaCache(orderId);
        router.visit(`/dispoauftraege/${orderId}`, {
            invalidateCacheTags: DISPO_ORDERS_CACHE_TAG,
        });
    }

    return (
        <div
            className="mt-3 space-y-2"
            data-test={`dispo-order-operational-flight-${positionId}`}
        >
            <p className="text-sm font-medium">{OPERATIONAL_FLIGHT_LABEL}</p>
            <p
                className="text-muted-foreground text-xs"
                data-test="dispo-order-operational-flight-separation-hint"
            >
                Unabhängig vom Kalkulationszeitraum (ursprünglicher Snapshot).
                Änderungen hier verändern die Ursprungskalkulation nicht.
            </p>

            {!canEdit ? (
                <>
                    <p
                        className="text-sm"
                        data-test="dispo-order-operational-flight-readonly"
                    >
                        {displayPeriod(flightStart, flightEnd)}
                    </p>
                    {hasPersistedFlight ? (
                        <p
                            className="text-xs font-medium text-emerald-800"
                            data-test="dispo-order-operational-flight-status-set"
                        >
                            Verbindlich festgelegt
                        </p>
                    ) : null}
                    {isUnsetPersisted ? (
                        <p
                            className="text-muted-foreground text-xs"
                            data-test="dispo-order-operational-flight-hint-required"
                        >
                            Für den Abschluss der Disposition müssen Start- und
                            Enddatum verbindlich festgelegt werden.
                        </p>
                    ) : null}
                </>
            ) : (
                <>
                    {isUnsetPersisted ? (
                        <p
                            className="text-muted-foreground text-xs"
                            data-test="dispo-order-operational-flight-hint-required"
                        >
                            Noch nicht festgelegt. Für den Abschluss der
                            Disposition müssen Start- und Enddatum verbindlich
                            festgelegt werden.
                        </p>
                    ) : (
                        <p
                            className="text-xs font-medium text-emerald-800"
                            data-test="dispo-order-operational-flight-status-set"
                        >
                            Verbindlich festgelegt
                        </p>
                    )}

                    <div className="flex flex-wrap gap-3">
                        <div className="space-y-1">
                            <Label htmlFor={`ops-flight-start-${positionId}`}>
                                Startdatum
                            </Label>
                            <Input
                                id={`ops-flight-start-${positionId}`}
                                type="date"
                                value={start}
                                disabled={submitting}
                                data-test="dispo-order-operational-flight-start"
                                onChange={(event) =>
                                    setStart(event.target.value)
                                }
                            />
                            {fieldErrors.operational_flight_start ? (
                                <p
                                    className="text-destructive text-xs"
                                    data-test="dispo-order-operational-flight-start-error"
                                >
                                    {fieldErrors.operational_flight_start}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor={`ops-flight-end-${positionId}`}>
                                Enddatum
                            </Label>
                            <Input
                                id={`ops-flight-end-${positionId}`}
                                type="date"
                                value={end}
                                disabled={submitting}
                                data-test="dispo-order-operational-flight-end"
                                onChange={(event) => setEnd(event.target.value)}
                            />
                            {fieldErrors.operational_flight_end ? (
                                <p
                                    className="text-destructive text-xs"
                                    data-test="dispo-order-operational-flight-end-error"
                                >
                                    {fieldErrors.operational_flight_end}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    {error ? (
                        <ErrorState
                            message={error}
                            data-test="dispo-order-operational-flight-error"
                        />
                    ) : null}

                    {isConflict ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            data-test="dispo-order-operational-flight-reload"
                            disabled={submitting}
                            onClick={onReload}
                        >
                            Seite neu laden
                        </Button>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        {adoptSource ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                data-test="dispo-order-operational-flight-adopt"
                                disabled={submitting}
                                onClick={() => applyAdopt(adoptSource)}
                            >
                                Zeitraum aus Kalkulation übernehmen
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            data-test="dispo-order-operational-flight-save"
                            disabled={submitting}
                            onClick={onSave}
                        >
                            {submitting ? 'Speichern…' : 'Speichern'}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            data-test="dispo-order-operational-flight-cancel"
                            disabled={submitting}
                            onClick={resetDraft}
                        >
                            Abbrechen
                        </Button>
                        {hasPersistedFlight ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                data-test="dispo-order-operational-flight-clear"
                                disabled={submitting}
                                onClick={onClear}
                            >
                                Verbindlichen Zeitraum entfernen
                            </Button>
                        ) : null}
                    </div>
                </>
            )}
        </div>
    );
}
