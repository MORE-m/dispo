<?php

namespace App\Services\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use Illuminate\Support\Carbon;

/**
 * @phpstan-type PositionProvenance array{
 *     dispo_order_position_id: int,
 *     sort: int,
 *     label: string,
 *     spot_method: string,
 *     result: string,
 *     source: string|null,
 *     start: string|null,
 *     end: string|null,
 *     unresolved_reason: string|null
 * }
 * @phpstan-type ProvenanceSnapshot array{
 *     contract_version: int,
 *     position_count: int,
 *     contributing_count: int,
 *     unresolved_count: int,
 *     positions: list<PositionProvenance>
 * }
 */
final readonly class DerivedCampaignPeriodResult
{
    /**
     * @param  ProvenanceSnapshot  $provenance
     */
    public function __construct(
        public ?string $start,
        public ?string $end,
        public DerivedCampaignPeriodStatus $status,
        public Carbon $derivedAt,
        public array $provenance,
    ) {}
}
