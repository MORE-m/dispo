import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { money } from '@/components/form-field';

type Recent = {
    id: number;
    number: string;
    campaign: string | null;
    nn_invest: string;
    updated_at: string;
};

export default function Overview({
    recentCalculations,
    canCreateCalculation,
}: {
    recentCalculations: Recent[];
    canCreateCalculation: boolean;
}) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Übersicht" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Übersicht"
                    actions={
                        canCreateCalculation ? (
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
                {recentCalculations.length === 0 ? (
                    <EmptyState
                        title="Keine Kalkulationen"
                        description={
                            canCreateCalculation
                                ? undefined
                                : 'Es liegen noch keine Vorgänge vor.'
                        }
                    />
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {recentCalculations.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={`/kalkulationen/${item.id}`}
                                    className="hover:bg-muted/40 flex items-center justify-between gap-4 px-4 py-3"
                                >
                                    <span className="font-medium">
                                        {item.number}
                                        {item.campaign
                                            ? ` · ${item.campaign}`
                                            : ''}
                                    </span>
                                    <span className="text-muted-foreground text-sm">
                                        {money(item.nn_invest)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Overview.layout = {
    breadcrumbs: [{ title: 'Übersicht', href: '/dashboard' }],
};
