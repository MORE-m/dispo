<?php

namespace App\Services\Calculation;

use App\Enums\DiscountType;

final readonly class DiscountInput
{
    public function __construct(
        public DiscountType $type,
        public string $percent,
        public ?string $customLabel = null,
        public int $sort = 0,
    ) {}

    public function displayName(): string
    {
        if ($this->type === DiscountType::Other) {
            $label = trim((string) $this->customLabel);

            return $label !== '' ? $label : $this->type->label();
        }

        return $this->type->label();
    }
}
