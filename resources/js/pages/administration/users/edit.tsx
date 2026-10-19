import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { SuccessState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type RoleOption = {
    value: string;
    label: string;
};

type EditableUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    discount_limit_percent: string | null;
    can_special_approve: boolean;
    is_active: boolean;
};

export default function UserAdminEdit({
    user,
    roleOptions,
}: {
    user: EditableUser;
    roleOptions: RoleOption[];
}) {
    const flash = usePage().props.flash;
    const form = useForm({
        name: user.name,
        email: user.email,
        role: user.role,
        discount_limit_percent: user.discount_limit_percent ?? '',
        can_special_approve: user.can_special_approve,
        is_active: user.is_active,
    });

    const isSales = form.data.role === 'sales';

    return (
        <>
            <Head title={`Benutzer: ${user.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Benutzer bearbeiten"
                    description="Rolle, Rabattgrenze, Sonderfreigabe und Aktivstatus. Passwort über bestehenden Auth-Pfad."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/benutzer">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                {flash.success ? (
                    <SuccessState
                        message={flash.success}
                        data-test="user-admin-success"
                    />
                ) : null}

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="user-edit-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/administration/benutzer/${user.id}`);
                    }}
                >
                    <FormField
                        label="Name"
                        htmlFor="name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            data-test="user-name-input"
                        />
                    </FormField>
                    <FormField
                        label="E-Mail"
                        htmlFor="email"
                        error={form.errors.email}
                    >
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            data-test="user-email-input"
                        />
                    </FormField>
                    <FormField
                        label="Rolle"
                        htmlFor="role"
                        error={form.errors.role}
                    >
                        <select
                            id="role"
                            className="w-full rounded-md border px-3 py-2"
                            value={form.data.role}
                            onChange={(e) => {
                                const role = e.target.value;
                                form.setData((data) => ({
                                    ...data,
                                    role,
                                    can_special_approve:
                                        role === 'sales'
                                            ? data.can_special_approve
                                            : false,
                                }));
                            }}
                            data-test="user-role-input"
                        >
                            {roleOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Rabattgrenze (%)"
                        htmlFor="discount_limit_percent"
                        error={form.errors.discount_limit_percent}
                        hint="Leer = keine persönliche Grenze."
                    >
                        <Input
                            id="discount_limit_percent"
                            type="number"
                            min={0}
                            max={100}
                            step="0.0001"
                            value={form.data.discount_limit_percent}
                            onChange={(e) =>
                                form.setData(
                                    'discount_limit_percent',
                                    e.target.value,
                                )
                            }
                            data-test="user-discount-input"
                        />
                    </FormField>
                    {isSales ? (
                        <FormField
                            label="Sonderfreigabe"
                            htmlFor="can_special_approve"
                            error={form.errors.can_special_approve}
                        >
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    id="can_special_approve"
                                    type="checkbox"
                                    checked={form.data.can_special_approve}
                                    onChange={(e) =>
                                        form.setData(
                                            'can_special_approve',
                                            e.target.checked,
                                        )
                                    }
                                    data-test="user-special-approve-input"
                                />
                                Kaufmännisches Sonderfreigaberecht
                            </label>
                        </FormField>
                    ) : null}
                    <FormField
                        label="Aktivstatus"
                        htmlFor="is_active"
                        error={form.errors.is_active}
                    >
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                id="is_active"
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) =>
                                    form.setData('is_active', e.target.checked)
                                }
                                data-test="user-active-input"
                            />
                            Benutzer ist aktiv
                        </label>
                    </FormField>
                    <div className="flex gap-2 pt-2">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="user-edit-submit"
                        >
                            Speichern
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
