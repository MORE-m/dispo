<?php

namespace Tests\Unit\PriceList;

use App\Support\PriceList\PriceListCalendar;
use Carbon\Carbon;
use Tests\TestCase;

class PriceListCalendarTest extends TestCase
{
    public function test_current_year_uses_europe_berlin_and_controlled_clock(): void
    {
        $winter = Carbon::parse('2026-12-31 23:30:00', 'UTC');
        $this->assertSame(2027, PriceListCalendar::currentYear($winter));

        $summer = Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin');
        $this->assertSame(2026, PriceListCalendar::currentYear($summer));
    }
}
