import { Head, Link, usePage } from '@inertiajs/react';
import { SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type Module = {
    key: string;
    title: string;
    description: string;
    href: string | null;
    available: boolean;
};

export default function AdministrationIndex({
    modules,
}: {
    modules: Module[];
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Administration" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Administration"
                    description="UX-GATE-D Teilfreigabe: Dynamische Felder, Katalog, Inventar-Admin, Preislisten-Lifecycle und Excel-Import (Draft). Kombinationstabellen bleiben gesperrt."
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                <div className="grid gap-4 md:grid-cols-2">
                    {modules.map((module) => (
                        <div key={module.key} className="rounded-xl border p-4">
                            <h2 className="text-base font-semibold">
                                {module.title}
                            </h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {module.description}
                            </p>
                            <div className="mt-4">
                                {module.available && module.href ? (
                                    <Button asChild>
                                        <Link href={module.href}>Öffnen</Link>
                                    </Button>
                                ) : (
                                    <p className="text-muted-foreground text-sm">
                                        Noch nicht freigegeben
                                    </p>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
