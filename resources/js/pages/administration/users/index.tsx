import { Head, Link, usePage } from '@inertiajs/react';
import { EmptyState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    discount_limit_percent: string | null;
    can_special_approve: boolean;
    is_active: boolean;
    status_label: string;
};

export default function UserAdminIndex({ users }: { users: UserRow[] }) {
    const flash = usePage().props.flash;

    return (
        <>
            <Head title="Benutzer" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Benutzer"
                    description="Anlegen und pflegen: Rolle, Rabattgrenze, Sonderfreigabe, Aktivstatus. Kein Löschen."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/administration">
                                    Administration
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href="/administration/benutzer/neu"
                                    data-test="user-create-link"
                                >
                                    Benutzer anlegen
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {flash.success ? (
                    <SuccessState
                        message={flash.success}
                        data-test="user-admin-success"
                    />
                ) : null}

                {users.length === 0 ? (
                    <EmptyState title="Keine Benutzer vorhanden." />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border"
                        data-test="user-admin-table"
                    >
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        E-Mail
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Rolle
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Rabattgrenze
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Sonderfreigabe
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Aktion
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-t"
                                        data-test={`user-admin-row-${user.id}`}
                                        data-email={user.email}
                                    >
                                        <td className="px-3 py-2">
                                            {user.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {user.email}
                                        </td>
                                        <td className="px-3 py-2">
                                            {user.role_label}
                                        </td>
                                        <td
                                            className="px-3 py-2"
                                            data-test={`user-discount-${user.id}`}
                                        >
                                            {user.discount_limit_percent ===
                                            null
                                                ? '—'
                                                : `${user.discount_limit_percent} %`}
                                        </td>
                                        <td
                                            className="px-3 py-2"
                                            data-test={`user-special-${user.id}`}
                                        >
                                            {user.can_special_approve
                                                ? 'Ja'
                                                : 'Nein'}
                                        </td>
                                        <td
                                            className="px-3 py-2"
                                            data-test={`user-status-${user.id}`}
                                        >
                                            {user.status_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/administration/benutzer/${user.id}/bearbeiten`}
                                                    data-test={`user-edit-link-${user.id}`}
                                                >
                                                    Bearbeiten
                                                </Link>
                                            </Button>
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
