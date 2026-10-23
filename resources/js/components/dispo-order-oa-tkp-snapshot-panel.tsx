import { money } from '@/components/form-field';
import {
    formatImpressions,
    formatOaPriceListIdentity,
    formatTkpDisplay,
    type DispoShowOaPosition,
} from '@/lib/dispo-order-oa-show-display';

export function DispoOrderOaTkpSnapshotPanel({
    position,
    index,
}: {
    position: DispoShowOaPosition;
    index: number;
}) {
    const platforms = [
        ...(position.oa_platform_allocations_snapshot ?? []),
    ].sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0));
    const targetings = [
        ...(position.oa_targeting_selections_snapshot ?? []),
    ].sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0));
    const priceIdentity = formatOaPriceListIdentity(position);

    return (
        <div
            className="mt-3 space-y-3"
            data-test={`dispo-order-oa-tkp-snapshot-${index}`}
        >
            <dl
                className="text-muted-foreground grid gap-1 text-xs sm:grid-cols-2"
                data-test={`dispo-order-oa-tkp-prices-${index}`}
            >
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="inline">Impressions</dt>
                    <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                        {position.impressions != null
                            ? formatImpressions(position.impressions)
                            : '–'}
                    </dd>
                </div>
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="inline">Basis-TKP</dt>
                    <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                        {formatTkpDisplay(position.basis_tkp)}
                    </dd>
                </div>
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="inline">Listen-TKP</dt>
                    <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                        {formatTkpDisplay(position.list_tkp_snapshot)}
                    </dd>
                </div>
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="inline">Mindest-TKP</dt>
                    <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                        {formatTkpDisplay(position.minimum_tkp_snapshot)}
                    </dd>
                </div>
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="inline">Targeting-Aufschlag</dt>
                    <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                        {formatTkpDisplay(position.targeting_surcharge_total)}
                    </dd>
                </div>
                <div className="flex justify-between gap-2 sm:block">
                    <dt className="text-foreground inline font-medium">
                        Finaler TKP
                    </dt>
                    <dd className="text-foreground inline font-medium tabular-nums sm:mt-0.5 sm:block">
                        {formatTkpDisplay(position.final_tkp)}
                    </dd>
                </div>
                {position.media_gross ? (
                    <div className="flex justify-between gap-2 sm:block">
                        <dt className="inline">Media-Brutto</dt>
                        <dd className="inline tabular-nums sm:mt-0.5 sm:block">
                            {money(position.media_gross)}
                        </dd>
                    </div>
                ) : null}
            </dl>

            {position.below_minimum_tkp ? (
                <p
                    className="text-xs font-medium text-orange-700"
                    data-test={`dispo-order-oa-below-minimum-${index}`}
                >
                    Basis-TKP liegt unter dem Mindest-TKP (Sonderfreigabe siehe
                    Auftragskopf, sofern erforderlich).
                </p>
            ) : null}

            {platforms.length > 0 ? (
                <div data-test={`dispo-order-oa-platforms-${index}`}>
                    <p className="text-xs font-medium">Platforms</p>
                    <table className="text-muted-foreground mt-1 w-full text-xs">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="py-1 pr-2 font-medium">
                                    Platform
                                </th>
                                <th className="py-1 text-right font-medium">
                                    Impressions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {platforms.map((row, platformIndex) => (
                                <tr
                                    key={`${row.oa_platform_id ?? platformIndex}-${row.platform_code ?? ''}`}
                                    className="border-b border-dashed last:border-0"
                                >
                                    <td className="py-1 pr-2">
                                        {row.platform_name?.trim() ||
                                            row.platform_code ||
                                            '–'}
                                        {row.platform_code &&
                                        row.platform_name?.trim() ? (
                                            <span className="text-muted-foreground/80">
                                                {' '}
                                                ({row.platform_code})
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="py-1 text-right tabular-nums">
                                        {formatImpressions(
                                            Number(row.impressions) || 0,
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {targetings.length > 0 ? (
                <div data-test={`dispo-order-oa-targetings-${index}`}>
                    <p className="text-xs font-medium">Targetings</p>
                    <ul className="text-muted-foreground mt-1 space-y-1 text-xs">
                        {targetings.map((row, targetingIndex) => (
                            <li
                                key={`${row.oa_targeting_definition_id ?? targetingIndex}-${row.targeting_code ?? ''}`}
                            >
                                {row.targeting_name?.trim() ||
                                    row.targeting_code ||
                                    '–'}
                                {row.targeting_category
                                    ? ` · ${row.targeting_category}`
                                    : ''}
                                {row.tkp_surcharge_snapshot
                                    ? ` · +${formatTkpDisplay(row.tkp_surcharge_snapshot)}`
                                    : ''}
                                {row.targeting_revision_number != null
                                    ? ` · Rev. ${row.targeting_revision_number}`
                                    : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {priceIdentity ? (
                <p
                    className="text-muted-foreground text-xs"
                    data-test={`dispo-order-oa-price-identity-${index}`}
                >
                    OA-Preisliste (eingefroren): {priceIdentity}
                </p>
            ) : null}
        </div>
    );
}
