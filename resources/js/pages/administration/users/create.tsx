import { Head, Link, useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type RoleOption = {
    value: string;
    label: string;
};

export default function UserAdminCreate({
    roleOptions,
}: {
    roleOptions: RoleOption[];
}) {
    const form = useForm({
        name: '',
        email: '',
        role: 'sales',
        password: '',
        password_confirmation: '',
        discount_limit_percent: '',
        can_special_approve: false,
    });

    const isSales = form.data.role === 'sales';

    return (
        <>
            <Head title="Benutzer anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Benutzer anlegen"
                    description="Neues Konto mit Rolle und initialem Passwort. Aktiv nach dem Anlegen."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/benutzer">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="user-create-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/administration/benutzer');
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
                        label="Initiales Passwort"
                        htmlFor="password"
                        error={form.errors.password}
                    >
                        <Input
                            id="password"
                            type="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            data-test="user-password-input"
                            autoComplete="new-password"
                        />
                    </FormField>
                    <FormField
                        label="Passwort bestätigen"
                        htmlFor="password_confirmation"
                        error={form.errors.password_confirmation}
                    >
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                            data-test="user-password-confirmation-input"
                            autoComplete="new-password"
                        />
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
                    <div className="flex gap-2 pt-2">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="user-create-submit"
                        >
                            Anlegen
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
