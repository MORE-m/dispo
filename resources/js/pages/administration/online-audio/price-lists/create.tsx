import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { ErrorState } from '@/components/feedback/states';
import { FormField } from '@/components/form-field';
import PageHeader from '@/components/heading-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    buildMatrixRows,
    filledMatrixItems,
    firstApiError,
    matrixSnapshot,
    oaCsrfHeaders,
    oaMediumLabel,
    OA_TEST_LIST_TKP,
    OA_TEST_MINIMUM_TKP,
    type OaPriceListItemRow,
    type OaRequiredPair,
} from '@/lib/oa-tkp-admin';

function rowsWithTestPrices(rows: OaPriceListItemRow[]): OaPriceListItemRow[] {
    return rows.map((row) => ({
        ...row,
        list_tkp: OA_TEST_LIST_TKP[row.medium_code] ?? row.list_tkp,
        minimum_tkp: OA_TEST_MINIMUM_TKP[row.medium_code] ?? row.minimum_tkp,
    }));
}

function groupRowIndices(rows: OaPriceListItemRow[]): number[][] {
    const groups: number[][] = [];
    let currentMedium = '';
    let current: number[] = [];
    rows.forEach((row, index) => {
        if (row.medium_code !== currentMedium) {
            if (current.length > 0) {
                groups.push(current);
            }
            currentMedium = row.medium_code;
            current = [index];
        } else {
            current.push(index);
        }
    });
    if (current.length > 0) {
        groups.push(current);
    }

    return groups;
}

