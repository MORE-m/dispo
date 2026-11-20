<?php

namespace Tests\Unit\DispoOrder;

use App\Models\Calculation;
use App\Support\DispoOrder\OperationalCampaignPeriodContract;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationalCampaignPeriodContractTest extends TestCase
{
    #[Test]
    public function canonicalize_accepts_null_pair_and_valid_range(): void
    {
        $this->assertSame(
            ['start' => null, 'end' => null],
            OperationalCampaignPeriodContract::canonicalizeForPersist(null, null),
        );

        $this->assertSame(
            ['start' => '2026-01-01', 'end' => '2026-01-01'],
            OperationalCampaignPeriodContract::canonicalizeForPersist('2026-01-01', '2026-01-01'),
        );
    }

    #[Test]
    public function canonicalize_rejects_partial_and_inverted(): void
    {
        $this->expectException(ValidationException::class);
        OperationalCampaignPeriodContract::canonicalizeForPersist('2026-01-01', null);
    }

    #[Test]
    public function legacy_prefill_requires_historical_non_adopt_complete_period(): void
    {
        $cutover = Carbon::parse(OperationalCampaignPeriodContract::LEGACY_CALC_CREATED_BEFORE);

        $historical = new Calculation;
        $historical->origin_standard_offer_version_id = null;
        $historical->created_at = $cutover->copy()->subDay();

        $this->assertSame(
            ['start' => '2026-01-01', 'end' => '2026-01-31'],
            OperationalCampaignPeriodContract::legacyPrefillFromCampaignPeriod(
                $historical,
                ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ),
        );

        $adopted = new Calculation;
        $adopted->origin_standard_offer_version_id = 12;
        $adopted->created_at = $cutover->copy()->subDay();
        $this->assertNull(
            OperationalCampaignPeriodContract::legacyPrefillFromCampaignPeriod(
                $adopted,
                ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ),
        );

        $newCalc = new Calculation;
        $newCalc->origin_standard_offer_version_id = null;
        $newCalc->created_at = $cutover->copy()->addMinute();
        $this->assertNull(
            OperationalCampaignPeriodContract::legacyPrefillFromCampaignPeriod(
                $newCalc,
                ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ),
        );

        $this->assertNull(
            OperationalCampaignPeriodContract::legacyPrefillFromCampaignPeriod(
                $historical,
                ['start' => '2026-02-01', 'end' => null],
            ),
        );
    }
}
