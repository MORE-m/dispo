<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;

final readonly class TimeRangeInput
{
    /**
     * @param  list<PlanRowInput>  $hours
     */
    public function __construct(
        public int $startHour,
        public int $endHourExclusive,
        public DayGroup $dayGroup,
        public int $spotCount,
        public array $hours,
        public int $sort = 0,
    ) {}
}
