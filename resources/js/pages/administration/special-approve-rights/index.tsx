import { Head, Link, router, usePage } from '@inertiajs/react';
import { SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type SalesUserRow = {
    id: number;
    name: string;
    email: string;
    can_special_approve: boolean;
};

export default function SpecialApproveRightsIndex({
    salesUsers,
}: {
    salesUsers: SalesUserRow[];
}) {
    const flash = usePage().props.flash;

    const setRight = (user: SalesUserRow, next: boolean) => {
        router.put(
            `/administration/sonderfreigaben/${user.id}`,
            { can_special_approve: next },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Sonderfreigaberechte" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Sonderfreigaberechte"
                    description="Nur Admin. Vergabe und Entzug des kaufmännischen Sonderfreigaberechts für Vertriebsnutzer. Keine Rollen- oder Passwortänderung."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration">Administration</Link>
                        </Button>
                    }
                />

                {flash.success ? (
                    <SuccessState
                        message={flash.success}
                        data-test="special-approve-rights-success"
                    />
                ) : null}

                <div
                    className="overflow-x-auto rounded-xl border"
                    data-test="special-approve-rights-table"
                >
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">
                                    E-Mail
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Sonderfreigabe
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Aktion
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {salesUsers.length === 0 ? (
                                <tr>
                                    <td
                                        className="text-muted-foreground px-3 py-4"
                                        colSpan={4}
                                    >
                                        Keine Vertriebsnutzer vorhanden.
                                    </td>
                                </tr>
                            ) : (
                                salesUsers.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-t"
                                        data-test={`special-approve-row-${user.id}`}
                                        data-email={user.email}
                                    >
                                        <td className="px-3 py-2">
                                            {user.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {user.email}
                                        </td>
                                        <td
                                            className="px-3 py-2"
                                            data-test={`special-approve-status-${user.id}`}
                                        >
                                            {user.can_special_approve
                                                ? 'Ja'
                                                : 'Nein'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {user.can_special_approve ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    data-test={`special-approve-revoke-${user.id}`}
                                                    onClick={() =>
                                                        setRight(user, false)
                                                    }
                                                >
                                                    Entziehen
                                                </Button>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    data-test={`special-approve-grant-${user.id}`}
                                                    onClick={() =>
                                                        setRight(user, true)
                                                    }
                                                >
                                                    Vergeben
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
