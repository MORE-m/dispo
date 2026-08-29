<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;

final readonly class PlanRowInput
{
    public function __construct(
        public int $hour,
        public DayGroup $dayGroup,
        public int $spotCount,
        public string $secondPrice,
    ) {}
}
