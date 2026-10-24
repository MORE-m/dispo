<?php

namespace Tests\Unit\DispoOrder;

use App\Services\DispoOrder\DispoOrderCompletionReadiness;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DSP-DCP-001 / PO-CALC-TKP-1D3d-2a: Completion periodState bleibt methodenagnostisch.
 * OA-TKP erhält keine Sonderregel; open bleibt zulässig, invalid blockierend.
 */
class DispoOrderCompletionReadinessPeriodStateTest extends TestCase
{
    #[DataProvider('periodStateCases')]
    public function test_period_state_for_entry_follows_dsp_dcp_001(
        array $entry,
        string $expected,
    ): void {
        $state = app(DispoOrderCompletionReadiness::class)->periodStateForEntry($entry);

        $this->assertSame($expected, $state);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function periodStateCases(): array
    {
        return [
            'oa_tkp_contributing_concrete' => [[
                'dispo_order_position_id' => 1,
                'spot_method' => 'tkp',
                'result' => DerivedCampaignPeriodContract::RESULT_CONTRIBUTING,
                'source' => DerivedCampaignPeriodContract::SOURCE_FLIGHT,
                'start' => '2026-04-01',
                'end' => '2026-04-30',
                'unresolved_reason' => null,
            ], 'concrete'],
            'oa_tkp_period_open' => [[
                'dispo_order_position_id' => 2,
                'spot_method' => 'tkp',
                'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
                'source' => null,
                'start' => null,
                'end' => null,
                'unresolved_reason' => DerivedCampaignPeriodContract::REASON_PERIOD_OPEN,
            ], 'open'],
            'oa_tkp_flight_missing_invalid' => [[
                'dispo_order_position_id' => 3,
                'spot_method' => 'tkp',
                'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
                'source' => null,
                'start' => null,
                'end' => null,
                'unresolved_reason' => DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING,
            ], 'invalid'],
            'oa_tkp_flight_invalid_reason' => [[
                'dispo_order_position_id' => 4,
                'spot_method' => 'tkp',
                'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
                'source' => null,
                'start' => null,
                'end' => null,
                'unresolved_reason' => DerivedCampaignPeriodContract::REASON_FLIGHT_INVALID,
            ], 'invalid'],
            'average_period_open_parity' => [[
                'dispo_order_position_id' => 5,
                'spot_method' => 'average',
                'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
                'source' => null,
                'start' => null,
                'end' => null,
                'unresolved_reason' => DerivedCampaignPeriodContract::REASON_PERIOD_OPEN,
            ], 'open'],
        ];
    }
}
