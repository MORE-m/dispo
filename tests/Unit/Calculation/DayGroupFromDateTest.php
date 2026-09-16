<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Services\Calculation\DayGroupFromDate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DayGroupFromDateTest extends TestCase
{
    #[Test]
    public function test_weekday_is_mo_fr(): void
    {
        $this->assertSame(DayGroup::MoFr, DayGroupFromDate::resolve('2026-09-14')); // Montag
        $this->assertSame(DayGroup::MoFr, DayGroupFromDate::resolve('2026-09-18')); // Freitag
    }

    #[Test]
    public function test_saturday_and_sunday(): void
    {
        $this->assertSame(DayGroup::Sa, DayGroupFromDate::resolve('2026-09-19'));
        $this->assertSame(DayGroup::So, DayGroupFromDate::resolve('2026-09-20'));
    }

    #[Test]
    public function test_uses_europe_berlin_not_utc_edge(): void
    {
        // 2026-03-29 00:30 UTC ist noch 29.03. in Berlin (Sommerzeitbeginn-Wochenende: Sonntag)
        $this->assertSame(DayGroup::So, DayGroupFromDate::resolve('2026-03-29'));
    }
}
