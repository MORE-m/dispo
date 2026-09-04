import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
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

export default function FieldSetPreview({
    fieldSet,
    preview,
    canActivate,
}: Props) {
    const flash = usePage().props.flash;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

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

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">
                        Felder (endgültige Reihenfolge)
                    </h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Feld
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Scope
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Pflicht
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Sichtbar
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Beispielwert
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {preview.fields.map((field) => (
                                    <tr
                                        key={field.membership_id}
                                        className="border-t"
                                    >
                                        <td className="px-4 py-2">
                                            <div className="font-medium">
                                                {field.label}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {field.key}
                                                {field.is_system
                                                    ? ' · Systemfeld'
                                                    : ''}
                                                {field.help_text
                                                    ? ` · ${field.help_text}`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2">
                                            {field.scope}
                                        </td>
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
                </section>

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
