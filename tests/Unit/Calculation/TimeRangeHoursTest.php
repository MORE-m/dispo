<?php

namespace Tests\Unit\Calculation;

use App\Services\Calculation\TimeRangeHours;
use Tests\TestCase;

class TimeRangeHoursTest extends TestCase
{
    public function test_exclusive_end_uses_hours_eight_through_seventeen(): void
    {
        $this->assertSame(
            [8, 9, 10, 11, 12, 13, 14, 15, 16, 17],
            TimeRangeHours::expand(8, 18),
        );
        $this->assertNotContains(18, TimeRangeHours::expand(8, 18));
    }

    public function test_single_hour_range_matches_legacy_hour_eight(): void
    {
        $this->assertSame([8], TimeRangeHours::expand(8, 9));
    }

    public function test_adjacent_ranges_do_not_overlap(): void
    {
        $this->assertFalse(TimeRangeHours::overlaps(8, 12, 12, 18));
        $this->assertTrue(TimeRangeHours::overlaps(8, 13, 12, 18));
    }

    public function test_hint_uses_inclusive_end_display(): void
    {
        $this->assertSame(
            'Berechnet werden die Preisstunden 08:00 bis 17:59 Uhr.',
            TimeRangeHours::hint(8, 18),
        );
    }
}
