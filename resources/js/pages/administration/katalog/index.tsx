import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type LinkItem = {
    title: string;
    description: string;
    href: string;
};

export default function CatalogHome({
    links,
    boundaryNote,
}: {
    links: LinkItem[];
    boundaryNote: string;
}) {
    return (
        <>
            <Head title="Katalog" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Werbemittel und Oberkategorien"
                    description="Stammdaten für den Kalkulationskatalog verwalten (ADV-001b)."
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href="/administration"
                                data-test="catalog-back-admin"
                            >
                                Zur Administration
                            </Link>
                        </Button>
                    }
                />
                <p
                    className="text-muted-foreground max-w-3xl text-sm"
                    data-test="catalog-boundary-note"
                >
                    {boundaryNote}
                </p>
                <div className="grid gap-4 md:grid-cols-2">
                    {links.map((item) => (
                        <div
                            key={item.href}
                            className="rounded-xl border p-4"
                            data-test={`catalog-tile-${item.href.includes('berechnungsmethoden') ? 'methods' : item.href.includes('werbemittel') ? 'media' : 'categories'}`}
                        >
                            <h2 className="text-base font-semibold">
                                {item.title}
                            </h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {item.description}
                            </p>
                            <Button className="mt-4" asChild>
                                <Link href={item.href}>Öffnen</Link>
                            </Button>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
