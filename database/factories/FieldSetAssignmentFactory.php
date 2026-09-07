<?php

namespace Database\Factories;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldSetAssignment>
 */
class FieldSetAssignmentFactory extends Factory
{
    protected $model = FieldSetAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $layer = FieldSetAssignmentTargetLayer::Global;

        return [
            // field_set_id muss vom Aufrufer gesetzt werden (kein FieldSet-Factory).
            'target_layer' => $layer,
            'advertising_category_id' => null,
            'advertising_medium_id' => null,
            'target_identity' => FieldSetAssignment::buildTargetIdentity($layer, null, null),
            'applies_to_process' => FieldAppliesTo::Both,
            'is_active' => false,
            'sort' => 0,
            'lock_version' => 1,
        ];
    }

    public function forFieldSet(FieldSet $fieldSet): static
    {
        return $this->state(fn (): array => [
            'field_set_id' => $fieldSet->id,
        ]);
    }

    public function global(): static
    {
        return $this->state(fn (): array => [
            'target_layer' => FieldSetAssignmentTargetLayer::Global,
            'advertising_category_id' => null,
            'advertising_medium_id' => null,
            'target_identity' => 'g',
        ]);
    }

    public function forCategory(int $categoryId): static
    {
        return $this->state(fn (): array => [
            'target_layer' => FieldSetAssignmentTargetLayer::AdvertisingCategory,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => null,
            'target_identity' => FieldSetAssignment::buildTargetIdentity(
                FieldSetAssignmentTargetLayer::AdvertisingCategory,
                $categoryId,
                null,
            ),
        ]);
    }

    public function forMedium(int $mediumId): static
    {
        return $this->state(fn (): array => [
            'target_layer' => FieldSetAssignmentTargetLayer::AdvertisingMedium,
            'advertising_category_id' => null,
            'advertising_medium_id' => $mediumId,
            'target_identity' => FieldSetAssignment::buildTargetIdentity(
                FieldSetAssignmentTargetLayer::AdvertisingMedium,
                null,
                $mediumId,
            ),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
