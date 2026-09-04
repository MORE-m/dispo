import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorState, SuccessState } from '@/components/feedback/states';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { JsonPostError, jsonPost } from '@/lib/json-post';

type VersionRow = {
    id: number;
    version: number;
    status: string;
    created_at: string | null;
};

type FieldSetDetail = {
    id: number;
    key: string;
    name: string;
    lock_version: number;
    active_version_id: number | null;
    versions: VersionRow[];
};

export default function FieldSetShow({
    fieldSet,
}: {
    fieldSet: FieldSetDetail;
}) {
    const flash = usePage().props.flash;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const draft = fieldSet.versions.find((v) => v.status === 'draft');
    const sourceId =
        draft === undefined
            ? (fieldSet.active_version_id ??
              fieldSet.versions.find((v) => v.status === 'active')?.id ??
              fieldSet.versions[0]?.id)
            : null;

    async function createDraftFrom(versionId: number) {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await jsonPost<{ redirect: string }>(
                `/administration/dynamische-felder/feldsets/${fieldSet.id}/entwuerfe`,
                {
                    lock_version: fieldSet.lock_version,
                    source_version_id: versionId,
                },
            );
            router.visit(result.redirect);
        } catch (caught) {
            if (caught instanceof JsonPostError) {
                setError(
                    caught.isConflict
                        ? caught.message
                        : caught.message ||
                              'Der Entwurf konnte nicht angelegt werden.',
                );
            } else {
                setError('Der Entwurf konnte nicht angelegt werden.');
            }
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={`Feldset ${fieldSet.key}`} />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title={fieldSet.name}
                    description={`${fieldSet.key} · Sperrversion ${fieldSet.lock_version}`}
                    actions={
                        <div className="flex gap-2">
                            {draft ? (
                                <Button asChild>
                                    <Link
                                        href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${draft.id}`}
                                    >
                                        Entwurf öffnen
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    onClick={() => {
                                        if (
                                            sourceId !== null &&
                                            sourceId !== undefined
                                        ) {
                                            void createDraftFrom(sourceId);
                                        }
                                    }}
                                    disabled={sourceId === null || busy}
                                >
                                    Entwurf aus aktiver Version
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <Link href="/administration/dynamische-felder/feldsets">
                                    Zurück
                                </Link>
                            </Button>
                        </div>
                    }
                />
                {flash.success ? (
                    <SuccessState message={flash.success} />
                ) : null}
                {error ? <ErrorState message={error} /> : null}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-4 py-2 font-medium">
                                    Version
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Aktionen
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {fieldSet.versions.map((version) => (
                                <tr key={version.id} className="border-t">
                                    <td className="px-4 py-2">
                                        v{version.version}
                                    </td>
                                    <td className="px-4 py-2">
                                        {version.status}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}`}
                                                >
                                                    Ansehen
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/administration/dynamische-felder/feldsets/${fieldSet.id}/versionen/${version.id}/vorschau`}
                                                >
                                                    Vorschau
                                                </Link>
                                            </Button>
                                            {(version.status === 'archived' ||
                                                version.status === 'active') &&
                                            draft === undefined ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void createDraftFrom(
                                                            version.id,
                                                        )
                                                    }
                                                >
                                                    Als Vorlage kopieren
                                                </Button>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
