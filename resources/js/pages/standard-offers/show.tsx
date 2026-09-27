import { FormEvent, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';

type VersionDetail = {
    id: number;
    version_number: number;
    status: string;
    status_label: string;
    title: string;
    author_name: string | null;
    published_at: string | null;
    archived_at: string | null;
    lock_version: number;
    draft_payload: Record<string, unknown> | null;
    frozen_summary: {
        nn_invest: string | null;
        media_gross: string | null;
        position_count: number;
    } | null;
    is_editable: boolean;
    is_adoptable: boolean;
};

type VersionRow = {
    id: number;
    version_number: number;
    status: string;
    status_label: string;
    published_at: string | null;
    author_name: string | null;
};

type OfferSummary = {
    id: number;
    number: string;
    title: string;
    published_version_id: number | null;
    has_draft: boolean;
};

type Catalog = {
    inventories: { id: number; name: string; code: string }[];
    media: { id: number; name: string; code: string }[];
};

export default function StandardOfferShow({
    offer,
    version,
    versions,
    canManage,
    canAdopt,
    catalog,
    schemaFingerprint,
}: {
    offer: OfferSummary;
    version: VersionDetail;
    versions: VersionRow[];
    canManage: boolean;
    canAdopt: boolean;
    catalog: Catalog | null;
    schemaFingerprint: string | null;
}) {
    const flash = usePage().props.flash;
    const [customerName, setCustomerName] = useState('');
    const [agencyName, setAgencyName] = useState('');
    const [campaign, setCampaign] = useState('');
    const [title, setTitle] = useState(version.title);
    const [message, setMessage] = useState<string | null>(null);

    const positions = Array.isArray(version.draft_payload?.positions)
        ? (version.draft_payload?.positions as Array<Record<string, unknown>>)
        : [];

    function publish() {
        router.post(
            `/standardangebote/${offer.id}/versionen/${version.id}/veroeffentlichen`,
            { lock_version: version.lock_version },
        );
    }

    function archive() {
        router.post(
            `/standardangebote/${offer.id}/versionen/${version.id}/archivieren`,
            { lock_version: version.lock_version },
        );
    }

    function newDraft() {
        router.post(`/standardangebote/${offer.id}/entwurf`);
    }

    function saveDraft(event: FormEvent) {
        event.preventDefault();
        if (!schemaFingerprint || positions.length === 0) {
            setMessage('Entwurf hat keine speicherbaren Positionen.');
            return;
        }

        router.put(`/standardangebote/${offer.id}/versionen/${version.id}`, {
            title,
            campaign: (version.draft_payload?.campaign as string) ?? null,
            product_title:
                (version.draft_payload?.product_title as string) ?? null,
            briefing: (version.draft_payload?.briefing as string) ?? null,
            order_discount_percent: String(
                (version.draft_payload?.order_discount_percent as
                    | string
                    | number
                    | null
                    | undefined) ?? '0',
            ),
            ae_enabled: Boolean(version.draft_payload?.ae_enabled),
            schema_fingerprint: schemaFingerprint,
            lock_version: version.lock_version,
            positions: positions as Array<
                Record<
                    string,
                    | string
                    | number
                    | boolean
                    | null
                    | Array<Record<string, string | number>>
                >
            >,
        });
    }

    function adopt(event: FormEvent) {
        event.preventDefault();
        setMessage(null);
        if (!customerName.trim()) {
            setMessage('Kunde ist Pflicht.');
            return;
        }
        router.post(
            `/standardangebote/${offer.id}/versionen/${version.id}/uebernehmen`,
            {
                customer_name: customerName,
                agency_name: agencyName || null,
                campaign: campaign || null,
            },
            {
                onError: (errors) => {
                    const first = Object.values(errors)[0];
                    setMessage(
                        typeof first === 'string'
                            ? first
                            : 'Übernahme fehlgeschlagen.',
                    );
                },
            },
        );
    }

    return (
        <>
            <Head title={`${offer.number} – Standardangebot`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={offer.title}
                    description={`${offer.number} · Version v${version.version_number} · ${version.status_label}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/standardangebote">Liste</Link>
                        </Button>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {message ? (
                    <p className="text-destructive text-sm">{message}</p>
                ) : null}

                <section className="space-y-2 rounded-xl border p-4 text-sm">
                    <p>
                        Autor: {version.author_name ?? '–'}
                        {version.published_at
                            ? ` · Veröffentlicht: ${version.published_at}`
                            : ''}
                        {version.archived_at
                            ? ` · Archiviert: ${version.archived_at}`
                            : ''}
                    </p>
                    {version.frozen_summary ? (
                        <p>
                            Freeze: {version.frozen_summary.position_count}{' '}
                            Position(en), N/N{' '}
                            {version.frozen_summary.nn_invest ?? '–'}
                        </p>
                    ) : null}
                    <div className="flex flex-wrap gap-2 pt-2">
                        {canManage && version.is_editable ? (
                            <>
                                <Button
                                    type="button"
                                    onClick={publish}
                                    data-test="standard-offer-publish"
                                >
                                    Veröffentlichen
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={archive}
                                    data-test="standard-offer-archive-draft"
                                >
                                    Entwurf archivieren
                                </Button>
                            </>
                        ) : null}
                        {canManage &&
                        version.status === 'published' &&
                        !offer.has_draft ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={newDraft}
                                data-test="standard-offer-new-draft"
                            >
                                Neuen Entwurf anlegen
                            </Button>
                        ) : null}
                        {canManage && version.status === 'published' ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={archive}
                                data-test="standard-offer-archive"
                            >
                                Archivieren
                            </Button>
                        ) : null}
                    </div>
                </section>

                {canManage && version.is_editable ? (
                    <form
                        onSubmit={saveDraft}
                        className="space-y-3 rounded-xl border p-4"
                        data-test="standard-offer-draft-form"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="draft-title">Titel</Label>
                            <Input
                                id="draft-title"
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                            />
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {positions.length} Position(en) · Katalog{' '}
                            {catalog ? 'geladen' : 'fehlt'}
                        </p>
                        <Button type="submit">Entwurf speichern</Button>
                    </form>
                ) : null}

                {canAdopt && version.is_adoptable ? (
                    <form
                        onSubmit={adopt}
                        className="space-y-3 rounded-xl border p-4"
                        data-test="standard-offer-adopt-form"
                    >
                        <h2 className="font-medium">
                            In Kundenkalkulation übernehmen
                        </h2>
                        <p className="text-muted-foreground text-xs">
                            Kunde ist Pflicht (Freitext bis CRM-Slice). Die
                            Vorlage bleibt unverändert.
                        </p>
                        <div className="space-y-2">
                            <Label htmlFor="customer">Kunde</Label>
                            <Input
                                id="customer"
                                value={customerName}
                                onChange={(event) =>
                                    setCustomerName(event.target.value)
                                }
                                required
                                data-test="standard-offer-customer"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="agency">Agentur (optional)</Label>
                            <Input
                                id="agency"
                                value={agencyName}
                                onChange={(event) =>
                                    setAgencyName(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="campaign">
                                Kampagne (optional)
                            </Label>
                            <Input
                                id="campaign"
                                value={campaign}
                                onChange={(event) =>
                                    setCampaign(event.target.value)
                                }
                            />
                        </div>
                        <Button type="submit" data-test="standard-offer-adopt">
                            Übernehmen
                        </Button>
                    </form>
                ) : null}

                <section className="space-y-2">
                    <h2 className="font-medium">Versionshistorie</h2>
                    <ul className="space-y-1 text-sm">
                        {versions.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={`/standardangebote/${offer.id}?version=${row.id}`}
                                    className={
                                        row.id === version.id
                                            ? 'font-semibold underline'
                                            : 'underline-offset-4 hover:underline'
                                    }
                                >
                                    v{row.version_number} · {row.status_label}
                                    {row.author_name
                                        ? ` · ${row.author_name}`
                                        : ''}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
