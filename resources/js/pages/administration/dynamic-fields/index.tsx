import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type LinkItem = {
    title: string;
    description: string;
    href: string;
};

export default function DynamicFieldsHome({ links }: { links: LinkItem[] }) {
    return (
        <>
            <Head title="Dynamische Felder" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Dynamische Felder"
                    description="Systemfeld-Revisionen, Custom-Felder, Feldsets und Assignments. Kein Abschluss von DF-3."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration">
                                Zur Administration
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 md:grid-cols-2">
                    {links.map((item) => (
                        <div key={item.href} className="rounded-xl border p-4">
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
