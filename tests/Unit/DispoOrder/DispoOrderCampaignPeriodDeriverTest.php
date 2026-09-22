<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use App\Services\DispoOrder\DispoOrderCampaignPeriodDeriver;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use Tests\TestCase;

/**
 * DSP-DCP-001: Unit-Ableitung ohne DB (in-memory Positionen + setRelation).
 */
class DispoOrderCampaignPeriodDeriverTest extends TestCase
{
    public function test_calendar_min_max_ignores_zero_spots_and_unsorted_dates(): void
    {
        $position = $this->calendarPosition(1, 0, [
            ['date' => '2026-09-20', 'hour' => 14, 'spot_count' => 2],
            ['date' => '2026-09-15', 'hour' => 8, 'spot_count' => 0],
            ['date' => '2026-09-18', 'hour' => 10, 'spot_count' => 1],
            ['date' => '2026-09-16', 'hour' => 9, 'spot_count' => '3'],
        ]);

        $result = $this->deriver()->deriveFromPositions(collect([$position]));

        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $result->status);
        $this->assertSame('2026-09-16', $result->start);
        $this->assertSame('2026-09-20', $result->end);
        $this->assertSame(DerivedCampaignPeriodContract::SOURCE_PLANNER, $result->provenance['positions'][0]['source']);
        $this->assertSame(DerivedCampaignPeriodContract::RESULT_CONTRIBUTING, $result->provenance['positions'][0]['result']);
    }

    public function test_calendar_gaps_are_ok(): void
    {
        $position = $this->calendarPosition(1, 0, [
            ['date' => '2026-01-01', 'hour' => 8, 'spot_count' => 1],
            ['date' => '2026-01-31', 'hour' => 8, 'spot_count' => 1],
        ]);

        $result = $this->deriver()->deriveFromPositions(collect([$position]));

        $this->assertSame('2026-01-01', $result->start);
        $this->assertSame('2026-01-31', $result->end);
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $result->status);
    }

    public function test_calendar_no_usable_entries_is_unresolved(): void
    {
        $position = $this->calendarPosition(1, 0, [
            ['date' => '2026-09-15', 'hour' => 8, 'spot_count' => 0],
            ['date' => '2026-09-16', 'hour' => 9, 'spot_count' => 0],
        ]);

        $result = $this->deriver()->deriveFromPositions(collect([$position]));

        $this->assertSame(DerivedCampaignPeriodStatus::Open, $result->status);
        $this->assertNull($result->start);
        $this->assertNull($result->end);
        $this->assertSame(
            DerivedCampaignPeriodContract::REASON_NO_USABLE_PLANNER,
            $result->provenance['positions'][0]['unresolved_reason'],
        );
    }

    public function test_calendar_missing_or_corrupt_planner_fail_closed(): void
    {
        $missing = $this->calendarPosition(1, 0, null);
        $nonArray = $this->calendarPosition(2, 1, 'corrupt');
        $badEntry = $this->calendarPosition(3, 2, [
            ['date' => '2026-09-15', 'hour' => 8, 'spot_count' => 1],
            'not-an-array',
        ]);
        $badDate = $this->calendarPosition(4, 3, [
            ['date' => '15.09.2026', 'hour' => 8, 'spot_count' => 1],
        ]);

        foreach ([$missing, $nonArray, $badEntry, $badDate] as $position) {
            $result = $this->deriver()->deriveFromPositions(collect([$position]));
            $this->assertSame(DerivedCampaignPeriodStatus::Open, $result->status);
            $this->assertSame(
                DerivedCampaignPeriodContract::REASON_CORRUPT_SNAPSHOT,
                $result->provenance['positions'][0]['unresolved_reason'],
                'position '.$position->id,
            );
        }
    }

    public function test_average_closed_flight_contributes(): void
    {
        $position = $this->flightPosition(
            10,
            0,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: '2026-03-01',
            flightEnd: '2026-03-31',
        );

        $result = $this->deriver()->deriveFromPositions(collect([$position]));

        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $result->status);
        $this->assertSame('2026-03-01', $result->start);
        $this->assertSame('2026-03-31', $result->end);
        $this->assertSame(DerivedCampaignPeriodContract::SOURCE_FLIGHT, $result->provenance['positions'][0]['source']);
    }

    public function test_average_period_open_is_unresolved(): void
    {
        $position = $this->flightPosition(
            11,
            0,
            SpotCalculationMethod::Average,
            periodOpen: true,
            flightStart: '2026-03-01',
            flightEnd: '2026-03-31',
        );

        $result = $this->deriver()->deriveFromPositions(collect([$position]));

        $this->assertSame(DerivedCampaignPeriodStatus::Open, $result->status);
        $this->assertSame(
            DerivedCampaignPeriodContract::REASON_PERIOD_OPEN,
            $result->provenance['positions'][0]['unresolved_reason'],
        );
    }

    public function test_average_missing_incomplete_invalid_flight(): void
    {
        $missingOpen = $this->flightPosition(
            20,
            0,
            SpotCalculationMethod::Average,
            periodOpen: null,
            flightStart: '2026-03-01',
            flightEnd: '2026-03-31',
            includePeriodOpen: false,
        );
        $missingFlight = $this->flightPosition(
            21,
            1,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: null,
            flightEnd: null,
            includeFlight: false,
        );
        $bothNull = $this->flightPosition(
            22,
            2,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: null,
            flightEnd: null,
        );
        $incomplete = $this->flightPosition(
            23,
            3,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: '2026-03-01',
            flightEnd: null,
        );
        $invalid = $this->flightPosition(
            24,
            4,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: '2026-03-31',
            flightEnd: '2026-03-01',
        );

        $cases = [
            [$missingOpen, DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING],
            [$missingFlight, DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING],
            [$bothNull, DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING],
            [$incomplete, DerivedCampaignPeriodContract::REASON_FLIGHT_INCOMPLETE],
            [$invalid, DerivedCampaignPeriodContract::REASON_FLIGHT_INVALID],
        ];

        foreach ($cases as [$position, $reason]) {
            $result = $this->deriver()->deriveFromPositions(collect([$position]));
            $this->assertSame(DerivedCampaignPeriodStatus::Open, $result->status);
            $this->assertSame($reason, $result->provenance['positions'][0]['unresolved_reason'], 'id '.$position->id);
        }
    }

    public function test_fixed_price_follows_spot_method_calendar_vs_average(): void
    {
        $calendarLike = $this->calendarPosition(30, 0, [
            ['date' => '2026-05-10', 'hour' => 8, 'spot_count' => 2],
            ['date' => '2026-05-12', 'hour' => 9, 'spot_count' => 1],
        ]);
        $calendarLike->fixed_price_nn = '250.00';

        $averageLike = $this->flightPosition(
            31,
            0,
            SpotCalculationMethod::Average,
            periodOpen: false,
            flightStart: '2026-06-01',
            flightEnd: '2026-06-15',
        );
        $averageLike->fixed_price_nn = '250.00';

        $fixedPriceMethod = $this->flightPosition(
            32,
            0,
            SpotCalculationMethod::FixedPrice,
            periodOpen: false,
            flightStart: '2026-07-01',
            flightEnd: '2026-07-10',
        );

        $calendarResult = $this->deriver()->deriveFromPositions(collect([$calendarLike]));
        $this->assertSame('2026-05-10', $calendarResult->start);
        $this->assertSame('2026-05-12', $calendarResult->end);
        $this->assertSame(DerivedCampaignPeriodContract::SOURCE_PLANNER, $calendarResult->provenance['positions'][0]['source']);

        $averageResult = $this->deriver()->deriveFromPositions(collect([$averageLike]));
        $this->assertSame('2026-06-01', $averageResult->start);
        $this->assertSame('2026-06-15', $averageResult->end);
        $this->assertSame(DerivedCampaignPeriodContract::SOURCE_FLIGHT, $averageResult->provenance['positions'][0]['source']);

        $fixedResult = $this->deriver()->deriveFromPositions(collect([$fixedPriceMethod]));
        $this->assertSame('2026-07-01', $fixedResult->start);
        $this->assertSame('2026-07-10', $fixedResult->end);
        $this->assertSame(DerivedCampaignPeriodContract::SOURCE_FLIGHT, $fixedResult->provenance['positions'][0]['source']);
    }

    public function test_tandem_does_not_multiply_period(): void
    {
        $entries = [
            ['date' => '2026-09-14', 'hour' => 8, 'spot_count' => 3],
            ['date' => '2026-09-16', 'hour' => 10, 'spot_count' => 1],
        ];

        $classic = $this->calendarPosition(40, 0, $entries);
        $classic->component_profile = null;
        $classic->total_spot_count = 3;

        $tandem = $this->calendarPosition(41, 0, $entries);
        $tandem->component_profile = SpotComponentProfile::Tandem;
        $tandem->total_spot_count = 3;

        $classicResult = $this->deriver()->deriveFromPositions(collect([$classic]));
        $tandemResult = $this->deriver()->deriveFromPositions(collect([$tandem]));

        $this->assertSame($classicResult->start, $tandemResult->start);
        $this->assertSame($classicResult->end, $tandemResult->end);
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $tandemResult->status);
        $this->assertSame('2026-09-14', $tandemResult->start);
        $this->assertSame('2026-09-16', $tandemResult->end);
    }

    public function test_multi_position_min_max_and_status_matrix(): void
    {
        $early = $this->calendarPosition(50, 2, [
            ['date' => '2026-02-01', 'hour' => 8, 'spot_count' => 1],
            ['date' => '2026-02-10', 'hour' => 8, 'spot_count' => 1],
        ]);
        $late = $this->calendarPosition(51, 0, [
            ['date' => '2026-04-01', 'hour' => 8, 'spot_count' => 1],
            ['date' => '2026-04-20', 'hour' => 8, 'spot_count' => 1],
        ]);
        $open = $this->flightPosition(
            52,
            1,
            SpotCalculationMethod::Average,
            periodOpen: true,
            flightStart: null,
            flightEnd: null,
        );

        $complete = $this->deriver()->deriveFromPositions(collect([$early, $late]));
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $complete->status);
        $this->assertSame('2026-02-01', $complete->start);
        $this->assertSame('2026-04-20', $complete->end);

        $partial = $this->deriver()->deriveFromPositions(collect([$early, $open, $late]));
        $this->assertSame(DerivedCampaignPeriodStatus::Partial, $partial->status);
        $this->assertSame('2026-02-01', $partial->start);
        $this->assertSame('2026-04-20', $partial->end);
        $this->assertSame(2, $partial->provenance['contributing_count']);
        $this->assertSame(1, $partial->provenance['unresolved_count']);

        $allOpen = $this->deriver()->deriveFromPositions(collect([$open]));
        $this->assertSame(DerivedCampaignPeriodStatus::Open, $allOpen->status);
        $this->assertNull($allOpen->start);
        $this->assertNull($allOpen->end);

        $empty = $this->deriver()->deriveFromPositions(collect());
        $this->assertSame(DerivedCampaignPeriodStatus::Open, $empty->status);
        $this->assertSame(0, $empty->provenance['position_count']);
    }

    public function test_provenance_is_sorted_and_versioned(): void
    {
        $pHighSort = $this->calendarPosition(100, 5, [
            ['date' => '2026-08-01', 'hour' => 8, 'spot_count' => 1],
        ]);
        $pLowSortHighId = $this->calendarPosition(200, 1, [
            ['date' => '2026-08-05', 'hour' => 8, 'spot_count' => 1],
        ]);
        $pLowSortLowId = $this->calendarPosition(150, 1, [
            ['date' => '2026-08-03', 'hour' => 8, 'spot_count' => 1],
        ]);

        $result = $this->deriver()->deriveFromPositions(collect([
            $pHighSort,
            $pLowSortHighId,
            $pLowSortLowId,
        ]));

        $this->assertSame(DerivedCampaignPeriodContract::CONTRACT_VERSION, $result->provenance['contract_version']);
        $this->assertSame(3, $result->provenance['position_count']);
        $ids = array_column($result->provenance['positions'], 'dispo_order_position_id');
        $this->assertSame([150, 200, 100], $ids);
        $this->assertSame('2026-08-01', $result->start);
        $this->assertSame('2026-08-05', $result->end);
    }

    private function deriver(): DispoOrderCampaignPeriodDeriver
    {
        return app(DispoOrderCampaignPeriodDeriver::class);
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int|string}>|string|null  $entries
     */
    private function calendarPosition(int $id, int $sort, array|string|null $entries): DispoOrderPosition
    {
        $position = new DispoOrderPosition([
            'sort' => $sort,
            'inventory_name' => 'Radio Hamburg',
            'advertising_medium_name' => 'Spot Classic',
            'spot_method' => SpotCalculationMethod::Calendar,
        ]);
        $position->id = $id;
        $position->exists = true;
        $position->planner_entries_snapshot = $entries;
        $position->setRelation('fieldValues', collect());

        return $position;
    }

    private function flightPosition(
        int $id,
        int $sort,
        SpotCalculationMethod $method,
        ?bool $periodOpen,
        ?string $flightStart,
        ?string $flightEnd,
        bool $includePeriodOpen = true,
        bool $includeFlight = true,
    ): DispoOrderPosition {
        $values = collect();

        if ($includePeriodOpen) {
            $openDef = new SnapshotFieldDefinition;
            $openDef->key = 'period_open';
            $open = new DispoOrderPositionFieldValue;
            $open->value_boolean = $periodOpen;
            $open->setRelation('snapshotFieldDefinition', $openDef);
            $values->push($open);
        }

        if ($includeFlight) {
            $flightDef = new SnapshotFieldDefinition;
            $flightDef->key = 'position_flight_period';
            $flight = new DispoOrderPositionFieldValue;
            $flight->value_period_start = $flightStart;
            $flight->value_period_end = $flightEnd;
            $flight->setRelation('snapshotFieldDefinition', $flightDef);
            $values->push($flight);
        }

        $position = new DispoOrderPosition([
            'sort' => $sort,
            'inventory_name' => 'Radio Hamburg',
            'advertising_medium_name' => 'Spot Classic',
            'spot_method' => $method,
        ]);
        $position->id = $id;
        $position->exists = true;
        $position->setRelation('fieldValues', $values);

        return $position;
    }
}
