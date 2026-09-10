<?php

namespace App\Services\Advertising\Admin;

use App\Models\AdvertisingCategoryCalculationMethod;

/**
 * ADV-001c3b2: effektive Desired-State-Zeile für Preview/Apply.
 */
final readonly class EffectiveCategoryMethodAssignment
{
    public function __construct(
        public ?int $id,
        public int $calculationMethodId,
        public bool $isActive,
        public int $sort,
        public ?string $engineProfileKey,
        public ?int $lockVersion,
        public bool $willCreate,
        public bool $willMutate,
        public ?AdvertisingCategoryCalculationMethod $existing,
    ) {}

    /**
     * @return array{
     *     id: int|null,
     *     calculation_method_id: int,
     *     is_active: bool,
     *     sort: int,
     *     engine_profile_key: string|null,
     *     will_create: bool,
     *     will_mutate: bool
     * }
     */
    public function toPreviewArray(): array
    {
        return [
            'id' => $this->id,
            'calculation_method_id' => $this->calculationMethodId,
            'is_active' => $this->isActive,
            'sort' => $this->sort,
            'engine_profile_key' => $this->engineProfileKey,
            'will_create' => $this->willCreate,
            'will_mutate' => $this->willMutate,
        ];
    }
}
