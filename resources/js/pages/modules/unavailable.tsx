import { Head } from '@inertiajs/react';
import { EmptyState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';

export default function UnavailableModule({
    title,
    gate,
}: {
    title: string;
    gate: string;
}) {
    return (
        <>
            <Head title={title} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader title={title} />
                <EmptyState
                    title="Noch nicht verfügbar"
                    description={`Dieser Bereich ist durch ${gate} gesperrt. Es gibt keine vorgetäuschte Fachoberfläche.`}
                />
            </div>
        </>
    );
}
