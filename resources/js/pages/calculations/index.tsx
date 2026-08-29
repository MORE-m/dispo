import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import { money } from '@/components/form-field';
import PageHeader from '@/components/heading-page';

type Row = {
    id: number;
    number: string;
    campaign: string | null;
    nn_invest: string;
    requires_special_approval: boolean;
};

export default function CalculationsIndex({
    calculations,
    canCreate,
}: {
    calculations: Row[];
    canCreate: boolean;
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Kalkulationen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Kalkulationen"
                    actions={
                        canCreate ? (
                            <Button asChild>
                                <Link href="/kalkulationen/neu">
                                    Neue Kalkulation
                                </Link>
                            </Button>
                        ) : null
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {calculations.length === 0 ? (
                    <EmptyState title="Keine Kalkulationen" />
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Nummer
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Kampagne
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        N/N-Invest
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Hinweis
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {calculations.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <Link
                                                href={`/kalkulationen/${row.id}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.campaign ?? '–'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {money(row.nn_invest)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {row.requires_special_approval
                                                ? 'Sonderfreigabe erforderlich'
                                                : ''}
                                        </td>
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

CalculationsIndex.layout = {
    breadcrumbs: [{ title: 'Kalkulationen', href: '/kalkulationen' }],
};
