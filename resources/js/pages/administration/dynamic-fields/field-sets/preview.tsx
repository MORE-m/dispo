import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { JsonPostError, jsonPost } from '@/lib/json-post';

type PreviewField = {
    membership_id: number;
    key: string;
    label: string;
    help_text: string | null;
    field_type: string;
    scope: string;
    sort: number;
    visible: boolean;
    effective_required: boolean;
    required_by_rule: boolean;
    is_system: boolean;
    example_value: unknown;
    revision: number;
};

type PreviewRule = {
    id: number;
    sort: number;
    summary: string;
};

type Props = {
    fieldSet: {
        id: number;
        key: string;
        name: string;
        is_system?: boolean;
        applies_to?: string;
        lock_version: number;
    };
    preview: {
        version_id: number;
        version: number;
        status: string;
        fields: PreviewField[];
        rules: PreviewRule[];
        example_values: {
            header: Record<string, unknown>;
            position: Record<string, unknown>;
        };
    };
    canActivate: boolean;
    runtimeNote?: string | null;
};

function formatExample(value: unknown): string {
    if (value === null || value === undefined) {
        return '–';
    }
    if (typeof value === 'boolean') {
        return value ? 'ja' : 'nein';
    }
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return '–';
}

function FieldTable({ fields }: { fields: PreviewField[] }) {
    if (fields.length === 0) {
        return <p className="text-muted-foreground text-sm">Keine Felder.</p>;
    }

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-left text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        <th className="px-4 py-2 font-medium">Feld</th>
                        <th className="px-4 py-2 font-medium">Scope</th>
                        <th className="px-4 py-2 font-medium">Pflicht</th>
                        <th className="px-4 py-2 font-medium">Sichtbar</th>
                        <th className="px-4 py-2 font-medium">Beispielwert</th>
                    </tr>
                </thead>
                <tbody>
                    {fields.map((field) => (
                        <tr key={field.membership_id} className="border-t">
                            <td className="px-4 py-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {field.label}
                                    </span>
                                    {field.is_system ? (
                                        <Badge variant="outline">System</Badge>
                                    ) : (
                                        <Badge variant="secondary">Eigen</Badge>
                                    )}
                                    {field.field_type === 'short_text' ||
                                    field.field_type === 'long_text' ? (
                                        <Badge variant="outline">
                                            {field.field_type}
                                        </Badge>
                                    ) : null}
                                </div>
                                <div className="text-muted-foreground">
                                    {field.key}
                                    {field.help_text
                                        ? ` · ${field.help_text}`
                                        : ''}
                                </div>
                            </td>
                            <td className="px-4 py-2">{field.scope}</td>
                            <td className="px-4 py-2">
                                {field.effective_required
                                    ? field.required_by_rule
                                        ? 'ja (Regel)'
                                        : 'ja'
                                    : 'nein'}
                            </td>
                            <td className="px-4 py-2">
                                {field.visible ? 'ja' : 'nein'}
                            </td>
                            <td className="px-4 py-2 font-mono text-xs">
                                {formatExample(field.example_value)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function FieldSetPreview({
    fieldSet,
    preview,
    canActivate,
    runtimeNote = null,
}: Props) {
    const flash = usePage().props.flash;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const isCalcSet =
        fieldSet.key === 'system_calculation_core' ||
        fieldSet.applies_to === 'calculation';
    const isDispoSet =
        fieldSet.key === 'system_dispo_order_core' ||
        fieldSet.applies_to === 'dispo_order';
    const headerFields = preview.fields.filter(
        (field) => field.scope === 'header',
    );
    const positionFields = preview.fields.filter(
        (field) => field.scope === 'position',
    );

    async function activate() {
        if (
            busy ||
            !window.confirm(
                'Version wirklich aktivieren? Bestehende Kalkulationen und Dispoaufträge bleiben unverändert.',
            )
        ) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${preview.version_id}/aktivieren`,
                { lock_version: fieldSet.lock_version },
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : caught.message ||
                              'Die Aktivierung ist fehlgeschlagen.',
                );
            } else {
                setError('Die Aktivierung ist fehlgeschlagen.');
            }
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Vorschau Version ${preview.version}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={`Vorschau · ${fieldSet.name} v${preview.version}`}
                    description="Statische Vorschau mit Beispielwerten (ADM-002). Keine echten Vorgangswerte werden verändert."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canActivate ? (
                                <Button
                                    type="button"
                                    onClick={() => void activate()}
                                    disabled={busy}
                                    data-test="fieldset-version-activate"
                                >
                                    Version aktivieren
                                </Button>
                            ) : null}
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${preview.version_id}`}
                                >
                                    Zur Version
                                </Link>
                            </Button>
                        </div>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {error ? <ErrorState message={error} /> : null}
                {runtimeNote ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-test="fieldset-preview-runtime-note"
                    >
                        {runtimeNote}
                    </p>
                ) : null}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        {isCalcSet
                            ? 'Kalkulation – Header'
                            : isDispoSet
                              ? 'Dispoauftrag – Header'
                              : 'Header'}
                    </h2>
                    <FieldTable fields={headerFields} />
                </section>

                {positionFields.length > 0 ? (
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            {isCalcSet
                                ? 'Kalkulation – Position'
                                : isDispoSet
                                  ? 'Dispoauftrag – Position'
                                  : 'Position'}
                        </h2>
                        <FieldTable fields={positionFields} />
                    </section>
                ) : null}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Regeln (nur Lesen)
                    </h2>
                    {preview.rules.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Keine Regeln.
                        </p>
                    ) : (
                        <ul className="space-y-2 rounded-xl border p-4 text-sm">
                            {preview.rules.map((rule) => (
                                <li key={rule.id}>
                                    #{rule.sort}: {rule.summary}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
