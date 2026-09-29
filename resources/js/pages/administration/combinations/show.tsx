import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type RuleDetail = {
    id: number;
    inventory_name: string;
    inventory_code: string | null;
    advertising_medium_name: string;
    advertising_medium_code: string | null;
    category_name: string | null;
    is_active: boolean;
    status_label: string;
    booking_code: string | null;
    planning_responsibility_key: string | null;
    planning_responsibility_label: string | null;
    hint_text: string | null;
    sort: number;
    default_length_seconds: number | null;
    surcharge_percent: string;
    is_discountable: boolean;
    is_ae_eligible: boolean;
    component_calculation_strategy: string;
    component_calculation_strategy_label: string;
    lock_version: number;
    must_not_plan: boolean;
    is_operative_complete: boolean;
};

type PlanningOption = { key: string; label: string };
type StrategyOption = { value: string; label: string };

export default function CombinationShow({
    rule,
    planningOptions,
    strategyOptions,
    urls,
}: {
    rule: RuleDetail;
    planningOptions: PlanningOption[];
    strategyOptions: StrategyOption[];
    urls: {
        index: string;
        update: string;
        deactivate: string;
        reactivate: string;
    };
}) {
    const flash = usePage().props.flash as {
        success?: string;
        error?: string;
    };

    const form = useForm({
        lock_version: rule.lock_version,
        booking_code: rule.booking_code ?? '',
        planning_responsibility_key: rule.planning_responsibility_key ?? '',
        hint_text: rule.hint_text ?? '',
        sort: rule.sort,
        default_length_seconds:
            rule.default_length_seconds ?? ('' as number | ''),
        surcharge_percent: rule.surcharge_percent,
        is_discountable: rule.is_discountable,
        is_ae_eligible: rule.is_ae_eligible,
        component_calculation_strategy: rule.component_calculation_strategy,
    });

    const lifecycle = useForm({
        lock_version: rule.lock_version,
    });

    return (
        <>
            <Head title={`Kombination #${rule.id}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`${rule.inventory_name} × ${rule.advertising_medium_name}`}
                    description={`Status: ${rule.status_label}. Inventar/Werbemittel unveränderlich. Buchungskennzeichen nur hier pflegen – in der Kalkulation nicht sichtbar.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={urls.index}>Zur Liste</Link>
                        </Button>
                    }
                />

                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {flash.error ? <ErrorState message={flash.error} /> : null}

                {rule.must_not_plan ? (
                    <p
                        className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
                        data-test="combination-must-not-plan-banner"
                    >
                        Diese Kombination ist als „darf nicht geplant werden“
                        markiert und kann in der Kalkulation nicht neu gewählt
                        werden.
                    </p>
                ) : null}

                <form
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                    data-test="combination-edit-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(urls.update, {
                            preserveScroll: true,
                            onSuccess: () => {
                                // lock_version comes back via full page props on redirect
                            },
                        });
                    }}
                >
                    <p className="text-muted-foreground text-sm">
                        Inventar: {rule.inventory_name}
                        {rule.inventory_code ? ` (${rule.inventory_code})` : ''}
                        <br />
                        Werbemittel: {rule.advertising_medium_name}
                        {rule.category_name
                            ? ` · Kategorie ${rule.category_name}`
                            : ''}
                    </p>
                    <FormField
                        label="Buchungskennzeichen"
                        htmlFor="booking_code"
                        error={form.errors.booking_code}
                    >
                        <Input
                            id="booking_code"
                            value={form.data.booking_code}
                            onChange={(e) =>
                                form.setData('booking_code', e.target.value)
                            }
                            className="font-mono"
                            data-test="combination-booking-input"
                        />
                    </FormField>
                    <FormField
                        label="Einplanung durch"
                        htmlFor="planning_responsibility_key"
                        error={form.errors.planning_responsibility_key}
                    >
                        <select
                            id="planning_responsibility_key"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.planning_responsibility_key}
                            onChange={(e) =>
                                form.setData(
                                    'planning_responsibility_key',
                                    e.target.value,
                                )
                            }
                            data-test="combination-planning-input"
                        >
                            {planningOptions.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        label="Hinweis"
                        htmlFor="hint_text"
                        error={form.errors.hint_text}
                    >
                        <textarea
                            id="hint_text"
                            className="min-h-20 w-full rounded-md border px-2 py-2"
                            value={form.data.hint_text}
                            onChange={(e) =>
                                form.setData('hint_text', e.target.value)
                            }
                            data-test="combination-hint-input"
                        />
                    </FormField>
                    <FormField
                        label="Sortierung"
                        htmlFor="sort"
                        error={form.errors.sort}
                    >
                        <Input
                            id="sort"
                            type="number"
                            value={form.data.sort}
                            onChange={(e) =>
                                form.setData('sort', Number(e.target.value))
                            }
                        />
                    </FormField>
                    <FormField
                        label="Standardlänge (Sekunden)"
                        htmlFor="default_length_seconds"
                        error={form.errors.default_length_seconds}
                    >
                        <Input
                            id="default_length_seconds"
                            type="number"
                            value={form.data.default_length_seconds}
                            onChange={(e) =>
                                form.setData(
                                    'default_length_seconds',
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        label="Aufschlag %"
                        htmlFor="surcharge_percent"
                        error={form.errors.surcharge_percent}
                    >
                        <Input
                            id="surcharge_percent"
                            value={form.data.surcharge_percent}
                            onChange={(e) =>
                                form.setData(
                                    'surcharge_percent',
                                    e.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        label="Komponentenstrategie"
                        htmlFor="component_calculation_strategy"
                        error={form.errors.component_calculation_strategy}
                    >
                        <select
                            id="component_calculation_strategy"
                            className="w-full rounded-md border px-2 py-2"
                            value={form.data.component_calculation_strategy}
                            onChange={(e) =>
                                form.setData(
                                    'component_calculation_strategy',
                                    e.target.value,
                                )
                            }
                        >
                            {strategyOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_discountable}
                            onChange={(e) =>
                                form.setData(
                                    'is_discountable',
                                    e.target.checked,
                                )
                            }
                        />
                        Rabattfähig
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_ae_eligible}
                            onChange={(e) =>
                                form.setData('is_ae_eligible', e.target.checked)
                            }
                        />
                        AE-fähig
                    </label>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="combination-save-submit"
                    >
                        Speichern
                    </Button>
                </form>

                <div className="flex flex-wrap gap-2">
                    {rule.is_active ? (
                        <Button
                            variant="outline"
                            disabled={lifecycle.processing}
                            data-test="combination-deactivate"
                            onClick={() =>
                                lifecycle.post(urls.deactivate, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Deaktivieren
                        </Button>
                    ) : (
                        <Button
                            disabled={lifecycle.processing}
                            data-test="combination-reactivate"
                            onClick={() =>
                                lifecycle.post(urls.reactivate, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Reaktivieren
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
