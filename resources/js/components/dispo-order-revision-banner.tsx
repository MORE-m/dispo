import { Link } from '@inertiajs/react';
import type { DispoOrderRevisionContext } from '@/types/dispo-order';

export function DispoOrderRevisionBanner({
    revision,
}: {
    revision: DispoOrderRevisionContext;
}) {
    return (
        <aside
            className="border-border/70 bg-muted/30 rounded-xl border px-5 py-4 text-sm"
            data-test="dispo-order-revision-banner"
        >
            <p className="font-medium">
                Du bearbeitest die Kalkulation zur Nachbesserung von
                Dispoauftrag {revision.predecessor_number}.
            </p>
            {revision.rejection_reason ? (
                <p
                    className="text-muted-foreground mt-2"
                    data-test="dispo-order-revision-rejection-reason"
                >
                    Ablehnungsgrund: {revision.rejection_reason}
                </p>
            ) : null}
            <p className="text-muted-foreground mt-2">
                Der abgelehnte Auftrag bleibt unverändert. Nach dem Speichern
                erstellst du einen neuen Dispoauftrag und reichst ihn erneut zur
                Freigabe ein.
            </p>
            <p className="mt-3">
                <Link
                    href={revision.return_url}
                    className="font-medium underline-offset-4 hover:underline"
                    data-test="dispo-order-revision-return-link"
                >
                    Zurück zu {revision.predecessor_number}
                </Link>
            </p>
        </aside>
    );
}
