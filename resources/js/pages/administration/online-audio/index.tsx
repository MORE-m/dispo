import { Head, Link } from '@inertiajs/react';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type Section = {
    key: string;
    title: string;
    description: string;
    href: string;
};

export default function OnlineAudioAdminHub({
    sections,
}: {
    sections: Section[];
}) {
    return (
        <>
            <Head title="Online Audio – Administration" />
            <div
                className="flex flex-1 flex-col gap-6 p-6"
                data-test="oa-admin-hub"
            >
                <PageHeader
                    title="Online Audio"
                    description="TKP-Stammdaten: Preislisten, Targetings und Plattformen (Admin-only)."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration">Administration</Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 md:grid-cols-2">
                    {sections.map((section) => (
                        <div
                            key={section.key}
                            className="rounded-xl border p-4"
                        >
                            <h2 className="text-base font-semibold">
                                {section.title}
                            </h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {section.description}
                            </p>
                            <div className="mt-4">
                                <Button asChild>
                                    <Link href={section.href}>Öffnen</Link>
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
