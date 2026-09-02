<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;

final readonly class BudgetBucket
{
    public function __construct(
        public DayGroup $dayGroup,
        public int $hour,
        public int $dayGroupOrder,
    ) {}
}
