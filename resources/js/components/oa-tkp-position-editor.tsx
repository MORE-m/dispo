import { FormField, money } from '@/components/form-field';
import { StatusBanner } from '@/components/feedback/states';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    applyOaTkpMetadataCandidate,
    formatTkpDisplay,
    isActiveTargetingBlockedByHistorical,
    isOaTkpTripleUnchanged,
    needsOaTkpRebind,
    platformDistributionStatus,
    restoreOriginalOaTkpPin,
    type OaPlatformAllocationDraft,
    type OaPlatformCatalogRow,
    type OaTargetingCatalogRow,
    type OaTkpMethodResult,
    type OaTkpPositionFields,
} from '@/lib/oa-tkp-wizard';
import {
    useOaTkpAvailablePriceYears,
    useOaTkpPricingMetadata,
} from '@/hooks/use-oa-tkp-pricing-metadata';
import { useEffect, useMemo } from 'react';

type Props = {
    positionIndex: number;
    position: OaTkpPositionFields & { client_key: string };
    platforms: OaPlatformCatalogRow[];
    targetings: OaTargetingCatalogRow[];
    currentPriceYear: number;
    nextPriceYear: number;
    methodResult: OaTkpMethodResult | null | undefined;
    mediaGross: string | null | undefined;
    fieldErrors: Record<string, string[]>;
    canEdit: boolean;
    /** Capability OFF + historical: nur Anzeige. */
    showActivePickers: boolean;
    onChange: (patch: Partial<OaTkpPositionFields>) => void;
};

