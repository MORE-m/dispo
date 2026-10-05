import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type Row = {
    id: number;
    created_at: string | null;
    last_attempt_at: string | null;
    available_at: string | null;
    sent_at: string | null;
    updated_at: string | null;
    event_type: string;
    event_label: string;
    status: string;
    status_label: string;
    attempt_count: number;
    recipient_name: string;
    recipient_email: string;
    recipient_user_id: number | null;
    order_number: string | null;
    order_id: number | null;
    order_url: string | null;
    source_type: string;
    source_id: number;
    channel: string;
    actor_name: string | null;
    actor_id: number | null;
    customer_name: string | null;
    campaign: string | null;
    occurred_at: string | null;
    error_text: string | null;
    error_truncated: boolean;
};

function Field({
    label,
    value,
}: {
    label: string;
    value: string | number | null | undefined;
}) {
    return (
        <div className="grid gap-1 border-b py-2 sm:grid-cols-[12rem_1fr]">
            <dt className="text-muted-foreground text-sm font-medium">
                {label}
            </dt>
            <dd className="text-sm wrap-break-word">{value ?? '—'}</dd>
        </div>
    );
}

export default function NotificationOutboxShow({ row }: { row: Row }) {
    return (
        <>
            <Head title={`Outbox #${row.id}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`Outbox #${row.id}`}
                    description="Lesende Detailansicht. Keine Versandaktionen."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration/benachrichtigungen">
                                    Zur Liste
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/administration">
                                    Administration
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <dl
                    className="rounded-xl border px-4"
                    data-test="notification-outbox-detail"
                >
                    <Field label="Status" value={row.status_label} />
                    <Field label="Ereignis" value={row.event_label} />
                    <Field label="Ereignis-Typ" value={row.event_type} />
                    <div className="grid gap-1 border-b py-2 sm:grid-cols-[12rem_1fr]">
                        <dt className="text-muted-foreground text-sm font-medium">
                            Auftrag
                        </dt>
                        <dd className="text-sm">
                            {row.order_url ? (
                                <Link
                                    href={row.order_url}
                                    className="underline"
                                >
                                    {row.order_number ?? `ID ${row.order_id}`}
                                </Link>
                            ) : (
                                (row.order_number ?? '—')
                            )}
                        </dd>
                    </div>
                    <Field label="Kunde" value={row.customer_name} />
                    <Field label="Kampagne" value={row.campaign} />
                    <Field label="Empfänger" value={row.recipient_name} />
                    <Field label="E-Mail" value={row.recipient_email} />
                    <Field
                        label="Empfänger-User-ID"
                        value={row.recipient_user_id}
                    />
                    <Field label="Auslöser" value={row.actor_name} />
                    <Field label="Versuche" value={row.attempt_count} />
                    <Field label="Erstellt" value={row.created_at} />
                    <Field label="Verfügbar ab" value={row.available_at} />
                    <Field
                        label="Letzter Versuch"
                        value={row.last_attempt_at}
                    />
                    <Field label="Gesendet" value={row.sent_at} />
                    <Field label="Ereigniszeit" value={row.occurred_at} />
                    <Field
                        label="Quelle"
                        value={`${row.source_type} #${row.source_id}`}
                    />
                    <Field
                        label="Fehler"
                        value={
                            row.error_text
                                ? `${row.error_text}${row.error_truncated ? ' (gekürzt)' : ''}`
                                : null
                        }
                    />
                </dl>
            </div>
        </>
    );
}
