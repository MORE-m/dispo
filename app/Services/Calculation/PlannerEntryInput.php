<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;

final readonly class PlannerEntryInput
{
    public function __construct(
        public string $date,
        public int $hour,
        public int $spotCount,
        public DayGroup $dayGroup,
        public string $secondPrice,
        public int $sort = 0,
    ) {}
}
