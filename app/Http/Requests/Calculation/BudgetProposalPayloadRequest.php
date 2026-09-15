<?php

namespace App\Http\Requests\Calculation;

use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Services\Calculation\BudgetPlanningPayloadNormalizer;
use App\Services\Calculation\DiscountValidator;
use App\Services\Calculation\TimeRangeValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Dedizierter Request für Budgetvorschläge ohne manuelle Kalkulationspositionen.
 */
class BudgetProposalPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planning_mode' => ['required', Rule::enum(PlanningMode::class)],
            'target_budget_nn' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'price_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'expected_price_list_ids' => ['sometimes', 'array'],
            'expected_price_list_ids.*' => ['integer', 'min:1'],
            'budget_elements' => ['sometimes', 'array', 'min:1'],
            'budget_elements.*.client_id' => ['nullable', 'string', 'max:120'],
            'budget_elements.*.inventory_id' => ['required_with:budget_elements', 'integer', 'min:1'],
            'budget_elements.*.spot_length_seconds' => ['required_with:budget_elements', 'integer', 'min:1', 'max:3600'],
            'budget_elements.*.distribution_ranges' => ['required_with:budget_elements', 'array', 'min:1'],
            'budget_elements.*.distribution_ranges.*.start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'budget_elements.*.distribution_ranges.*.end_hour_exclusive' => ['required', 'integer', 'min:1', 'max:24'],
            'budget_elements.*.distribution_ranges.*.day_group' => ['required', Rule::enum(DayGroup::class)],
            'budget_elements.*.position_discounts' => ['sometimes', 'array'],
            'budget_elements.*.position_discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'budget_elements.*.position_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'budget_elements.*.position_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'budget_wish_inventory_ids' => ['sometimes', 'array', 'min:1'],
            'budget_wish_inventory_ids.*' => ['integer', 'min:1'],
            'budget_spot_length_seconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'budget_distribution_ranges' => ['sometimes', 'array', 'min:1'],
            'budget_distribution_ranges.*.start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'budget_distribution_ranges.*.end_hour_exclusive' => ['required', 'integer', 'min:1', 'max:24'],
            'budget_distribution_ranges.*.day_group' => ['required', Rule::enum(DayGroup::class)],
            'budget_position_discounts_by_inventory' => ['sometimes', 'array'],
            'budget_position_discounts_by_inventory.*.inventory_id' => ['required', 'integer', 'min:1'],
            'budget_position_discounts_by_inventory.*.discounts' => ['sometimes', 'array'],
            'budget_position_discounts_by_inventory.*.discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'budget_position_discounts_by_inventory.*.discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'budget_position_discounts_by_inventory.*.discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'order_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'order_discounts' => ['sometimes', 'array'],
            'order_discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'order_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'order_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ae_enabled' => ['sometimes', 'boolean'],
            'calculation_id' => ['nullable', 'integer', 'min:1'],
            'positions' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('planning_mode') !== PlanningMode::Budget->value) {
                $validator->errors()->add('planning_mode', 'Budgetvorschläge sind nur im Planungsweg „Mit Budget planen“ möglich.');
            }

            $hasElements = is_array($this->input('budget_elements')) && $this->input('budget_elements') !== [];
            $hasLegacy = is_array($this->input('budget_wish_inventory_ids')) && $this->input('budget_wish_inventory_ids') !== [];

            if (! $hasElements && ! $hasLegacy) {
                $validator->errors()->add('budget_elements', 'Mindestens ein Budget-Werbeelement ist erforderlich.');
            }

            if (! $hasElements && $hasLegacy) {
                try {
                    (new TimeRangeValidator)->validated(
                        $this->input('budget_distribution_ranges', []),
                        'budget_distribution_ranges',
                        requireAtLeastOne: true,
                        requireSpotCount: false,
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }

            if ($hasElements) {
                try {
                    (new BudgetPlanningPayloadNormalizer)->normalizeElements($this->all());
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }

            try {
                (new DiscountValidator)->validated($this->input('order_discounts', []), 'order_discounts');
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }

            foreach ($this->input('budget_elements', []) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                try {
                    (new DiscountValidator)->validated(
                        $row['position_discounts'] ?? [],
                        "budget_elements.{$index}.position_discounts",
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }

            foreach ($this->input('budget_position_discounts_by_inventory', []) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                try {
                    (new DiscountValidator)->validated(
                        $row['discounts'] ?? [],
                        "budget_position_discounts_by_inventory.{$index}.discounts",
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = $this->validated();
        $payload['planning_mode'] = PlanningMode::Budget->value;
        $payload['budget_strategy'] = 'equal_spot_count';
        $payload['order_discount_percent'] = $payload['order_discount_percent'] ?? '0';
        $payload['positions'] = [];

        if ($this->exists('ae_enabled')) {
            $payload['ae_enabled'] = $this->boolean('ae_enabled');
        }

        return $payload;
    }
}
