<?php

namespace App\Services\Calculation;

use App\Enums\SpotComponentRole;

final readonly class ComponentInput
{
    public function __construct(
        public SpotComponentRole $role,
        public string $label,
        public int $lengthSeconds,
        public int $sort = 0,
        public ?int $lengthIndex = null,
    ) {}
}
