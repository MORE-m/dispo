import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';

type OfferRow = {
    id: number;
    number: string;
    title: string;
    published_version_id: number | null;
    published_version_number: number | null;
    draft_version_id: number | null;
    has_draft: boolean;
};

export default function StandardOffersIndex({
    offers,
    canManage,
}: {
    offers: OfferRow[];
    canManage: boolean;
    canAdopt: boolean;
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Standardangebote" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Standardangebote"
                    description="Versionierte Vorlagen ohne Kundenbindung. Spot Classic Average (BL-P4-03a)."
                    actions={
                        canManage ? (
                            <Button asChild>
                                <Link
                                    href="/standardangebote/neu"
                                    data-test="standard-offer-create"
                                >
                                    Neues Standardangebot
                                </Link>
                            </Button>
                        ) : null
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {offers.length === 0 ? (
                    <EmptyState title="Keine Standardangebote" />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Nummer
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Titel
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Veröffentlicht
                                    </th>
                                    {canManage ? (
                                        <th className="px-4 py-2 font-medium">
                                            Entwurf
                                        </th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {offers.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/standardangebote/${row.id}${
                                                    row.draft_version_id
                                                        ? `?version=${row.draft_version_id}`
                                                        : row.published_version_id
                                                          ? `?version=${row.published_version_id}`
                                                          : ''
                                                }`}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test={`standard-offer-row-${row.id}`}
                                            >
                                                {row.number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.title}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.published_version_number
                                                ? `v${row.published_version_number}`
                                                : '–'}
                                        </td>
                                        {canManage ? (
                                            <td className="px-4 py-2">
                                                {row.has_draft ? 'ja' : '–'}
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
