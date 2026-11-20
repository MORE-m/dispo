<?php

namespace Tests\Unit\DispoOrder;

use App\Support\DispoOrder\OperationalCampaignPeriodContract;
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
    public function canonicalize_rejects_end_before_start(): void
    {
        $this->expectException(ValidationException::class);
        OperationalCampaignPeriodContract::canonicalizeForPersist('2026-02-10', '2026-02-01');
    }
}