export function OaTkpPositionEditor({
    positionIndex,
    position,
    platforms,
    targetings,
    currentPriceYear,
    nextPriceYear,
    methodResult,
    mediaGross,
    fieldErrors,
    canEdit,
    showActivePickers,
    onChange,
}: Props) {
    const rebind = needsOaTkpRebind(position);
    const onOriginalTriple = isOaTkpTripleUnchanged(position);

    const { years: availableYears, loading: yearsLoading } =
        useOaTkpAvailablePriceYears({
            enabled: showActivePickers && canEdit,
            inventoryId: position.inventory_id,
            advertisingMediumId: position.advertising_medium_id,
            currentYear: currentPriceYear,
            nextYear: nextPriceYear,
            clientKey: position.client_key,
        });

    const priceYearOptions = useMemo(() => {
        const years = new Set(availableYears);
        if (position.original_oa_price_year != null && onOriginalTriple) {
            years.add(position.original_oa_price_year);
        }
        years.add(position.price_year);

        return Array.from(years).sort((a, b) => a - b);
    }, [
        availableYears,
        position.original_oa_price_year,
        position.price_year,
        onOriginalTriple,
    ]);

    const {
        loading: metadataLoading,
        error: metadataError,
        metadata,
    } = useOaTkpPricingMetadata({
        enabled: showActivePickers && canEdit,
        shouldFetch: rebind,
        triple: {
            clientKey: position.client_key,
            inventoryId: position.inventory_id,
            advertisingMediumId: position.advertising_medium_id,
            priceYear: position.price_year,
        },
    });

    useEffect(() => {
        if (!showActivePickers || !canEdit) {
            return;
        }

        if (!rebind && onOriginalTriple) {
            const restored = restoreOriginalOaTkpPin(position);
            if (
                restored.expected_oa_tkp_price_list_id !==
                    position.expected_oa_tkp_price_list_id ||
                restored.list_tkp_snapshot !== position.list_tkp_snapshot ||
                restored.minimum_tkp_snapshot !== position.minimum_tkp_snapshot
            ) {
                onChange(restored);
            }

            return;
        }

        if (metadata == null) {
            return;
        }

        if (
            metadata.expected_oa_tkp_price_list_id ===
                position.expected_oa_tkp_price_list_id &&
            metadata.list_tkp === position.list_tkp_snapshot &&
            metadata.minimum_tkp === position.minimum_tkp_snapshot
        ) {
            return;
        }

        onChange(applyOaTkpMetadataCandidate(metadata));
        // onChange/position bewusst nicht vollständig – nur Metadata-Apply.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        metadata,
        rebind,
        onOriginalTriple,
        showActivePickers,
        canEdit,
        position.expected_oa_tkp_price_list_id,
        position.list_tkp_snapshot,
        position.minimum_tkp_snapshot,
    ]);

    // Current Year nicht verfügbar, Next schon → Jahr auf Next setzen (nur neue/rebind).
    useEffect(() => {
        if (!showActivePickers || !canEdit || yearsLoading) {
            return;
        }
        if (availableYears.length === 0) {
            return;
        }
        if (availableYears.includes(position.price_year)) {
            return;
        }
        if (!rebind) {
            return;
        }
        const preferred = availableYears.includes(currentPriceYear)
            ? currentPriceYear
            : availableYears[0];
        if (preferred != null && preferred !== position.price_year) {
            onChange({ price_year: preferred });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        availableYears,
        yearsLoading,
        position.price_year,
        rebind,
        showActivePickers,
        canEdit,
        currentPriceYear,
    ]);

    const distribution = platformDistributionStatus(
        position.impressions_input,
        position.oa_platform_allocations,
    );

    const listTkp = methodResult?.list_tkp ?? position.list_tkp_snapshot;
    const minTkp = methodResult?.minimum_tkp ?? position.minimum_tkp_snapshot;
    const surcharge =
        methodResult?.targeting_surcharge_total ??
        position.targeting_surcharge_total;
    const finalTkp = methodResult?.final_tkp ?? position.final_tkp;
    const belowMin =
        methodResult?.below_minimum_tkp ?? position.below_minimum_tkp;

    const activePlatformOptions = platforms.filter(
        (platform) =>
            !position.oa_platform_allocations.some(
                (row) => row.oa_platform_id === platform.id && !row.historical,
            ),
    );

    function updateAllocation(
        rowIndex: number,
        patch: Partial<OaPlatformAllocationDraft>,
    ) {
        const next = position.oa_platform_allocations.map((row, index) =>
            index === rowIndex ? { ...row, ...patch } : row,
        );
        onChange({ oa_platform_allocations: next });
    }

    function addPlatformRow() {
        const first = activePlatformOptions[0];
        onChange({
            oa_platform_allocations: [
                ...position.oa_platform_allocations,
                {
                    oa_platform_id: first?.id ?? null,
                    platform_code: first?.code ?? null,
                    platform_name: first?.name ?? null,
                    impressions_input: '',
                    historical: false,
                },
            ],
        });
    }

    function removePlatformRow(rowIndex: number) {
        onChange({
            oa_platform_allocations: position.oa_platform_allocations.filter(
                (_, index) => index !== rowIndex,
            ),
        });
    }

    function toggleTargeting(targeting: OaTargetingCatalogRow) {
        if (
            isActiveTargetingBlockedByHistorical(
                targeting.code,
                position.oa_targeting_selections,
            )
        ) {
            return;
        }

        const existingIndex = position.oa_targeting_selections.findIndex(
            (row) =>
                !row.historical &&
                row.oa_targeting_definition_id === targeting.id,
        );

        if (existingIndex >= 0) {
            onChange({
                oa_targeting_selections:
                    position.oa_targeting_selections.filter(
                        (_, index) => index !== existingIndex,
                    ),
            });
            return;
        }

        onChange({
            oa_targeting_selections: [
                ...position.oa_targeting_selections,
                {
                    oa_targeting_definition_id: targeting.id,
                    targeting_code: targeting.code,
                    targeting_name: targeting.name,
                    targeting_category: targeting.category,
                    tkp_surcharge_snapshot: targeting.tkp_surcharge,
                    targeting_revision_number: targeting.revision_number,
                    historical: false,
                },
            ],
        });
    }

    function removeTargeting(rowIndex: number) {
        onChange({
            oa_targeting_selections: position.oa_targeting_selections.filter(
                (_, index) => index !== rowIndex,
            ),
        });
    }

    const targetingsByCategory = useMemo(() => {
        const map = new Map<string, OaTargetingCatalogRow[]>();
        for (const targeting of targetings) {
            const key = targeting.category || 'Sonstige';
            const list = map.get(key) ?? [];
            list.push(targeting);
            map.set(key, list);
        }

        return Array.from(map.entries());
    }, [targetings]);

    return (
        <div className="space-y-6" data-test={`oa-tkp-editor-${positionIndex}`}>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <FormField
                    label="Impressions"
                    htmlFor={`oa-tkp-impressions-${positionIndex}`}
                    error={
                        fieldErrors[
                            `positions.${positionIndex}.impressions`
                        ]?.[0]
                    }
                >
                    <Input
                        id={`oa-tkp-impressions-${positionIndex}`}
                        data-test={`oa-tkp-impressions-${positionIndex}`}
                        inputMode="numeric"
                        value={position.impressions_input}
                        disabled={!canEdit}
                        onChange={(event) =>
                            onChange({
                                impressions_input: event.target.value.replace(
                                    /[^\d]/g,
                                    '',
                                ),
                            })
                        }
                    />
                </FormField>

                <FormField
                    label="Basis-TKP"
                    htmlFor={`oa-tkp-basis-${positionIndex}`}
                    error={
                        fieldErrors[`positions.${positionIndex}.basis_tkp`]?.[0]
                    }
                >
                    <Input
                        id={`oa-tkp-basis-${positionIndex}`}
                        data-test={`oa-tkp-basis-${positionIndex}`}
                        inputMode="decimal"
                        value={position.basis_tkp}
                        disabled={!canEdit}
                        onChange={(event) =>
                            onChange({ basis_tkp: event.target.value })
                        }
                    />
                </FormField>

                <FormField
                    label="Preislistenjahr"
                    htmlFor={`oa-tkp-price-year-${positionIndex}`}
                    error={
                        fieldErrors[
                            `positions.${positionIndex}.price_year`
                        ]?.[0] ??
                        fieldErrors[
                            `positions.${positionIndex}.expected_oa_tkp_price_list_id`
                        ]?.[0]
                    }
                >
                    <select
                        id={`oa-tkp-price-year-${positionIndex}`}
                        data-test={`oa-tkp-price-year-${positionIndex}`}
                        className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        value={position.price_year}
                        disabled={!canEdit || !showActivePickers}
                        onChange={(event) =>
                            onChange({
                                price_year: Number(event.target.value),
                            })
                        }
                    >
                        {priceYearOptions.map((year) => (
                            <option key={year} value={year}>
                                {year}
                            </option>
                        ))}
                    </select>
                </FormField>
            </div>

            {metadataLoading || yearsLoading ? (
                <p className="text-muted-foreground text-sm">
                    Preisliste wird geladen…
                </p>
            ) : null}

            {metadataError ? (
                <div data-test={`oa-tkp-metadata-error-${positionIndex}`}>
                    <StatusBanner tone="warning">
                        {metadataError.includes('geändert') ||
                        metadataError.toLowerCase().includes('conflict')
                            ? 'Preisliste hat sich geändert. Bitte Preisjahr bzw. Auswahl neu laden.'
                            : metadataError}
                    </StatusBanner>
                </div>
            ) : null}

            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <ReadonlyMetric
                    label="Listen-TKP"
                    value={formatTkpDisplay(listTkp)}
                />
                <ReadonlyMetric
                    label="Mindest-TKP"
                    value={formatTkpDisplay(minTkp)}
                />
                <ReadonlyMetric
                    label="Targeting-Aufschlag"
                    value={formatTkpDisplay(surcharge)}
                />
                <ReadonlyMetric
                    label="Final-TKP"
                    value={formatTkpDisplay(finalTkp)}
                    testId={`oa-tkp-final-${positionIndex}`}
                />
                <ReadonlyMetric
                    label="Media-Brutto"
                    value={mediaGross ? money(mediaGross) : '–'}
                />
            </dl>

            {belowMin ? (
                <div data-test={`oa-tkp-under-min-${positionIndex}`}>
                    <StatusBanner tone="warning">
                        Basis-TKP unterschreitet Mindest-TKP. Für diese Position
                        ist eine Sonderfreigabe erforderlich.
                    </StatusBanner>
                </div>
            ) : null}

            <fieldset className="space-y-3">
                <legend className="text-sm font-medium">
                    Platform-Verteilung
                </legend>
                <p
                    className="text-muted-foreground text-sm"
                    data-test={`oa-tkp-platform-summary-${positionIndex}`}
                >
                    {distribution.message}
                </p>
                {fieldErrors[
                    `positions.${positionIndex}.oa_platform_allocations`
                ]?.[0] ? (
                    <p className="text-destructive text-sm" role="alert">
                        {
                            fieldErrors[
                                `positions.${positionIndex}.oa_platform_allocations`
                            ][0]
                        }
                    </p>
                ) : null}

                <ul className="space-y-3">
                    {position.oa_platform_allocations.map((row, rowIndex) => (
                        <li
                            key={`${row.oa_platform_id ?? 'new'}-${rowIndex}`}
                            className="border-border/70 grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_8rem_auto]"
                            data-test={`oa-tkp-platform-${positionIndex}-${rowIndex}`}
                        >
                            <FormField
                                label={
                                    row.historical
                                        ? 'Platform (historisch)'
                                        : 'Platform'
                                }
                                htmlFor={`oa-tkp-platform-select-${positionIndex}-${rowIndex}`}
                            >
                                {row.historical ? (
                                    <p className="text-sm">
                                        {row.platform_name ??
                                            row.platform_code ??
                                            'Historische Platform'}
                                        <span className="text-muted-foreground ml-2 text-xs">
                                            (nicht mehr aktiv)
                                        </span>
                                    </p>
                                ) : (
                                    <select
                                        id={`oa-tkp-platform-select-${positionIndex}-${rowIndex}`}
                                        className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                        disabled={!canEdit}
                                        value={row.oa_platform_id ?? ''}
                                        onChange={(event) => {
                                            const id = Number(
                                                event.target.value,
                                            );
                                            const platform = platforms.find(
                                                (item) => item.id === id,
                                            );
                                            updateAllocation(rowIndex, {
                                                oa_platform_id:
                                                    platform?.id ?? null,
                                                platform_code:
                                                    platform?.code ?? null,
                                                platform_name:
                                                    platform?.name ?? null,
                                            });
                                        }}
                                    >
                                        <option value="">Bitte wählen</option>
                                        {platforms.map((platform) => (
                                            <option
                                                key={platform.id}
                                                value={platform.id}
                                            >
                                                {platform.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </FormField>
                            <FormField
                                label="Impressions"
                                htmlFor={`oa-tkp-platform-imp-${positionIndex}-${rowIndex}`}
                            >
                                <Input
                                    id={`oa-tkp-platform-imp-${positionIndex}-${rowIndex}`}
                                    inputMode="numeric"
                                    disabled={!canEdit}
                                    value={row.impressions_input}
                                    onChange={(event) =>
                                        updateAllocation(rowIndex, {
                                            impressions_input:
                                                event.target.value.replace(
                                                    /[^\d]/g,
                                                    '',
                                                ),
                                        })
                                    }
                                />
                            </FormField>
                            {canEdit ? (
                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            removePlatformRow(rowIndex)
                                        }
                                    >
                                        Entfernen
                                    </Button>
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>

                {canEdit && showActivePickers ? (
                    <Button
                        type="button"
                        variant="outline"
                        data-test={`oa-tkp-platform-add-${positionIndex}`}
                        onClick={addPlatformRow}
                        disabled={platforms.length === 0}
                    >
                        Platform hinzufügen
                    </Button>
                ) : null}
            </fieldset>

            <fieldset className="space-y-3">
                <legend className="text-sm font-medium">Targetings</legend>
                {fieldErrors[
                    `positions.${positionIndex}.oa_targeting_selections`
                ]?.[0] ? (
                    <p className="text-destructive text-sm" role="alert">
                        {
                            fieldErrors[
                                `positions.${positionIndex}.oa_targeting_selections`
                            ][0]
                        }
                    </p>
                ) : null}

                {position.oa_targeting_selections
                    .filter((row) => row.historical)
                    .map((row, rowIndex) => {
                        const absoluteIndex =
                            position.oa_targeting_selections.indexOf(row);

                        return (
                            <div
                                key={`hist-${row.targeting_code}-${rowIndex}`}
                                className="border-border/70 flex items-center justify-between gap-3 rounded-lg border border-dashed p-3"
                                data-test={`oa-tkp-targeting-historical-${positionIndex}-${row.targeting_code}`}
                            >
                                <div>
                                    <p className="text-sm font-medium">
                                        {row.targeting_name ??
                                            row.targeting_code}
                                        <span className="text-muted-foreground ml-2 text-xs">
                                            historisch
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {row.targeting_category}
                                        {row.tkp_surcharge_snapshot
                                            ? ` · +${formatTkpDisplay(row.tkp_surcharge_snapshot)} TKP`
                                            : ''}
                                        {row.targeting_revision_number != null
                                            ? ` · Rev. ${row.targeting_revision_number}`
                                            : ''}
                                    </p>
                                </div>
                                {canEdit ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            removeTargeting(absoluteIndex)
                                        }
                                    >
                                        Entfernen
                                    </Button>
                                ) : null}
                            </div>
                        );
                    })}

                {showActivePickers
                    ? targetingsByCategory.map(([category, rows]) => (
                          <div key={category} className="space-y-2">
                              <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                                  {category}
                              </p>
                              <ul className="space-y-2">
                                  {rows.map((targeting) => {
                                      const blocked =
                                          isActiveTargetingBlockedByHistorical(
                                              targeting.code,
                                              position.oa_targeting_selections,
                                          );
                                      const checked =
                                          position.oa_targeting_selections.some(
                                              (row) =>
                                                  !row.historical &&
                                                  row.oa_targeting_definition_id ===
                                                      targeting.id,
                                          );
                                      const id = `oa-tkp-targeting-${positionIndex}-${targeting.id}`;

                                      return (
                                          <li key={targeting.id}>
                                              <label
                                                  htmlFor={id}
                                                  className="border-border/70 flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                                                  data-test={id}
                                              >
                                                  <input
                                                      id={id}
                                                      type="checkbox"
                                                      className="mt-1"
                                                      checked={checked}
                                                      disabled={
                                                          !canEdit || blocked
                                                      }
                                                      onChange={() =>
                                                          toggleTargeting(
                                                              targeting,
                                                          )
                                                      }
                                                  />
                                                  <span>
                                                      <span className="text-sm font-medium">
                                                          {targeting.name}
                                                      </span>
                                                      <span className="text-muted-foreground block text-xs">
                                                          (+
                                                          {formatTkpDisplay(
                                                              targeting.tkp_surcharge,
                                                          )}{' '}
                                                          TKP)
                                                          {blocked
                                                              ? ' – zuerst historische Auswahl entfernen'
                                                              : ''}
                                                      </span>
                                                  </span>
                                              </label>
                                          </li>
                                      );
                                  })}
                              </ul>
                          </div>
                      ))
                    : null}
            </fieldset>
        </div>
    );
}

function ReadonlyMetric({
    label,
    value,
    testId,
}: {
    label: string;
    value: string;
    testId?: string;
}) {
    return (
        <div className="bg-muted/20 rounded-lg border p-3">
            <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </dt>
            <dd
                className="mt-1 text-sm font-semibold tabular-nums"
                data-test={testId}
            >
                {value}
            </dd>
        </div>
    );
}