export default function OaPriceListCreate({
    requiredPairs,
    currentYear,
    suggestedYear,
}: {
    requiredPairs: OaRequiredPair[];
    currentYear: number;
    suggestedYear: number;
}) {
    const [year, setYear] = useState(String(suggestedYear));
    const [name, setName] = useState('');
    const [fillTestPrices, setFillTestPrices] = useState(false);
    const [rows, setRows] = useState<OaPriceListItemRow[]>(() =>
        buildMatrixRows(requiredPairs, []),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const baselineSnapshot = useMemo(
        () =>
            JSON.stringify({
                year: String(suggestedYear),
                name: '',
                matrix: matrixSnapshot(buildMatrixRows(requiredPairs, [])),
                fillTestPrices: false,
            }),
        [requiredPairs, suggestedYear],
    );

    const dirty = useMemo(() => {
        const snapshot = JSON.stringify({
            year,
            name,
            matrix: matrixSnapshot(rows),
            fillTestPrices,
        });

        return snapshot !== baselineSnapshot;
    }, [baselineSnapshot, year, name, rows, fillTestPrices]);

    useEffect(() => {
        function onBeforeUnload(event: BeforeUnloadEvent) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        }
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    useEffect(() => {
        if (fillTestPrices) {
            setRows((prev) => rowsWithTestPrices(prev));
        }
    }, [fillTestPrices]);

    function updateCell(
        index: number,
        field: 'list_tkp' | 'minimum_tkp',
        value: string,
    ) {
        setRows((prev) =>
            prev.map((row, i) =>
                i === index ? { ...row, [field]: value } : row,
            ),
        );
    }

    const matrixGroups = groupRowIndices(rows);

    async function onSubmit(event: React.FormEvent) {
        event.preventDefault();
        if (busy) {
            return;
        }

        const matrixRows = fillTestPrices ? rowsWithTestPrices(rows) : rows;
        const items = filledMatrixItems(matrixRows);
        if (items.length === 0) {
            setError(
                'Bitte mindestens ein Medium×Inventar-Paar mit Listen- und Mindest-TKP befüllen, oder „Testpreise eintragen“ aktivieren.',
            );

            return;
        }

        setBusy(true);
        setError(null);
        try {
            const response = await fetch(
                '/administration/online-audio/preislisten',
                {
                    method: 'POST',
                    headers: oaCsrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        year: Number(year) || currentYear,
                        name: name.trim(),
                        items,
                    }),
                },
            );
            const result = (await response.json()) as {
                message?: string;
                price_list?: { id: number };
                errors?: string[] | Record<string, string[]>;
                field_errors?: Array<{
                    line: number | null;
                    field: string | null;
                    message: string;
                }>;
            };
            if (!response.ok) {
                setError(
                    firstApiError(
                        result,
                        'Der Entwurf konnte nicht angelegt werden.',
                    ),
                );

                return;
            }
            const id = result.price_list?.id;
            if (id) {
                router.visit(`/administration/online-audio/preislisten/${id}`);
            } else {
                setError('Entwurf angelegt, aber keine ID in der Antwort.');
            }
        } catch {
            setError('Die Anfrage konnte nicht gesendet werden.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="OA-TKP-Entwurf anlegen" />
            <div className="flex flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="OA-TKP-Preislisten-Entwurf"
                    description={`Kalenderjahr und Revision werden serverseitig vergeben. Aktuelles Jahr: ${currentYear}. Vorbereitung oft für ${suggestedYear}.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/administration/online-audio/preislisten">
                                Zur Liste
                            </Link>
                        </Button>
                    }
                />

                {error ? <ErrorState message={error} /> : null}

                <form
                    className="space-y-6"
                    data-test="oa-price-list-create-form"
                    onSubmit={onSubmit}
                >
                    <div className="max-w-xl space-y-4 rounded-xl border p-4">
                        <FormField label="Kalenderjahr" htmlFor="year">
                            <Input
                                id="year"
                                type="number"
                                value={year}
                                disabled={busy}
                                onChange={(event) =>
                                    setYear(event.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="Name" htmlFor="name">
                            <Input
                                id="name"
                                value={name}
                                disabled={busy}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </FormField>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={fillTestPrices}
                                disabled={busy}
                                onChange={(event) =>
                                    setFillTestPrices(event.target.checked)
                                }
                                data-test="oa-fill-test-prices"
                            />
                            Testpreise für alle Pflichtpaare eintragen
                            (OA_TEST_* je Medium, Listen + Mindest)
                        </label>
                    </div>

                    <div className="overflow-x-auto rounded-xl border">
                        <table
                            className="w-full text-left text-sm"
                            data-test="oa-price-list-matrix"
                        >
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Werbeform
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Inventar
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Listen-TKP
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Mindest-TKP
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {matrixGroups.map((indices) => {
                                    const mediumCode =
                                        rows[indices[0]]?.medium_code ?? '';
                                    const mediumLabel = oaMediumLabel(
                                        mediumCode,
                                        rows[indices[0]]?.medium_name,
                                    );

                                    return (
                                        <Fragment key={`group-${mediumCode}`}>
                                            <tr className="bg-muted/20 border-t">
                                                <td
                                                    colSpan={4}
                                                    className="px-3 py-2 text-xs font-semibold tracking-wide uppercase"
                                                >
                                                    {mediumLabel}
                                                </td>
                                            </tr>
                                            {indices.map((index) => {
                                                const row = rows[index];

                                                return (
                                                    <tr
                                                        key={`${row.medium_code}|${row.inventory_code}`}
                                                        className="border-t"
                                                    >
                                                        <td className="px-3 py-2">
                                                            {row.medium_name ??
                                                                oaMediumLabel(
                                                                    row.medium_code,
                                                                )}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {row.inventory_name ??
                                                                row.inventory_code}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                inputMode="decimal"
                                                                className="font-mono"
                                                                value={
                                                                    row.list_tkp
                                                                }
                                                                disabled={busy}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateCell(
                                                                        index,
                                                                        'list_tkp',
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                inputMode="decimal"
                                                                className="font-mono"
                                                                value={
                                                                    row.minimum_tkp
                                                                }
                                                                disabled={busy}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateCell(
                                                                        index,
                                                                        'minimum_tkp',
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <Button
                        type="submit"
                        disabled={busy}
                        data-test="oa-price-list-create-submit"
                    >
                        Entwurf anlegen
                    </Button>
                </form>
            </div>
        </>
    );
}
