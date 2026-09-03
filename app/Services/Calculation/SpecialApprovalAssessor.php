<?php

namespace App\Services\Calculation;

use App\Enums\SpecialApprovalReasonCode;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use Illuminate\Support\Collection;

/**
 * Kanonische Sonderfreigabe-Regeln für Kalkulation und Dispo-Snapshot (COM-002).
 */
final class SpecialApprovalAssessor
{
    /**
     * @param  list<string>  $percents
     */
    public function stackedPercent(array $percents): string
    {
        $factor = '1';

        foreach ($percents as $percent) {
            if ($percent === '' || Decimal::cmp($percent, '0') === 0) {
                continue;
            }

            $factor = Decimal::mul($factor, Decimal::oneMinusPercent($percent));
        }

        return Decimal::roundPrice(Decimal::mul(Decimal::sub('1', $factor), '100'));
    }

    public function exceedsLimit(string $actualPercent, ?string $personalLimitPercent): bool
    {
        if ($personalLimitPercent === null || $personalLimitPercent === '') {
            return false;
        }

        return Decimal::cmp($actualPercent, $personalLimitPercent, 4) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reasonsForRates(
        string $positionDiscountPercent,
        string $orderDiscountPercent,
        string $effectiveDiscountPercent,
        ?string $personalLimitPercent,
        ?int $positionId = null,
        ?string $positionKey = null,
        ?string $positionLabel = null,
    ): array {
        if ($personalLimitPercent === null || $personalLimitPercent === '') {
            return [];
        }

        $reasons = [];
        $positionExceeded = $this->exceedsLimit($positionDiscountPercent, $personalLimitPercent);
        $orderExceeded = $this->exceedsLimit($orderDiscountPercent, $personalLimitPercent);

        if ($positionExceeded) {
            $reasons[] = $this->reason(
                SpecialApprovalReasonCode::PositionDiscountExceedsLimit,
                $positionDiscountPercent,
                $personalLimitPercent,
                $positionId,
                $positionKey,
                $positionLabel,
            );
        }

        if ($orderExceeded) {
            $reasons[] = $this->reason(
                SpecialApprovalReasonCode::OrderDiscountExceedsLimit,
                $orderDiscountPercent,
                $personalLimitPercent,
                $positionId,
                $positionKey,
                $positionLabel,
            );
        }

        if (! $positionExceeded && ! $orderExceeded && $this->exceedsLimit($effectiveDiscountPercent, $personalLimitPercent)) {
            $reasons[] = $this->reason(
                SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit,
                $effectiveDiscountPercent,
                $personalLimitPercent,
                $positionId,
                $positionKey,
                $positionLabel,
            );
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, CalculationPosition>  $selectedPositions
     */
    public function assessFromCalculation(Calculation $calculation, Collection $selectedPositions): SpecialApprovalAssessment
    {
        $calculation->loadMissing(['positions.inventory']);

        $limit = $calculation->personal_discount_limit_percent;
        $limitString = $limit === null || $limit === '' ? null : (string) $limit;
        $storedReasons = is_array($calculation->special_approval_reasons)
            ? $calculation->special_approval_reasons
            : [];

        $selectedIds = array_values(
            $selectedPositions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $allIds = array_values(
            $calculation->positions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $reasons = [];
        foreach ($selectedPositions as $position) {
            $reasons = array_merge(
                $reasons,
                $this->reasonsFromStoredPosition(
                    $position,
                    (string) $calculation->order_discount_percent,
                    $limitString,
                ),
            );
        }

        /** @var list<array<string, mixed>> $storedReasonsList */
        $storedReasonsList = array_values($storedReasons);

        $reasons = array_merge(
            $reasons,
            $this->inheritedUnattributableReasons(
                $storedReasonsList,
                $selectedIds,
                $allIds,
                (bool) $calculation->requires_special_approval,
                $limitString,
            ),
        );

        return SpecialApprovalAssessment::fromReasons($reasons);
    }

    public function effectivePercentFromAmounts(
        string $mediaGross,
        string $positionDiscountAmount,
        string $orderDiscountAmount,
    ): string {
        if (Decimal::cmp($mediaGross, '0') === 0) {
            return Decimal::roundPrice('0');
        }

        $afterOrder = Decimal::sub($mediaGross, Decimal::add($positionDiscountAmount, $orderDiscountAmount));

        return Decimal::roundPrice(
            Decimal::mul(Decimal::sub('1', Decimal::div($afterOrder, $mediaGross)), '100'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reasonsFromStoredPosition(
        CalculationPosition $position,
        string $orderDiscountPercent,
        ?string $limit,
    ): array {
        $discountable = (bool) $position->is_discountable;
        $positionPercent = $discountable ? (string) $position->position_discount_percent : '0';
        $orderPercent = $discountable ? $orderDiscountPercent : '0';
        $effective = $this->effectivePercentFromAmounts(
            (string) $position->media_gross,
            (string) $position->position_discount_amount,
            (string) $position->order_discount_amount,
        );

        return $this->reasonsForRates(
            $positionPercent,
            $orderPercent,
            $effective,
            $limit,
            (int) $position->id,
            'id:'.$position->id,
            $position->inventory?->name,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  list<int>  $selectedIds
     * @param  list<int>  $allIds
     * @return list<array<string, mixed>>
     */
    private function inheritedUnattributableReasons(
        array $stored,
        array $selectedIds,
        array $allIds,
        bool $calculationRequiresSpecial,
        ?string $limit,
    ): array {
        $known = [
            SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value,
            SpecialApprovalReasonCode::OrderDiscountExceedsLimit->value,
            SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value,
        ];

        $inherited = [];

        foreach ($stored as $row) {
            $code = (string) ($row['code'] ?? '');
            $positionId = isset($row['position_id']) ? (int) $row['position_id'] : null;

            if (in_array($code, $known, true)) {
                continue;
            }

            if ($positionId !== null && in_array($positionId, $selectedIds, true)) {
                $inherited[] = $row;

                continue;
            }

            if ($positionId !== null && in_array($positionId, $allIds, true)) {
                continue;
            }

            $inherited[] = [
                'code' => SpecialApprovalReasonCode::Unattributable->value,
                'label' => SpecialApprovalReasonCode::Unattributable->label(),
                'source_code' => $code !== '' ? $code : null,
            ];
        }

        if ($calculationRequiresSpecial && $stored === [] && $limit === null) {
            $inherited[] = [
                'code' => SpecialApprovalReasonCode::Unattributable->value,
                'label' => SpecialApprovalReasonCode::Unattributable->label(),
            ];
        }

        return $inherited;
    }

    /**
     * @return array<string, mixed>
     */
    private function reason(
        SpecialApprovalReasonCode $code,
        string $actualPercent,
        string $limitPercent,
        ?int $positionId,
        ?string $positionKey,
        ?string $positionLabel,
    ): array {
        return [
            'code' => $code->value,
            'label' => $code->label(),
            'position_id' => $positionId,
            'position_key' => $positionKey,
            'position_label' => $positionLabel,
            'actual_percent' => Decimal::roundPrice($actualPercent),
            'limit_percent' => Decimal::roundPrice($limitPercent),
        ];
    }
}
